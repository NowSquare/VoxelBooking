<?php

declare(strict_types=1);

namespace Tests\Unit\Agent\OAuth;

use App\Agent\OAuth\AuthorizationService;
use App\Agent\OAuth\ClientRepository;
use App\Agent\OAuth\TokenRepository;
use League\OAuth2\Server\Exception\OAuthServerException;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthorizationServerTest extends TestCase
{
    private PDO $db;
    private AuthorizationService $service;
    private string $verifier;
    private array $query;
    private array $issued = [];

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        foreach ([
            'CREATE TABLE oauth_clients (id TEXT PRIMARY KEY, name TEXT, redirect_uris TEXT, secret_hash TEXT, auth_method TEXT, registration_type TEXT, metadata_expires_at TEXT, revoked_at TEXT, created_at TEXT)',
            'CREATE TABLE agent_connections (id TEXT PRIMARY KEY, client_id TEXT, scopes TEXT, expires_at TEXT, revoked_at TEXT)',
            'CREATE TABLE oauth_grants (connection_id TEXT PRIMARY KEY, audience TEXT)',
            'CREATE TABLE oauth_auth_codes (id_hash TEXT PRIMARY KEY, connection_id TEXT, expires_at TEXT, revoked_at TEXT)',
            'CREATE TABLE oauth_access_tokens (id_hash TEXT PRIMARY KEY, connection_id TEXT, credential_id TEXT, expires_at TEXT, revoked_at TEXT)',
            'CREATE TABLE oauth_refresh_tokens (id_hash TEXT PRIMARY KEY, connection_id TEXT, access_id_hash TEXT, expires_at TEXT, revoked_at TEXT)',
        ] as $sql) {
            $this->db->exec($sql);
        }
        $this->db->exec("INSERT INTO oauth_clients VALUES ('test-client', 'Test client', '[\"https://client.example/callback\"]', NULL, 'none', 'preregistered', NULL, NULL, '2026-01-01')");
        $expires = gmdate('Y-m-d H:i:s', time() + 86400);
        $statement = $this->db->prepare('INSERT INTO agent_connections VALUES (?, ?, ?, ?, NULL)');
        $statement->execute(['connection-1', 'test-client', '["bookings:read","bookings:write"]', $expires]);
        $this->db->exec("INSERT INTO oauth_grants VALUES ('connection-1', 'https://booking.example/mcp')");
        $repository = new TokenRepository(
            $this->db,
            function (string $connectionId, string $audience, string $expiry, array $scopes): array {
                $raw = 'vbc_' . bin2hex(random_bytes(32));
                $this->issued[$raw] = ['connection_id' => $connectionId, 'audience' => $audience, 'scopes' => $scopes];
                return ['id' => 'credential-' . count($this->issued), 'raw_token' => $raw];
            },
            static function (string $connectionId): void {},
            static function (string $credentialId): void {},
        );
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->service = new AuthorizationService(
            $this->db, new ClientRepository($this->db), $repository,
            ['bookings:read', 'bookings:write'], 'https://booking.example',
            ['https://booking.example/mcp'], $pem, bin2hex(random_bytes(32)),
        );
        $this->verifier = str_repeat('a', 64);
        $this->query = [
            'response_type' => 'code',
            'client_id' => 'test-client',
            'redirect_uri' => 'https://client.example/callback',
            'scope' => 'bookings:read bookings:write',
            'state' => 'client-state',
            'resource' => 'https://booking.example/mcp',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];
    }

    private function code(): string
    {
        $response = $this->service->authorize($this->query, 'connection-1', true);
        parse_str(parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        self::assertSame('client-state', $query['state']);
        self::assertSame('https://booking.example', $query['iss']);
        return $query['code'];
    }

    private function redeem(string $code, array $overrides = []): array
    {
        $response = $this->service->token($overrides + [
            'grant_type' => 'authorization_code', 'code' => $code,
            'client_id' => 'test-client', 'redirect_uri' => 'https://client.example/callback',
            'code_verifier' => $this->verifier, 'resource' => 'https://booking.example/mcp',
        ]);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testRealLibraryIssuesOpaqueResourceBoundTokensAndEncryptedRefresh(): void
    {
        $tokens = $this->redeem($this->code());
        self::assertStringStartsWith('vbc_', $tokens['access_token']);
        self::assertArrayHasKey('refresh_token', $tokens);
        self::assertSame('https://booking.example/mcp', $this->issued[$tokens['access_token']]['audience']);
        self::assertSame('bookings:read bookings:write', $tokens['scope']);
        self::assertSame(1, (int) $this->db->query('SELECT count(*) FROM oauth_auth_codes WHERE revoked_at IS NOT NULL')->fetchColumn());
        self::assertStringNotContainsString($tokens['access_token'], json_encode($this->db->query('SELECT * FROM oauth_access_tokens')->fetchAll()));
    }

    public function testCodeCanOnlyBeRedeemedOnce(): void
    {
        $code = $this->code();
        $this->redeem($code);
        $this->expectException(OAuthServerException::class);
        $this->redeem($code);
    }

    public function testWrongVerifierDoesNotConsumeAuthorizationCode(): void
    {
        $code = $this->code();
        try {
            $this->redeem($code, ['code_verifier' => str_repeat('b', 64)]);
            self::fail('Wrong verifier accepted');
        } catch (OAuthServerException) {
            self::assertSame(0, count($this->issued));
        }
        self::assertArrayHasKey('access_token', $this->redeem($code));
    }

    public function testResourceAndRedirectMismatchCannotIssueTokens(): void
    {
        $code = $this->code();
        foreach ([
            ['resource' => 'https://evil.example/mcp'],
            ['redirect_uri' => 'https://client.example/callback/other'],
            ['resource' => ''],
        ] as $overrides) {
            try {
                $this->redeem($code, $overrides);
                self::fail('Mismatched request accepted');
            } catch (OAuthServerException) {
                self::assertSame([], $this->issued);
            }
        }
    }

    public function testPkceS256AndExactRegisteredRedirectAreRequired(): void
    {
        foreach ([
            ['code_challenge_method' => 'plain'],
            ['code_challenge' => ''],
            ['redirect_uri' => 'https://client.example/callback?extra=1'],
            ['scope' => 'installation:secrets'],
        ] as $overrides) {
            try {
                $this->service->validateAuthorization($overrides + $this->query);
                self::fail('Unsafe authorization accepted');
            } catch (OAuthServerException) {
                self::assertSame([], $this->issued);
            }
        }
    }

    public function testRefreshRotationNarrowsScopesAndReuseRevokesConnection(): void
    {
        $first = $this->redeem($this->code());
        $request = [
            'grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'],
            'client_id' => 'test-client', 'resource' => 'https://booking.example/mcp', 'scope' => 'bookings:read',
        ];
        $response = $this->service->token($request);
        $second = json_decode((string) $response->getBody(), true);
        self::assertNotSame($first['access_token'], $second['access_token']);
        self::assertNotSame($first['refresh_token'], $second['refresh_token']);
        self::assertSame(['bookings:read'], $this->issued[$second['access_token']]['scopes']);
        try {
            $this->service->token($request);
            self::fail('Refresh replay accepted');
        } catch (OAuthServerException) {
            self::assertNotNull($this->db->query("SELECT revoked_at FROM agent_connections WHERE id = 'connection-1'")->fetchColumn());
        }
        $this->expectException(OAuthServerException::class);
        $this->service->token(['refresh_token' => $second['refresh_token']] + $request);
    }

    public function testRefreshCannotIncreaseScopesAfterDownscoping(): void
    {
        $this->query['scope'] = 'bookings:read';
        $first = $this->redeem($this->code());
        $this->expectException(OAuthServerException::class);
        $this->service->token([
            'grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'],
            'client_id' => 'test-client', 'resource' => 'https://booking.example/mcp', 'scope' => 'bookings:read bookings:write',
        ]);
    }

    public function testRevocationPreventsCodeExchange(): void
    {
        $code = $this->code();
        $this->db->exec("UPDATE agent_connections SET revoked_at = '2026-01-01' WHERE id = 'connection-1'");
        $this->expectException(OAuthServerException::class);
        $this->redeem($code);
    }

    public function testRefreshRevocationInvalidatesTheGrantAndReturnsNoTokenDetails(): void
    {
        $tokens = $this->redeem($this->code());
        $response = $this->service->revoke(['token' => $tokens['refresh_token'], 'client_id' => 'test-client']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertNotNull($this->db->query("SELECT revoked_at FROM agent_connections WHERE id='connection-1'")->fetchColumn());
    }

    public function testAuthorizationMetadataDeclaresOnlyImplementedFlows(): void
    {
        $metadata = $this->service->metadata();
        self::assertSame(['authorization_code', 'refresh_token'], $metadata['grant_types_supported']);
        self::assertSame(['S256'], $metadata['code_challenge_methods_supported']);
        self::assertTrue($metadata['authorization_response_iss_parameter_supported']);
        self::assertSame('https://booking.example/mcp', $this->service->resourceMetadata('https://booking.example/mcp')['resource']);
    }

    public function testDenyReturnsOAuthErrorToValidatedRedirectWithIssuer(): void
    {
        $response = $this->service->authorize($this->query, '', false);
        parse_str(parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        self::assertSame('access_denied', $query['error']);
        self::assertSame('client-state', $query['state']);
        self::assertSame('https://booking.example', $query['iss']);
        self::assertSame([], $this->issued);
    }

    public function testClientAuthenticationMustUseRegisteredMethodAndOneCredentialSource(): void
    {
        $secret = 'confidential-client-secret';
        $this->db->prepare("UPDATE oauth_clients SET auth_method='client_secret_basic', secret_hash=? WHERE id='test-client'")
            ->execute([password_hash($secret, PASSWORD_DEFAULT)]);
        $code = $this->code();
        $parameters = [
            'grant_type' => 'authorization_code', 'code' => $code,
            'redirect_uri' => 'https://client.example/callback',
            'code_verifier' => $this->verifier, 'resource' => 'https://booking.example/mcp',
        ];
        foreach ([
            [$parameters + ['client_id' => 'test-client', 'client_secret' => $secret], null],
            [$parameters + ['client_id' => 'other-client'], 'Basic ' . base64_encode('test-client:' . $secret)],
            [$parameters + ['client_id' => 'test-client', 'client_secret' => $secret], 'Basic ' . base64_encode('test-client:' . $secret)],
        ] as [$body, $header]) {
            try {
                $this->service->token($body, $header);
                self::fail('Conflicting or wrong authentication method accepted');
            } catch (OAuthServerException) {
                self::assertSame([], $this->issued);
            }
        }
        $response = $this->service->token($parameters, 'Basic ' . base64_encode('test-client:' . $secret));
        self::assertSame(200, $response->getStatusCode());
    }
}
