<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Agent\{ActorContext, OperationException, PayloadCipher, PrincipalResolver};
use App\Application\Integrations\{WebhookHttp, WebhookService};
use App\Engine\{Database, Ulid};
use PHPUnit\Framework\TestCase;

/**
 * Queued webhook events belong to the destination and business they were published for.
 */
final class WebhookDeliveryIntegrityTest extends TestCase
{
    private array $subscriptionIds = [];
    private array $tenantIds = [];

    protected function setUp(): void
    {
        TestFixtures::provision();
    }

    protected function tearDown(): void
    {
        foreach ($this->subscriptionIds as $id) {
            Database::execute(
                'DELETE o FROM delivery_outbox o JOIN webhook_deliveries d ON d.outbox_id=o.id WHERE d.subscription_id=?',
                [$id]
            );
            Database::execute('DELETE FROM webhook_deliveries WHERE subscription_id=?', [$id]);
            Database::execute('DELETE FROM webhook_subscriptions WHERE id=?', [$id]);
        }
        foreach ($this->tenantIds as $id) {
            Database::execute('DELETE FROM tenants WHERE id=?', [$id]);
        }
    }

    public function testEndpointChangeRetiresDeliveryWhoseWorkerDied(): void
    {
        $subscription = $this->subscribe();
        $delivery = $this->publishOne($subscription['id']);
        // A worker claimed the row, then died: its lease ran out without an outcome.
        Database::execute(
            "UPDATE delivery_outbox SET status='sending',lease_token='dead-worker',lease_until=? WHERE id=?",
            [gmdate('Y-m-d H:i:s', time() - 60), $delivery['outbox_id']]
        );

        $this->changeEndpoint($subscription, 'https://new-receiver.example/events');

        $outbox = Database::query(
            'SELECT status,last_error_code,lease_token FROM delivery_outbox WHERE id=?',
            [$delivery['outbox_id']]
        )[0];
        self::assertSame('delivered', $outbox['status']);
        self::assertSame('destination_changed', $outbox['last_error_code']);
        self::assertNull($outbox['lease_token']);
    }

    public function testDeliveryPublishedForAnEarlierEndpointNeverReachesTheNewOne(): void
    {
        $subscription = $this->subscribe();
        $delivery = $this->publishOne($subscription['id']);
        // A live worker still holds the row while the owner changes the destination.
        Database::execute(
            "UPDATE delivery_outbox SET status='sending',lease_token='live-worker',lease_until=? WHERE id=?",
            [gmdate('Y-m-d H:i:s', time() + 600), $delivery['outbox_id']]
        );
        $this->changeEndpoint($subscription, 'https://new-receiver.example/events');

        $requests = [];
        $http = new WebhookHttp(
            static fn () => ['8.8.8.8'],
            static function (array $target) use (&$requests): int {
                $requests[] = $target['url'];
                return 204;
            }
        );
        $result = WebhookService::deliver($this->outboxPayload($delivery['outbox_id']), $http);

        self::assertSame([], $requests);
        self::assertTrue($result['skipped']);
        self::assertSame(
            'destination_changed',
            Database::query('SELECT last_error_code FROM webhook_deliveries WHERE id=?', [$delivery['id']])[0]['last_error_code']
        );
    }

    public function testDeliveryToAnUnchangedEndpointIsSent(): void
    {
        $subscription = $this->subscribe();
        $delivery = $this->publishOne($subscription['id']);
        $requests = [];
        $http = new WebhookHttp(
            static fn () => ['8.8.8.8'],
            static function (array $target) use (&$requests): int {
                $requests[] = $target['url'];
                return 204;
            }
        );

        self::assertTrue(WebhookService::deliver($this->outboxPayload($delivery['outbox_id']), $http)['sent']);
        self::assertCount(1, $requests);
    }

    public function testAnotherBusinessesSubscriptionCannotBeChanged(): void
    {
        $foreignTenant = $this->foreignTenant();
        $foreignId = Ulid::generate();
        Database::execute(
            'INSERT INTO webhook_subscriptions (id,tenant_id,name,endpoint,event_types,secret_cipher,created_at,updated_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',
            [
                $foreignId,
                $foreignTenant,
                'Foreign receiver',
                'https://foreign.example/events',
                json_encode(WebhookService::EVENTS, JSON_THROW_ON_ERROR),
                (new PayloadCipher())->encrypt(['secret' => bin2hex(random_bytes(32))])
            ]
        );
        $this->subscriptionIds[] = $foreignId;
        $before = Database::query('SELECT * FROM webhook_subscriptions WHERE id=?', [$foreignId])[0];
        $input = [
            'business_id' => TestFixtures::BUSINESS_TENANT_ID,
            'subscription_id' => $foreignId,
            'expected_version' => hash('sha256', $foreignId . ':' . $before['generation'])
        ];

        $attempts = [
            'update' => static fn (ActorContext $actor) => WebhookService::update(
                $actor,
                [...$input, 'endpoint' => 'https://attacker.example/events']
            ),
            'rotate' => static fn (ActorContext $actor) => WebhookService::rotate($actor, $input),
            'reveal' => static fn (ActorContext $actor) => WebhookService::reveal(
                $actor,
                TestFixtures::BUSINESS_TENANT_ID,
                $foreignId
            ),
        ];
        foreach ($attempts as $name => $attempt) {
            try {
                $attempt($this->actor());
                self::fail('Cross-business ' . $name . ' succeeded.');
            } catch (OperationException $error) {
                self::assertSame(404, $error->status, $name);
            }
        }

        self::assertSame($before, Database::query('SELECT * FROM webhook_subscriptions WHERE id=?', [$foreignId])[0]);
    }

    private function actor(): ActorContext
    {
        return (new PrincipalResolver())->resolve(
            'business_user',
            TestFixtures::BUSINESS_USER_ID,
            [TestFixtures::BUSINESS_TENANT_ID],
            ['webhooks:read', 'webhooks:manage'],
            authMode: 'session'
        );
    }

    private function subscribe(): array
    {
        $row = WebhookService::create($this->actor(), [
            'business_id' => TestFixtures::BUSINESS_TENANT_ID,
            'name' => 'Integrity receiver',
            'endpoint' => 'https://receiver.example/events',
            'event_types' => WebhookService::EVENTS
        ]);
        $this->subscriptionIds[] = $row['id'];
        return $row;
    }

    private function publishOne(string $subscriptionId): array
    {
        Database::transaction(static fn () => WebhookService::publish(
            TestFixtures::BUSINESS_TENANT_ID,
            'booking.created',
            ['id' => TestFixtures::BOOKING_ID, 'status' => 'confirmed', 'booking_pattern' => 'timeslot']
        ));
        return Database::query('SELECT * FROM webhook_deliveries WHERE subscription_id=?', [$subscriptionId])[0];
    }

    private function changeEndpoint(array $subscription, string $endpoint): void
    {
        WebhookService::update($this->actor(), [
            'business_id' => $subscription['business_id'],
            'subscription_id' => $subscription['id'],
            'expected_version' => $subscription['version'],
            'endpoint' => $endpoint
        ]);
    }

    /** The payload exactly as the outbox worker hands it to the handler. */
    private function outboxPayload(string $outboxId): array
    {
        $cipher = Database::query('SELECT payload_cipher FROM delivery_outbox WHERE id=?', [$outboxId])[0]['payload_cipher'];
        return (new PayloadCipher())->decrypt($cipher);
    }

    private function foreignTenant(): string
    {
        $id = Ulid::generate();
        Database::execute(
            "INSERT INTO tenants (id,name,slug,email,booking_pattern,timezone,currency,status) VALUES (?,'Other business',?,'other@example.test','timeslot','UTC','EUR','active')",
            [$id, 'webhook-other-' . strtolower($id)]
        );
        $this->tenantIds[] = $id;
        return $id;
    }
}
