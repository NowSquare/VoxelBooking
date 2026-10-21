<?php

declare(strict_types=1);

namespace App\Agent\OAuth;

use DateInterval;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PDO;
use Psr\Http\Message\ResponseInterface;

final class AuthorizationService
{
    use \League\OAuth2\Server\CryptTrait;

    private AuthorizationServer $server;

    public function __construct(
        private readonly PDO $db,
        private readonly ClientRepository $clients,
        private readonly TokenRepository $tokens,
        private readonly array $scopes,
        private readonly string $issuer,
        private readonly array $audiences,
        string $privateKey,
        string $encryptionKey,
    ) {
        $this->setEncryptionKey($encryptionKey);
        $this->server = new AuthorizationServer(
            $clients,
            $tokens,
            new ScopeRepository($scopes, $tokens),
            $privateKey,
            $encryptionKey,
            new TokenResponse()
        );
        $code = new AuthCodeGrant($tokens, $tokens, new DateInterval('PT5M'));
        $code->setRefreshTokenTTL(new DateInterval('P30D'));
        $refresh = new RefreshTokenGrant($tokens);
        $refresh->setRefreshTokenTTL(new DateInterval('P30D'));
        $this->server->enableGrantType($code, new DateInterval('PT1H'));
        $this->server->enableGrantType($refresh, new DateInterval('PT1H'));
    }

    public function validateAuthorization(array $query): AuthorizationRequestInterface
    {
        $this->scalarParameters($query);
        $this->validateResource($query['resource'] ?? null);
        if (($query['code_challenge_method'] ?? null) !== 'S256'
            || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $query['code_challenge'] ?? '')) {
            throw OAuthServerException::invalidRequest('code_challenge');
        }
        if (!isset($query['scope']) || trim($query['scope']) === '') {
            throw OAuthServerException::invalidScope('');
        }
        $client = $this->clients->getClientEntity($query['client_id'] ?? '');
        if ($client === null || !in_array($query['redirect_uri'] ?? '', (array) $client->getRedirectUri(), true)) {
            throw OAuthServerException::invalidRequest('client_id');
        }
        $request = (new ServerRequest('GET', $this->issuer . '/oauth/authorize'))->withQueryParams($query);
        return $this->server->validateAuthorizationRequest($request);
    }

    public function authorize(array $query, string $connectionId, bool $approved): ResponseInterface
    {
        $authorization = $this->validateAuthorization($query);
        $authorization->setUser(new User($connectionId));
        $authorization->setAuthorizationApproved($approved);
        $this->tokens->forResource($query['resource']);
        try {
            $response = $this->server->completeAuthorizationRequest($authorization, new Response());
        } catch (OAuthServerException $error) {
            if ($approved || $error->getErrorType() !== 'access_denied') {
                throw $error;
            }
            $response = $error->generateHttpResponse(new Response());
        }
        $location = $response->getHeaderLine('Location');
        // RFC 9207: name the issuer in the redirect so clients can detect mix-up attacks.
        if ($location !== '') {
            $location .= (str_contains($location, '?') ? '&' : '?') . 'iss=' . rawurlencode($this->issuer);
            $response = $response->withHeader('Location', $location);
        }
        return $response->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function token(array $parameters, ?string $authorization = null): ResponseInterface
    {
        $this->scalarParameters($parameters);
        $this->validateResource($parameters['resource'] ?? null);
        $this->clientCredentials($parameters, $authorization);
        $this->tokens->forResource($parameters['resource']);
        $request = (new ServerRequest('POST', $this->issuer . '/oauth/token'))->withParsedBody($parameters);
        if ($authorization !== null) {
            $request = $request->withHeader('Authorization', $authorization);
        }
        if ($this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            // League reads client metadata before locking the code/refresh row.
            // Current principal/grant checks after that lock must observe any
            // revocation committed while the exchange was waiting.
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        $this->db->beginTransaction();
        try {
            $response = $this->server->respondToAccessTokenRequest($request, new Response());
            $this->db->commit();
            return $response;
        } catch (\Throwable $error) {
            $this->db->rollBack();
            $this->tokens->revokeReplayedFamily();
            throw $error;
        }
    }

    public function metadata(): array
    {
        return [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->issuer . '/oauth/authorize',
            'token_endpoint' => $this->issuer . '/oauth/token',
            'revocation_endpoint' => $this->issuer . '/oauth/revoke',
            'registration_endpoint' => $this->issuer . '/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'scopes_supported' => array_values(array_unique($this->scopes)),
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    public function revoke(array $parameters, ?string $authorization = null): ResponseInterface
    {
        $this->scalarParameters($parameters);
        [$clientId, $secret] = $this->clientCredentials($parameters, $authorization);
        if (!$this->clients->validateClient($clientId, $secret, 'refresh_token')) {
            throw OAuthServerException::invalidRequest('client_id');
        }
        $raw = $parameters['token'] ?? '';
        if ($raw === '') {
            throw OAuthServerException::invalidRequest('token');
        }
        if (str_starts_with($raw, 'vbc_')) {
            $statement = $this->db->prepare(
                'UPDATE agent_credentials SET revoked_at = COALESCE(revoked_at, ?) WHERE token_hash = ? AND connection_id IN (SELECT id FROM agent_connections WHERE client_id = ?)'
            );
            $statement->execute([gmdate('Y-m-d H:i:s'), hash('sha256', $raw), $clientId]);
        } else {
            // Anything else is treated as an encrypted refresh token; undecryptable input is ignored, per RFC 7009.
            try {
                $payload = json_decode($this->decrypt($raw), true, flags: JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $payload = [];
            }
            if (($payload['client_id'] ?? null) === $clientId && is_string($payload['refresh_token_id'] ?? null)) {
                $statement = $this->db->prepare(
                    'UPDATE agent_connections SET revoked_at = COALESCE(revoked_at, ?) WHERE client_id = ? AND id IN (SELECT connection_id FROM oauth_refresh_tokens WHERE id_hash = ?)'
                );
                $statement->execute([gmdate('Y-m-d H:i:s'), $clientId, hash('sha256', $payload['refresh_token_id'])]);
            }
        }
        return (new Response(200))->withHeader('Cache-Control', 'no-store');
    }

    public function resourceMetadata(string $audience): array
    {
        $this->validateResource($audience);
        return [
            'resource' => $audience,
            'authorization_servers' => [$this->issuer],
            'scopes_supported' => array_values(array_unique($this->scopes)),
            'bearer_methods_supported' => ['header'],
        ];
    }

    private function validateResource(mixed $resource): void
    {
        if (!is_string($resource) || !in_array($resource, $this->audiences, true)) {
            throw OAuthServerException::invalidRequest('resource');
        }
    }

    /** Reject ambiguous credentials and enforce each client's registered authentication method. */
    private function clientCredentials(array $parameters, ?string $authorization): array
    {
        $clientId = $parameters['client_id'] ?? '';
        $secret = $parameters['client_secret'] ?? null;
        $method = $secret === null ? 'none' : 'client_secret_post';
        if ($authorization !== null && $authorization !== '') {
            if (stripos($authorization, 'Basic ') !== 0
                || array_key_exists('client_id', $parameters)
                || array_key_exists('client_secret', $parameters)) {
                throw OAuthServerException::invalidRequest('authorization');
            }
            $decoded = base64_decode(substr($authorization, 6), true);
            if ($decoded === false || !str_contains($decoded, ':')) {
                throw OAuthServerException::invalidRequest('authorization');
            }
            [$clientId, $secret] = array_map('urldecode', explode(':', $decoded, 2));
            $method = 'client_secret_basic';
        }
        $client = $this->clients->record($clientId);
        if ($client === null || $client['auth_method'] !== $method) {
            throw OAuthServerException::invalidRequest('client_id');
        }
        return [$clientId, $secret];
    }

    private function scalarParameters(array $parameters): void
    {
        foreach ($parameters as $value) {
            if (!is_string($value) || strlen($value) > 8192) {
                throw OAuthServerException::invalidRequest('parameters');
            }
        }
    }
}
