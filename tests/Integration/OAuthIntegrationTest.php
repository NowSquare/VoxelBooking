<?php

declare(strict_types=1);
namespace Tests\Integration;

use App\Agent\{ConnectionAuthenticator, ConnectionService, OperationDefinition, OperationException, OperationRegistry, PrincipalResolver};
use App\Agent\OAuth\Factory;
use App\Agent\OAuth\Maintenance;
use App\Engine\{CustomerCapability, Database, Request};
use League\OAuth2\Server\Exception\OAuthServerException;
use PHPUnit\Framework\TestCase;

final class OAuthIntegrationTest extends TestCase
{
    protected function setUp(): void { TestFixtures::provision(); }

    private function registry(): OperationRegistry
    {
        return new OperationRegistry(new OperationDefinition(
            'booking_read', 'Read booking', 'Read a permitted booking.',
            ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => []], ['bookings:read'], 'staff',
            true, false, 'none', ['timeslot'], 'bookings', ['C05'], ['A01'], 'GET', '/fixture', static fn (): array => [],
        ));
    }

    private function exchange(Factory $factory, string $clientId, string $connectionId): array
    {
        $verifier = str_repeat('a', 64);
        $query = [
            'response_type' => 'code', 'client_id' => $clientId,
            'redirect_uri' => 'https://client.example/callback', 'scope' => 'bookings:read',
            'resource' => 'https://booking.example/mcp', 'state' => 'browser-state',
            'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        ];
        Database::execute('INSERT INTO oauth_grants (connection_id, audience) VALUES (?, ?)', [$connectionId, $query['resource']]);
        $response = Database::transaction(fn () => $factory->authorization->authorize($query, $connectionId, true));
        parse_str(parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $callback);
        self::assertSame('https://booking.example', $callback['iss']);
        $response = $factory->authorization->token([
            'grant_type' => 'authorization_code', 'code' => $callback['code'], 'code_verifier' => $verifier,
            'client_id' => $clientId, 'redirect_uri' => $query['redirect_uri'], 'resource' => $query['resource'],
        ]);
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function register(Factory $factory): string
    {
        return $factory->registration->register(['client_name' => 'Integration client', 'redirect_uris' => ['https://client.example/callback'], 'token_endpoint_auth_method' => 'none'])['client_id'];
    }

    public function testMysqlLibraryGrantUsesSharedCredentialAndRevocation(): void
    {
        $registry = $this->registry();
        $factory = new Factory($registry, 'https://booking.example');
        $client = $this->register($factory);
        $actor = (new PrincipalResolver())->resolve('business_user', TestFixtures::BUSINESS_USER_ID, [TestFixtures::BUSINESS_TENANT_ID], ['bookings:read'], authMode: 'session');
        $grant = (new ConnectionService($registry))->createGrant($actor, 'Integration client', ['bookings:read'], $actor->businessIds, gmdate('Y-m-d H:i:s', time() + 3600), 'Integration test', $client);
        $tokens = $this->exchange($factory, $client, $grant['id']);
        $authenticated = (new ConnectionAuthenticator())->authenticate($tokens['access_token'], 'https://booking.example/mcp');
        self::assertSame($actor->principalId, $authenticated->principalId);
        self::assertSame($grant['id'], $authenticated->connectionId);
        self::assertSame(['bookings:read'], $authenticated->scopes);
        $factory->authorization->revoke(['client_id' => $client, 'token' => $tokens['access_token']]);
        $this->expectException(OperationException::class);
        (new ConnectionAuthenticator())->authenticate($tokens['access_token'], 'https://booking.example/mcp');
    }

    public function testCustomerGrantIsBoundToVerifiedSubjectAndCurrentCapability(): void
    {
        $raw = CustomerCapability::issueManage(TestFixtures::BUSINESS_TENANT_ID, TestFixtures::BOOKING_ID);
        $_SERVER['HTTP_X_CUSTOMER_CAPABILITY'] = $raw;
        $actor = CustomerCapability::actor(new Request(), CustomerCapability::MANAGE, TestFixtures::BUSINESS_TENANT_ID, TestFixtures::BOOKING_ID);
        unset($_SERVER['HTTP_X_CUSTOMER_CAPABILITY']);
        self::assertNotNull($actor);
        $registry = $this->registry();
        $factory = new Factory($registry, 'https://booking.example');
        $client = $this->register($factory);
        $grant = (new ConnectionService($registry))->createCustomerGrant($actor, 'Customer client', ['bookings:read'], gmdate('Y-m-d H:i:s', time() + 600), 'Customer consent test', $client);
        $tokens = $this->exchange($factory, $client, $grant['id']);
        $authenticated = (new ConnectionAuthenticator())->authenticate($tokens['access_token'], 'https://booking.example/mcp');
        self::assertSame('customer', $authenticated->principalType);
        self::assertSame(TestFixtures::BOOKING_ID, $authenticated->bookingId);
        self::assertSame(TestFixtures::CUSTOMER_ID, $authenticated->customerId);
        self::assertLessThanOrEqual(600, $tokens['expires_in']);
        CustomerCapability::revokeForSubject(TestFixtures::BUSINESS_TENANT_ID, CustomerCapability::MANAGE, TestFixtures::BOOKING_ID);
        try {
            (new ConnectionAuthenticator())->authenticate($tokens['access_token'], 'https://booking.example/mcp');
            self::fail('Revoked customer source accepted');
        } catch (OperationException) {
            self::assertTrue(true);
        }
        $this->expectException(OperationException::class);
        $factory->authorization->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client, 'resource' => 'https://booking.example/mcp']);
    }

    public function testCleanupRetainsLiveRefreshReplayEvidenceAndLiveClient(): void
    {
        $registry = $this->registry();
        $factory = new Factory($registry, 'https://booking.example');
        $client = $this->register($factory);
        $actor = (new PrincipalResolver())->resolve('business_user', TestFixtures::BUSINESS_USER_ID, [TestFixtures::BUSINESS_TENANT_ID], ['bookings:read'], authMode: 'session');
        $grant = (new ConnectionService($registry))->createGrant($actor, 'Cleanup client', ['bookings:read'], $actor->businessIds, gmdate('Y-m-d H:i:s', time() + 3600), 'Cleanup test', $client);
        $tokens = $this->exchange($factory, $client, $grant['id']);
        $factory->authorization->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client, 'resource' => 'https://booking.example/mcp']);
        Database::execute('UPDATE oauth_clients SET created_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 100 * 86400), $client]);
        Maintenance::cleanup();
        self::assertSame(1, (int) Database::query('SELECT COUNT(*) n FROM oauth_clients WHERE id = ?', [$client])[0]['n']);
        self::assertSame(1, (int) Database::query('SELECT COUNT(*) n FROM oauth_refresh_tokens WHERE connection_id = ? AND revoked_at IS NOT NULL', [$grant['id']])[0]['n']);
        $this->expectException(OAuthServerException::class);
        $factory->authorization->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client, 'resource' => 'https://booking.example/mcp']);
    }

    public function testTokenExchangeDoesNotUseIdentitySnapshotFromBeforeConcurrentDeactivation(): void
    {
        $db = Database::pdo();
        $other = new \PDO(
            'mysql:host='.$_ENV['DB_HOST'].';port='.$_ENV['DB_PORT'].';dbname='.$_ENV['DB_DATABASE'].';charset=utf8mb4',
            $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION],
        );
        $registry = $this->registry();
        $factory = new Factory($registry, 'https://booking.example');
        $client = $this->register($factory);
        $actor = (new PrincipalResolver())->resolve('business_user', TestFixtures::BUSINESS_USER_ID,
            [TestFixtures::BUSINESS_TENANT_ID], ['bookings:read'], authMode:'session');
        $grant = (new ConnectionService($registry))->createGrant($actor, 'Concurrent OAuth', ['bookings:read'],
            $actor->businessIds, gmdate('Y-m-d H:i:s', time()+3600), 'Current identity verification', $client);
        Database::execute('INSERT INTO oauth_grants (connection_id,audience) VALUES (?,?)', [$grant['id'],'https://booking.example/mcp']);
        $deactivate = false;
        $issued = 0;
        $tokens = new \App\Agent\OAuth\TokenRepository($db,
            static function () use (&$issued): array {
                $issued++;
                self::fail('Credential issuance started after concurrent deactivation');
            },
            static function () use (&$deactivate, $other, $actor): void {
                if ($deactivate) {
                    // League has already read the client inside the transaction.
                    // A second transaction commits a role-state change before
                    // the grant's current-identity check and token issuance.
                    $other->prepare('UPDATE business_users SET is_active=0 WHERE id=?')->execute([$actor->principalId]);
                }
                (new PrincipalResolver())->resolve('business_user', $actor->principalId,
                    $actor->businessIds, $actor->scopes, authMode:'session');
            }, static function (): void {},
        );
        $keys = (new \App\Agent\OAuth\KeyStore(dirname(__DIR__,2).'/storage/keys'))->load();
        $service = new \App\Agent\OAuth\AuthorizationService($db, $factory->clients, $tokens, ['bookings:read'],
            'https://booking.example', ['https://booking.example/mcp'], $keys['private_key'], $keys['encryption_key']);
        $verifier = str_repeat('c', 64);
        $query = ['response_type'=>'code','client_id'=>$client,'redirect_uri'=>'https://client.example/callback',
            'scope'=>'bookings:read','resource'=>'https://booking.example/mcp','code_challenge_method'=>'S256',
            'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')];
        $response = $service->authorize($query,$grant['id'],true);
        parse_str(parse_url($response->getHeaderLine('Location'),PHP_URL_QUERY),$callback);
        $deactivate = true;
        try {
            $service->token(['grant_type'=>'authorization_code','code'=>$callback['code'],'code_verifier'=>$verifier,
                'client_id'=>$client,'redirect_uri'=>$query['redirect_uri'],'resource'=>$query['resource']]);
            self::fail('A committed deactivation was hidden by the token transaction snapshot');
        } catch (OperationException $error) {
            self::assertSame('unauthorized',$error->problemCode);
            self::assertSame(0,$issued);
        } finally {
            $other->prepare('UPDATE business_users SET is_active=1 WHERE id=?')->execute([$actor->principalId]);
        }
    }
}
