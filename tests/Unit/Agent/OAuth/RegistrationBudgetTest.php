<?php

declare(strict_types=1);

namespace Tests\Unit\Agent\OAuth;

use App\Agent\OAuth\ClientRegistration;
use App\Agent\OAuth\ClientRepository;
use App\Agent\OAuth\MetadataFetcher;
use App\Agent\OAuth\RegistrationThrottled;
use App\Agent\RateBudget;
use PDO;
use PHPUnit\Framework\TestCase;

final class RegistrationBudgetTest extends TestCase
{
    private PDO $db;
    private int $fetches = 0;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE oauth_clients (id TEXT PRIMARY KEY, name TEXT, redirect_uris TEXT, secret_hash TEXT, auth_method TEXT, registration_type TEXT, metadata_expires_at TEXT, revoked_at TEXT, created_at TEXT)');
        $this->db->exec('CREATE TABLE agent_rate_budgets (budget_key TEXT NOT NULL, window_start INTEGER NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, expires_at TEXT NOT NULL, PRIMARY KEY (budget_key, window_start))');
    }

    private function registration(): ClientRegistration
    {
        $fetcher = new MetadataFetcher(
            static fn (): array => ['1.1.1.1'],
            function (string $url): array {
                $this->fetches++;
                return [
                    'status' => 200,
                    'body' => json_encode([
                        'client_id' => $url,
                        'client_name' => 'Metadata client',
                        'redirect_uris' => ['https://client.example/callback'],
                    ]),
                    'cache_control' => 'no-store',
                ];
            },
        );

        return new ClientRegistration($this->db, new ClientRepository($this->db), $fetcher, new RateBudget($this->db));
    }

    private static function metadata(): array
    {
        return ['client_name' => 'Dynamic client', 'redirect_uris' => ['https://client.example/callback']];
    }

    private function fillRegistry(int $count): void
    {
        $statement = $this->db->prepare("INSERT INTO oauth_clients VALUES (?, 'Filler', '[]', NULL, 'none', 'dynamic', NULL, NULL, '2026-01-01 00:00:00')");
        $this->db->beginTransaction();
        for ($index = 0; $index < $count; $index++) {
            $statement->execute(['filler-' . $index]);
        }
        $this->db->commit();
    }

    public function testMetadataDocumentClientsCountTowardTheRegistryCapacity(): void
    {
        $this->fillRegistry(10000);

        try {
            $this->registration()->ensure('https://client.example/client.json');
            self::fail('A metadata document client was admitted past the registry capacity');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('registration_limit', $error->getMessage());
        }
        self::assertSame(0, $this->fetches);
    }

    public function testKnownMetadataDocumentClientStillRefreshesAtCapacity(): void
    {
        $registration = $this->registration();
        $registration->ensure('https://client.example/client.json');
        $this->fillRegistry(10000);

        $client = $this->registration()->ensure('https://client.example/client.json');

        self::assertSame('https://client.example/client.json', $client->getIdentifier());
    }

    public function testOperatorPreregistrationIsNeverLockedOutByAFullRegistry(): void
    {
        $this->fillRegistry(10000);

        $result = $this->registration()->register(self::metadata(), 'preregistered');

        self::assertStringStartsWith('vbo_', $result['client_id']);
    }

    public function testDynamicRegistrationIsBoundedPerOriginPerHour(): void
    {
        $registration = $this->registration();
        for ($attempt = 0; $attempt < ClientRegistration::DYNAMIC_REGISTRATIONS_PER_ORIGIN_PER_HOUR; $attempt++) {
            $registration->register(self::metadata(), 'dynamic', '203.0.113.7');
        }

        try {
            $registration->register(self::metadata(), 'dynamic', '203.0.113.7');
            self::fail('One origin registered past its hourly budget');
        } catch (RegistrationThrottled $throttled) {
            self::assertGreaterThan(0, $throttled->retryAfter);
        }

        $other = $registration->register(self::metadata(), 'dynamic', '198.51.100.9');
        self::assertStringStartsWith('vbo_', $other['client_id']);
    }

    public function testNewMetadataDocumentClientsAreBoundedPerHostPerHour(): void
    {
        $registration = $this->registration();
        for ($index = 0; $index < ClientRegistration::METADATA_CLIENTS_PER_HOST_PER_HOUR; $index++) {
            $registration->ensure('https://client.example/client-' . $index . '.json');
        }
        $fetchesBeforeRefusal = $this->fetches;

        try {
            $registration->ensure('https://client.example/client-overflow.json');
            self::fail('One host registered metadata clients past its hourly budget');
        } catch (RegistrationThrottled) {
            self::assertSame($fetchesBeforeRefusal, $this->fetches);
        }

        $other = $registration->ensure('https://other.example/client.json');
        self::assertSame('https://other.example/client.json', $other->getIdentifier());
    }

    public function testMetadataDocumentFetchesAreBoundedPerHostPerHour(): void
    {
        $registration = $this->registration();
        for ($index = 0; $index < ClientRegistration::METADATA_FETCHES_PER_HOST_PER_HOUR; $index++) {
            // no-store metadata is fetched again on every request.
            $this->registration()->ensure('https://client.example/client.json');
        }

        $this->expectException(RegistrationThrottled::class);
        $registration->ensure('https://client.example/client.json');
    }
}
