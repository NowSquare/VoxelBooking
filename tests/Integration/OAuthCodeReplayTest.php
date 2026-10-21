<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Agent\ConnectionAuthenticator;
use App\Agent\ConnectionService;
use App\Agent\OAuth\Factory;
use App\Agent\OperationDefinition;
use App\Agent\OperationException;
use App\Agent\OperationRegistry;
use App\Agent\PrincipalResolver;
use App\Engine\Database;
use League\OAuth2\Server\Exception\OAuthServerException;
use PHPUnit\Framework\TestCase;

final class OAuthCodeReplayTest extends TestCase
{
    private const RESOURCE = 'https://booking.example/mcp';
    private const REDIRECT = 'https://client.example/callback';

    protected function setUp(): void
    {
        TestFixtures::provision();
    }

    private function registry(): OperationRegistry
    {
        return new OperationRegistry(new OperationDefinition(
            'booking_read', 'Read booking', 'Read a permitted booking.',
            ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => []], ['bookings:read'], 'staff',
            true, false, 'none', ['timeslot'], 'bookings', ['C05'], ['A01'], 'GET', '/fixture', static fn (): array => [],
        ));
    }

    public function testReplayedCodeRevokesEverythingTheFirstRedemptionIssued(): void
    {
        $registry = $this->registry();
        $factory = new Factory($registry, 'https://booking.example');
        $client = $factory->registration->register([
            'client_name' => 'Replay client',
            'redirect_uris' => [self::REDIRECT],
            'token_endpoint_auth_method' => 'none',
        ])['client_id'];
        $actor = (new PrincipalResolver())->resolve(
            'business_user',
            TestFixtures::BUSINESS_USER_ID,
            [TestFixtures::BUSINESS_TENANT_ID],
            ['bookings:read'],
            authMode: 'session',
        );
        $grant = (new ConnectionService($registry))->createGrant(
            $actor,
            'Replay client',
            ['bookings:read'],
            $actor->businessIds,
            gmdate('Y-m-d H:i:s', time() + 3600),
            'Code replay test',
            $client,
        );
        Database::execute('INSERT INTO oauth_grants (connection_id, audience) VALUES (?, ?)', [$grant['id'], self::RESOURCE]);

        $verifier = str_repeat('a', 64);
        $query = [
            'response_type' => 'code',
            'client_id' => $client,
            'redirect_uri' => self::REDIRECT,
            'scope' => 'bookings:read',
            'resource' => self::RESOURCE,
            'state' => 'browser-state',
            'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        ];
        $response = Database::transaction(fn () => $factory->authorization->authorize($query, $grant['id'], true));
        parse_str(parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $callback);
        $exchange = [
            'grant_type' => 'authorization_code',
            'code' => $callback['code'],
            'code_verifier' => $verifier,
            'client_id' => $client,
            'redirect_uri' => self::REDIRECT,
            'resource' => self::RESOURCE,
        ];

        $first = json_decode((string) $factory->authorization->token($exchange)->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $authenticated = (new ConnectionAuthenticator())->authenticate($first['access_token'], self::RESOURCE);
        self::assertSame($grant['id'], $authenticated->connectionId);

        try {
            $factory->authorization->token($exchange);
            self::fail('A redeemed authorization code was accepted twice');
        } catch (OAuthServerException $error) {
            self::assertSame('invalid_grant', $error->getErrorType());
        }

        try {
            (new ConnectionAuthenticator())->authenticate($first['access_token'], self::RESOURCE);
            self::fail('The access token issued from a replayed code still authenticates');
        } catch (OperationException) {
            self::assertNotNull(Database::query('SELECT revoked_at FROM agent_connections WHERE id = ?', [$grant['id']])[0]['revoked_at']);
        }

        $this->expectException(OAuthServerException::class);
        $factory->authorization->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $first['refresh_token'],
            'client_id' => $client,
            'resource' => self::RESOURCE,
        ]);
    }
}
