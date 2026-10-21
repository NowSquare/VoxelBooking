<?php

declare(strict_types=1);

namespace App\Agent\OAuth;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use PDO;

final class TokenRepository implements AccessTokenRepositoryInterface, AuthCodeRepositoryInterface, RefreshTokenRepositoryInterface
{
    private ?string $resource = null;
    private ?string $replayedConnection = null;

    public function __construct(
        private readonly PDO $db,
        private readonly Closure $issueCredential,
        private readonly Closure $assertCurrentConnection,
        private readonly Closure $revokeCredential,
    ) {}

    public function forResource(string $resource): void
    {
        $this->resource = $resource;
        $this->replayedConnection = null;
    }

    public function grant(string $connectionId, string $clientId): array
    {
        $grant = $this->one(
            'SELECT c.*, g.audience FROM agent_connections c JOIN oauth_grants g ON g.connection_id = c.id WHERE c.id = ?' . $this->lock(),
            [$connectionId]
        );
        // Every token mint re-checks the grant: same client, still live, and bound to this resource.
        if ($grant === null || $grant['client_id'] !== $clientId || $grant['revoked_at'] !== null
            || strtotime($grant['expires_at'] . ' UTC') <= time()
            || $this->resource === null || !hash_equals($grant['audience'], $this->resource)) {
            throw OAuthServerException::invalidGrant();
        }
        ($this->assertCurrentConnection)($connectionId);
        $grant['scopes_array'] = json_decode($grant['scopes'], true, flags: JSON_THROW_ON_ERROR);
        return $grant;
    }

    public function getNewToken(
        ClientEntityInterface $clientEntity,
        array $scopes,
        ?string $userIdentifier = null
    ): AccessTokenEntityInterface {
        $token = new AccessToken();
        $token->setClient($clientEntity);
        if ($userIdentifier !== null) {
            $token->setUserIdentifier($userIdentifier);
        }
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }
        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        if (!$accessTokenEntity instanceof AccessToken || $accessTokenEntity->getUserIdentifier() === null) {
            throw OAuthServerException::invalidGrant();
        }
        $grant = $this->grant($accessTokenEntity->getUserIdentifier(), $accessTokenEntity->getClient()->getIdentifier());
        // A token never outlives the connection it was issued from.
        $expiry = min($accessTokenEntity->getExpiryDateTime()->getTimestamp(), strtotime($grant['expires_at'] . ' UTC'));
        $accessTokenEntity->setExpiryDateTime(new DateTimeImmutable('@' . $expiry));
        $scopes = array_map(static fn ($scope) => $scope->getIdentifier(), $accessTokenEntity->getScopes());
        if (array_diff($scopes, $grant['scopes_array']) !== []) {
            throw OAuthServerException::invalidScope('');
        }
        $credential = ($this->issueCredential)($grant['id'], $grant['audience'], gmdate('Y-m-d H:i:s', $expiry), $scopes);
        $this->execute('INSERT INTO oauth_access_tokens (id_hash, connection_id, credential_id, expires_at) VALUES (?, ?, ?, ?)', [
            hash('sha256', $accessTokenEntity->getIdentifier()),
            $grant['id'],
            $credential['id'],
            gmdate('Y-m-d H:i:s', $expiry),
        ]);
        $accessTokenEntity->audience = $grant['audience'];
        $accessTokenEntity->setRawToken($credential['raw_token']);
    }

    public function revokeAccessToken(string $tokenId): void
    {
        $record = $this->one('SELECT credential_id FROM oauth_access_tokens WHERE id_hash = ?', [hash('sha256', $tokenId)]);
        if ($record !== null) {
            ($this->revokeCredential)($record['credential_id']);
        }
        $this->revoke('oauth_access_tokens', $tokenId);
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        return $this->revoked('oauth_access_tokens', $tokenId);
    }

    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCode();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $grant = $this->grant($authCodeEntity->getUserIdentifier() ?? '', $authCodeEntity->getClient()->getIdentifier());
        $this->execute('INSERT INTO oauth_auth_codes (id_hash, connection_id, expires_at) VALUES (?, ?, ?)', [
            hash('sha256', $authCodeEntity->getIdentifier()),
            $grant['id'],
            $this->date($authCodeEntity->getExpiryDateTime()),
        ]);
    }

    public function revokeAuthCode(string $codeId): void
    {
        $this->revoke('oauth_auth_codes', $codeId);
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        $record = $this->one('SELECT * FROM oauth_auth_codes WHERE id_hash = ?' . $this->lock(), [hash('sha256', $codeId)]);
        // A code is revoked only by its one successful redemption, so seeing it again means it leaked.
        // RFC 6749 4.1.2 and RFC 9700 2.1.1: revoke the tokens that redemption issued, not just this attempt.
        if ($record !== null && $record['revoked_at'] !== null) {
            $this->replayedConnection = $record['connection_id'];
        }
        return $record === null || $record['revoked_at'] !== null || strtotime($record['expires_at'] . ' UTC') <= time();
    }

    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return new RefreshToken();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $access = $refreshTokenEntity->getAccessToken();
        $grant = $this->grant($access->getUserIdentifier() ?? '', $access->getClient()->getIdentifier());
        $expiry = min($refreshTokenEntity->getExpiryDateTime()->getTimestamp(), strtotime($grant['expires_at'] . ' UTC'));
        $refreshTokenEntity->setExpiryDateTime(new DateTimeImmutable('@' . $expiry));
        $this->execute('INSERT INTO oauth_refresh_tokens (id_hash, connection_id, access_id_hash, expires_at) VALUES (?, ?, ?, ?)', [
            hash('sha256', $refreshTokenEntity->getIdentifier()),
            $grant['id'],
            hash('sha256', $access->getIdentifier()),
            gmdate('Y-m-d H:i:s', $expiry),
        ]);
    }

    public function revokeRefreshToken(string $tokenId): void
    {
        $this->revoke('oauth_refresh_tokens', $tokenId);
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $record = $this->one('SELECT * FROM oauth_refresh_tokens WHERE id_hash = ?' . $this->lock(), [hash('sha256', $tokenId)]);
        // A revoked refresh token presented again signals theft; remember the family so it can be revoked.
        if ($record !== null && $record['revoked_at'] !== null) {
            $this->replayedConnection = $record['connection_id'];
        }
        return $record === null || $record['revoked_at'] !== null || strtotime($record['expires_at'] . ' UTC') <= time();
    }

    /** Called after rollback so code or refresh reuse detection survives the failed exchange. */
    public function revokeReplayedFamily(): void
    {
        if ($this->replayedConnection !== null) {
            $this->execute(
                'UPDATE agent_connections SET revoked_at = COALESCE(revoked_at, ?) WHERE id = ?',
                [gmdate('Y-m-d H:i:s'), $this->replayedConnection]
            );
        }
    }

    private function revoked(string $table, string $id): bool
    {
        $record = $this->one('SELECT * FROM ' . $table . ' WHERE id_hash = ?' . $this->lock(), [hash('sha256', $id)]);
        return $record === null || $record['revoked_at'] !== null || strtotime($record['expires_at'] . ' UTC') <= time();
    }

    private function revoke(string $table, string $id): void
    {
        $this->execute(
            'UPDATE ' . $table . ' SET revoked_at = COALESCE(revoked_at, ?) WHERE id_hash = ?',
            [gmdate('Y-m-d H:i:s'), hash('sha256', $id)]
        );
    }

    private function lock(): string
    {
        return $this->db->inTransaction() && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function one(string $sql, array $parameters): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function execute(string $sql, array $parameters): void
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
    }

    private function date(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
