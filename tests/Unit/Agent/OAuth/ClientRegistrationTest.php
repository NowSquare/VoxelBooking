<?php

declare(strict_types=1);
namespace Tests\Unit\Agent\OAuth;

use App\Agent\OAuth\ClientMetadata;
use App\Agent\OAuth\MetadataFetcher;
use App\Agent\OAuth\ClientRegistration;
use App\Agent\OAuth\ClientRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientRegistrationTest extends TestCase
{
    public function testPublicClientMetadataAcceptsHttpsAndNativeLoopbackRedirects(): void
    {
        $metadata = ClientMetadata::validate([
            'client_name' => 'Example client',
            'redirect_uris' => ['https://client.example/callback', 'http://127.0.0.1:3456/callback', 'http://[::1]:3456/callback'],
        ]);
        self::assertSame('none', $metadata['token_endpoint_auth_method']);
        self::assertSame(['authorization_code', 'refresh_token'], $metadata['grant_types']);
    }

    #[DataProvider('badMetadata')]
    public function testUnsafeMetadataIsRejected(array $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClientMetadata::validate($input);
    }

    public static function badMetadata(): array
    {
        $valid = ['client_name' => 'Example', 'redirect_uris' => ['https://client.example/callback']];
        return [
            [['client_name' => 'Example', 'redirect_uris' => ['https://client.example/callback#fragment']]],
            [['client_name' => 'Example', 'redirect_uris' => ['http://client.example/callback']]],
            [['client_name' => 'Example', 'redirect_uris' => ['https://name:secret@client.example/callback']]],
            [['client_name' => 'Example', 'redirect_uris' => []]],
            [['client_name' => str_repeat('x', 121)] + $valid],
            [['grant_types' => ['password']] + $valid],
            [['token_endpoint_auth_method' => 'private_key_jwt'] + $valid],
            [['response_types' => ['token']] + $valid],
        ];
    }

    public function testCimdRequiresItsExactHttpsIdentifier(): void
    {
        $url = 'https://client.example/oauth/client.json';
        self::assertSame($url, ClientMetadata::validate([
            'client_id' => $url, 'client_name' => 'Example', 'redirect_uris' => ['https://client.example/callback'],
        ], $url)['client_id']);
        $this->expectException(\InvalidArgumentException::class);
        ClientMetadata::validate([
            'client_id' => 'https://other.example/client.json', 'client_name' => 'Example', 'redirect_uris' => ['https://client.example/callback'],
        ], $url);
    }

    #[DataProvider('unsafeUrls')]
    public function testCimdRejectsUnsafeLocationsBeforeNetwork(string $url, array $addresses): void
    {
        $fetcher = new MetadataFetcher(
            static fn (): array => $addresses,
            static function (): array { throw new \LogicException('Network must not run'); },
        );
        $this->expectException(\InvalidArgumentException::class);
        $fetcher->fetch($url);
    }

    public static function unsafeUrls(): array
    {
        return [
            ['http://client.example/client.json', ['1.1.1.1']],
            ['https://client.example', ['1.1.1.1']],
            ['https://user:secret@client.example/client.json', ['1.1.1.1']],
            ['https://client.example/client.json#fragment', ['1.1.1.1']],
            ['https://client.example:8443/client.json', ['1.1.1.1']],
            ['https://client.example/client.json', ['127.0.0.1']],
            ['https://client.example/client.json', ['169.254.169.254']],
            ['https://client.example/client.json', ['::1']],
            ['https://client.example/client.json', ['::ffff:127.0.0.1']],
            ['https://client.example/client.json', ['1.1.1.1', '10.1.2.3']],
            ['https://client.example/client.json', []],
        ];
    }

    public function testCimdPinsValidatedAddressAndRejectsRedirects(): void
    {
        $url = 'https://client.example/client.json';
        $fetcher = new MetadataFetcher(
            static fn (): array => ['1.1.1.1'],
            static function (string $requested, string $host, string $ip) use ($url): array {
                self::assertSame($url, $requested);
                self::assertSame('client.example', $host);
                self::assertSame('1.1.1.1', $ip);
                return ['status' => 302, 'body' => '', 'cache_control' => ''];
            },
        );
        $this->expectException(\InvalidArgumentException::class);
        $fetcher->fetch($url);
    }

    public function testRegistrationReturnsSecretOnceAndStoresOnlyHash(): void
    {
        $db = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE oauth_clients (id TEXT PRIMARY KEY, name TEXT, redirect_uris TEXT, secret_hash TEXT, auth_method TEXT, registration_type TEXT, metadata_expires_at TEXT, revoked_at TEXT, created_at TEXT)');
        $clients = new ClientRepository($db);
        $registration = new ClientRegistration($db, $clients);
        $result = $registration->register([
            'client_name' => 'Confidential client', 'redirect_uris' => ['https://client.example/callback'],
            'token_endpoint_auth_method' => 'client_secret_basic',
        ]);
        self::assertTrue($clients->validateClient($result['client_id'], $result['client_secret'], 'authorization_code'));
        self::assertFalse($clients->validateClient($result['client_id'], 'wrong', 'authorization_code'));
        self::assertFalse($clients->validateClient($result['client_id'], $result['client_secret'], 'password'));
        self::assertStringNotContainsString($result['client_secret'], json_encode($db->query('SELECT * FROM oauth_clients')->fetchAll(\PDO::FETCH_ASSOC)));
    }
}
