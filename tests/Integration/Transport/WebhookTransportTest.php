<?php

declare(strict_types=1);

namespace Tests\Integration\Transport;

use App\Agent\ActorContext;
use App\Application\Integrations\WebhookService;
use App\Engine\{Database, Ulid};
use Tests\Support\Transport\TransportTestCase;

/**
 * Webhook subscriptions and deliveries over HTTP v2 and native MCP.
 *
 * Nothing here contacts a receiver. Endpoints use the reserved `.example` domain,
 * deliveries are queued by an in-process domain event and never dispatched, and
 * every subscription, delivery and outbox row is removed when the test ends.
 */
final class WebhookTransportTest extends TransportTestCase
{
    private const OPERATIONS = ['webhook_create', 'webhooks_list', 'webhook_get', 'webhook_update', 'webhook_secret_rotate', 'webhook_deliveries_list', 'webhook_delivery_retry'];

    private string $business;
    private ActorContext $session;
    private array $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = $this->tenant('timeslot')['id'];
        $this->session = $this->operatorSession([$this->business]);
        $this->client = $this->connect($this->session, self::OPERATIONS);
    }

    protected function tearDown(): void
    {
        Database::execute('DELETE FROM delivery_outbox WHERE tenant_id=?', [$this->business]);
        Database::execute('DELETE FROM webhook_deliveries WHERE tenant_id=?', [$this->business]);
        Database::execute('DELETE FROM webhook_subscriptions WHERE tenant_id=?', [$this->business]);
        parent::tearDown();
    }

    private function subscription(string $name): array
    {
        return $this->seedCommand($this->session, 'webhook_create', ['business_id' => $this->business, 'name' => $name,
            'endpoint' => 'https://receiver.example/events', 'event_types' => WebhookService::EVENTS])['data'];
    }

    /** A committed booking event queues one delivery per matching subscription, inside the domain transaction. */
    private function delivery(string $subscriptionId): array
    {
        Database::transaction(fn() => WebhookService::publish($this->business, 'booking.created', ['id' => Ulid::generate(), 'status' => 'confirmed', 'booking_pattern' => 'timeslot']));
        return Database::query('SELECT * FROM webhook_deliveries WHERE subscription_id=? ORDER BY id DESC LIMIT 1', [$subscriptionId])[0];
    }

    private function selector(array $subscription): array
    {
        return ['business_id' => $this->business, 'subscription_id' => $subscription['id']];
    }

    public function testWebhookCreateOverHttpAndMcp(): void
    {
        foreach (self::TRANSPORTS as $transport) {
            $data = $this->call($transport, $this->client, 'webhook_create', ['business_id' => $this->business, 'name' => 'Receiver ' . $transport,
                'endpoint' => 'https://' . $transport . '.receiver.example/events', 'event_types' => ['booking.created', 'booking.cancelled']])['data'];
            self::assertSame('Receiver ' . $transport, $data['name']);
            self::assertSame(['booking.created', 'booking.cancelled'], $data['event_types']);
            self::assertArrayNotHasKey('secret', $data);
            self::assertSame($data, $this->read($this->session, 'webhook_get', $this->selector($data)), $transport);
            self::assertSame('https://' . $transport . '.receiver.example/events', Database::query('SELECT endpoint FROM webhook_subscriptions WHERE id=?', [$data['id']])[0]['endpoint']);
        }
    }

    public function testWebhooksListOverHttpAndMcp(): void
    {
        $first = $this->subscription('Listed first');
        $second = $this->subscription('Listed second');
        $expected = $this->read($this->session, 'webhooks_list', ['business_id' => $this->business]);
        foreach (self::TRANSPORTS as $transport) {
            $data = $this->call($transport, $this->client, 'webhooks_list', ['business_id' => $this->business])['data'];
            self::assertEqualsCanonicalizing([$first['id'], $second['id']], array_column($data['items'], 'id'), $transport);
            self::assertSame($expected, $data, $transport);
        }
    }

    public function testWebhookGetOverHttpAndMcp(): void
    {
        $subscription = $this->subscription('Read receiver');
        foreach (self::TRANSPORTS as $transport) {
            self::assertSame($subscription, $this->call($transport, $this->client, 'webhook_get', $this->selector($subscription))['data'], $transport);
        }
    }

    public function testWebhookUpdateOverHttpAndMcp(): void
    {
        $subscription = $this->subscription('Renamed receiver');
        foreach (self::TRANSPORTS as $transport) {
            $data = $this->call($transport, $this->client, 'webhook_update', [...$this->selector($subscription), 'expected_version' => $subscription['version'],
                'name' => 'Renamed over ' . $transport, 'event_types' => ['booking.rescheduled']])['data'];
            self::assertSame('Renamed over ' . $transport, $data['name']);
            self::assertSame(['booking.rescheduled'], $data['event_types']);
            self::assertSame($data, $this->read($this->session, 'webhook_get', $this->selector($subscription)), $transport);
            $subscription = $data;
        }
    }

    public function testWebhookSecretRotateOverHttpAndMcp(): void
    {
        $subscription = $this->subscription('Rotated receiver');
        foreach (self::TRANSPORTS as $transport) {
            $before = Database::query('SELECT generation, secret_cipher FROM webhook_subscriptions WHERE id=?', [$subscription['id']])[0];
            $data = $this->call($transport, $this->client, 'webhook_secret_rotate', [...$this->selector($subscription), 'expected_version' => $subscription['version']])['data'];
            self::assertNotSame($subscription['version'], $data['version']);
            self::assertArrayNotHasKey('secret', $data);
            $after = Database::query('SELECT generation, secret_cipher, previous_secret_cipher FROM webhook_subscriptions WHERE id=?', [$subscription['id']])[0];
            self::assertSame((int) $before['generation'] + 1, (int) $after['generation'], $transport);
            self::assertSame($before['secret_cipher'], $after['previous_secret_cipher'], 'The previous secret overlaps for receivers mid-rotation.');
            $subscription = $data;
        }
    }

    public function testWebhookDeliveriesListOverHttpAndMcp(): void
    {
        $subscription = $this->subscription('Delivery receiver');
        $delivery = $this->delivery($subscription['id']);
        foreach (self::TRANSPORTS as $transport) {
            $data = $this->call($transport, $this->client, 'webhook_deliveries_list', $this->selector($subscription))['data'];
            self::assertSame([$delivery['id']], array_column($data['items'], 'id'), $transport);
            self::assertSame('booking.created', $data['items'][0]['event_type']);
            self::assertNull($data['next_after_id']);
        }
    }

    public function testWebhookDeliveryRetryOverHttpAndMcp(): void
    {
        $subscription = $this->subscription('Retried receiver');
        $delivery = $this->delivery($subscription['id']);
        foreach (self::TRANSPORTS as $index => $transport) {
            // The automatic attempts are exhausted; only a person (or their agent) may queue another.
            Database::execute("UPDATE delivery_outbox SET status='failed', attempts=8, last_error_code='delivery_failed' WHERE id=?", [$delivery['outbox_id']]);
            $data = $this->call($transport, $this->client, 'webhook_delivery_retry', ['business_id' => $this->business, 'delivery_id' => $delivery['id']])['data'];
            self::assertSame(['delivery_id' => $delivery['id'], 'status' => 'queued'], $data);
            self::assertSame(['status' => 'pending', 'attempts' => 0], array_map(
                static fn($value) => is_numeric($value) ? (int) $value : $value,
                Database::query('SELECT status, attempts FROM delivery_outbox WHERE id=?', [$delivery['outbox_id']])[0]
            ), $transport);
            self::assertSame($index + 1, (int) Database::query('SELECT manual_retries FROM webhook_deliveries WHERE id=?', [$delivery['id']])[0]['manual_retries']);
        }
        // Never let a queued retry reach a dispatcher: the receiver is fictional.
        Database::execute("UPDATE delivery_outbox SET status='failed' WHERE id=?", [$delivery['outbox_id']]);
    }
}
