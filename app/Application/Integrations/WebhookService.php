<?php

declare(strict_types=1);

namespace App\Application\Integrations;

use App\Agent\{ActorContext, CanonicalJson, Endpoints, OperationException, PayloadCipher};
use App\Application\Shared\Access;
use App\Engine\{AuditLog, Database, DemoMode, Outbox, Ulid};

final class WebhookService
{
    public const EVENTS = ['booking.created', 'booking.status_changed', 'booking.cancelled', 'booking.rescheduled'];
    private const DATA_FIELDS = [
        'id',
        'booking_pattern',
        'status',
        'start_datetime',
        'end_datetime',
        'timezone',
        'rescheduled_to_id'
    ];

    public static function listing(ActorContext $actor, array $input): array
    {
        Access::tenant($actor, $input['business_id'], 'owner');
        $rows = Database::query(
            'SELECT * FROM webhook_subscriptions WHERE tenant_id = ? ORDER BY id LIMIT 100',
            [$input['business_id']]
        );
        return ['items' => array_map(self::project(...), $rows)];
    }

    public static function get(ActorContext $actor, array $input): array
    {
        return self::project(self::owned($actor, $input['business_id'], $input['subscription_id']));
    }

    public static function preview(ActorContext $actor, array $input): array
    {
        Access::tenant($actor, $input['business_id'], 'owner');
        if (isset($input['subscription_id'])) {
            $before = self::owned($actor, $input['business_id'], $input['subscription_id']);
            if (!hash_equals(self::version($before), $input['expected_version'])) {
                throw new OperationException('version_conflict', 409, nextAction: 'reread_record');
            }
        }

        self::validate($input);
        return [
            'business_id' => $input['business_id'],
            'subscription' => isset($before) ? self::project($before) : null,
            'changes' => array_intersect_key($input, array_flip(['name', 'endpoint', 'event_types', 'is_active'])),
            'disclosure' => __('agent-webhooks.disclosure')
        ];
    }

    public static function create(ActorContext $actor, array $input): array
    {
        return Access::transaction(static function () use ($actor, $input): array {
            self::mutable($actor, $input['business_id']);
            self::validate($input);
            $count = (int) Database::query(
                'SELECT COUNT(*) AS n FROM webhook_subscriptions WHERE tenant_id = ?',
                [$input['business_id']]
            )[0]['n'];
            if ($count >= 100) {
                throw new OperationException('subscription_limit', 409);
            }

            $now = gmdate('Y-m-d H:i:s');
            $id = Ulid::generate();
            Database::execute(
                'INSERT INTO webhook_subscriptions (id,tenant_id,name,endpoint,event_types,secret_cipher,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)',
                [
                    $id,
                    $input['business_id'],
                    $input['name'],
                    $input['endpoint'],
                    json_encode($input['event_types'], JSON_THROW_ON_ERROR),
                    (new PayloadCipher())->encrypt(['secret' => bin2hex(random_bytes(32))]),
                    $now,
                    $now
                ]
            );
            self::audit($actor, 'webhook.created', $id, $input['business_id']);
            return self::get($actor, ['business_id' => $input['business_id'], 'subscription_id' => $id]);
        });
    }

    public static function update(ActorContext $actor, array $input): array
    {
        return Access::transaction(static function () use ($actor, $input): array {
            self::mutable($actor, $input['business_id']);
            self::preview($actor, $input);
            $before = self::owned($actor, $input['business_id'], $input['subscription_id']);
            // Changing a destination must not send previously queued events to a new recipient.
            if (isset($input['endpoint']) && $input['endpoint'] !== $before['endpoint']) {
                Database::execute(
                    "UPDATE delivery_outbox o JOIN webhook_deliveries d ON d.outbox_id=o.id SET o.status='delivered',o.delivered_at=UTC_TIMESTAMP(),o.last_error_code='destination_changed' WHERE d.subscription_id=? AND o.status IN ('pending','failed')",
                    [$before['id']]
                );
            }

            $values = array_replace($before, $input);
            Database::execute(
                'UPDATE webhook_subscriptions SET name=?,endpoint=?,event_types=?,is_active=?,generation=generation+1,updated_at=? WHERE id=?',
                [
                    $values['name'],
                    $values['endpoint'],
                    is_array($values['event_types'])
                        ? json_encode(array_values(array_unique($values['event_types'])), JSON_THROW_ON_ERROR)
                        : $values['event_types'],
                    (int) $values['is_active'],
                    gmdate('Y-m-d H:i:s'),
                    $before['id']
                ]
            );
            self::audit($actor, 'webhook.updated', $before['id'], $input['business_id']);
            return self::get($actor, $input);
        });
    }

    public static function rotate(ActorContext $actor, array $input): array
    {
        return Access::transaction(static function () use ($actor, $input): array {
            self::mutable($actor, $input['business_id']);
            self::preview($actor, $input);
            Database::execute(
                'UPDATE webhook_subscriptions SET previous_secret_cipher=secret_cipher,previous_secret_until=?,secret_cipher=?,secret_revealed_at=NULL,generation=generation+1,updated_at=? WHERE id=?',
                [
                    gmdate('Y-m-d H:i:s', time() + 86400),
                    (new PayloadCipher())->encrypt(['secret' => bin2hex(random_bytes(32))]),
                    gmdate('Y-m-d H:i:s'),
                    $input['subscription_id']
                ]
            );
            self::audit($actor, 'webhook.secret_rotated', $input['subscription_id'], $input['business_id']);
            return self::get($actor, $input);
        });
    }

    /** Secret delivery is deliberately a current owner browser action, never a tool projection. */
    public static function reveal(ActorContext $actor, string $businessId, string $id): string
    {
        if ($actor->authMode !== 'session') {
            throw new OperationException('browser_required', 403);
        }

        return Access::transaction(static function () use ($actor, $businessId, $id): string {
            self::mutable($actor, $businessId);
            $row = self::owned($actor, $businessId, $id);
            if ($row['secret_revealed_at'] !== null) {
                throw new OperationException('secret_already_revealed', 409);
            }

            Database::execute(
                'UPDATE webhook_subscriptions SET secret_revealed_at=? WHERE id=?',
                [gmdate('Y-m-d H:i:s'), $id]
            );
            self::audit($actor, 'webhook.secret_revealed', $id, $businessId);
            return (new PayloadCipher())->decrypt($row['secret_cipher'])['secret'];
        });
    }

    /** Called within the same transaction as the booking change. One event, many subscribed deliveries. */
    public static function publish(
        string $businessId,
        string $eventType,
        array $data,
        ?ActorContext $actor = null
    ): void {
        if (!in_array($eventType, self::EVENTS, true)) {
            throw new \InvalidArgumentException('Unknown webhook event.');
        }

        if (!Database::pdo()->inTransaction()) {
            throw new \LogicException('Webhook intent must share the domain transaction.');
        }

        if (DemoMode::isActive()) {
            return;
        }

        $subscriptions = Database::query(
            'SELECT id,event_types FROM webhook_subscriptions WHERE tenant_id=? AND is_active=1',
            [$businessId]
        );
        $eventId = Ulid::generate();
        $body = [
            'id' => $eventId,
            'type' => $eventType,
            'schema_version' => '2026-10-20',
            'created_at' => gmdate('c'),
            'business_id' => $businessId,
            'actor' => [
                'type' => $actor?->principalType ?? 'system',
                'id' => $actor?->principalId,
                'connection_id' => $actor?->connectionId
            ],
            'correlation_id' => Outbox::currentReceiptId() ?? $eventId,
            'data' => array_intersect_key($data, array_flip(self::DATA_FIELDS))
        ];
        foreach ($subscriptions as $subscription) {
            if (!in_array(
                $eventType,
                json_decode($subscription['event_types'], true, flags: JSON_THROW_ON_ERROR),
                true
            )) {
                continue;
            }

            $id = Ulid::generate();
            Database::execute(
                'INSERT INTO webhook_deliveries (id,tenant_id,subscription_id,event_id,event_type,payload_cipher,created_at) VALUES (?,?,?,?,?,?,?)',
                [
                    $id,
                    $businessId,
                    $subscription['id'],
                    $eventId,
                    $eventType,
                    (new PayloadCipher())->encrypt($body),
                    gmdate('Y-m-d H:i:s')
                ]
            );
            $outboxId = Outbox::enqueue('webhook.deliver', ['delivery_id' => $id], $businessId, $id);
            Database::execute('UPDATE webhook_deliveries SET outbox_id=? WHERE id=?', [$outboxId, $id]);
        }
    }

    public static function deliver(array $payload, ?WebhookHttp $http = null): array
    {
        $row = Database::query(
            'SELECT d.*,s.endpoint,s.is_active,s.secret_cipher,s.previous_secret_cipher,s.previous_secret_until FROM webhook_deliveries d JOIN webhook_subscriptions s ON s.id=d.subscription_id JOIN tenants t ON t.id=d.tenant_id WHERE d.id=? AND t.status != ?',
            [$payload['delivery_id'], 'archived']
        )[0] ?? null;
        if (!$row || !$row['is_active'] || $row['payload_cipher'] === null) {
            return ['skipped' => true];
        }

        $cipher = new PayloadCipher();
        $body = CanonicalJson::encode($cipher->decrypt($row['payload_cipher']));
        $secret = $cipher->decrypt($row['secret_cipher'])['secret'];
        $previous = $row['previous_secret_cipher'] !== null && $row['previous_secret_until'] > gmdate('Y-m-d H:i:s')
            ? $cipher->decrypt($row['previous_secret_cipher'])['secret']
            : null;
        $status = null;
        try {
            $status = ($http ?? new WebhookHttp())->post($row['endpoint'], $body, [
                'VoxelBooking-Signature: ' . WebhookHttp::signature($body, time(), $secret, $previous),
                'VoxelBooking-Delivery-Id: ' . $row['id'],
                'VoxelBooking-Event: ' . $row['event_type'],
            ]);
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('webhook_receiver_rejected');
            }

            Database::execute(
                'UPDATE webhook_deliveries SET last_http_status=?,last_attempt_at=?,last_error_code=NULL WHERE id=?',
                [$status, gmdate('Y-m-d H:i:s'), $row['id']]
            );
            return ['sent' => true];
        } catch (\Throwable $error) {
            $code = $error instanceof \InvalidArgumentException
                ? 'unsafe_or_unavailable_destination'
                : ($status === null ? 'transport_failed' : 'receiver_rejected');
            Database::execute(
                'UPDATE webhook_deliveries SET last_http_status=?,last_attempt_at=?,last_error_code=? WHERE id=?',
                [$status, gmdate('Y-m-d H:i:s'), $code, $row['id']]
            );
            throw new \RuntimeException('webhook_delivery_failed');
        }
    }

    public static function deliveries(ActorContext $actor, array $input): array
    {
        self::owned($actor, $input['business_id'], $input['subscription_id']);
        $after = $input['after_id'] ?? '';
        $rows = Database::query(
            'SELECT d.id,d.event_id,d.event_type,d.last_http_status,d.last_error_code,d.last_attempt_at,d.created_at,d.manual_retries,o.status,o.attempts,o.available_at FROM webhook_deliveries d JOIN delivery_outbox o ON o.id=d.outbox_id WHERE d.tenant_id=? AND d.subscription_id=? AND d.id>? ORDER BY d.id LIMIT 101',
            [$input['business_id'], $input['subscription_id'], $after]
        );
        $more = count($rows) > 100;
        $rows = array_slice($rows, 0, 100);
        foreach ($rows as &$row) {
            foreach (['last_http_status', 'attempts', 'manual_retries'] as $key) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
        }

        unset($row);
        return ['items' => $rows, 'next_after_id' => $more ? end($rows)['id'] : null];
    }

    public static function retry(ActorContext $actor, array $input): array
    {
        return Access::transaction(static function () use ($actor, $input): array {
            self::mutable($actor, $input['business_id']);
            $row = Database::query(
                'SELECT d.*,o.status,s.is_active FROM webhook_deliveries d JOIN delivery_outbox o ON o.id=d.outbox_id JOIN webhook_subscriptions s ON s.id=d.subscription_id WHERE d.id=? AND d.tenant_id=? FOR UPDATE',
                [$input['delivery_id'], $input['business_id']]
            )[0] ?? throw new OperationException('not_found', 404);
            if (
                $row['status'] !== 'failed'
                || !$row['is_active']
                || $row['payload_cipher'] === null
                || (int) $row['manual_retries'] >= 3
            ) {
                throw new OperationException('delivery_not_retryable', 409);
            }

            Database::execute('UPDATE webhook_deliveries SET manual_retries=manual_retries+1 WHERE id=?', [$row['id']]);
            Database::execute(
                "UPDATE delivery_outbox SET status='pending',attempts=0,available_at=?,last_error_code=NULL WHERE id=?",
                [gmdate('Y-m-d H:i:s'), $row['outbox_id']]
            );
            self::audit($actor, 'webhook.delivery_retried', $row['id'], $input['business_id']);
            return ['delivery_id' => $row['id'], 'status' => 'queued'];
        });
    }

    public static function cleanup(): void
    {
        Database::execute(
            'UPDATE webhook_subscriptions SET previous_secret_cipher=NULL,previous_secret_until=NULL WHERE previous_secret_until < ?',
            [gmdate('Y-m-d H:i:s')]
        );
        Database::execute(
            'UPDATE webhook_deliveries SET payload_cipher=NULL WHERE created_at < ?',
            [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]
        );
        Database::execute(
            'DELETE FROM webhook_deliveries WHERE created_at < ?',
            [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]
        );
    }

    private static function owned(ActorContext $actor, string $businessId, string $id): array
    {
        Access::tenant($actor, $businessId, 'owner');
        return Database::query('SELECT * FROM webhook_subscriptions WHERE id=? AND tenant_id=?', [$id, $businessId])[0]
            ?? throw new OperationException('not_found', 404);
    }

    private static function mutable(ActorContext $actor, string $businessId): void
    {
        if (DemoMode::isActive()) {
            throw new OperationException('demo_restricted', 403);
        }

        Access::tenant($actor, $businessId, 'owner', true);
    }

    private static function validate(array $input): void
    {
        if (isset($input['endpoint'])) {
            try {
                WebhookHttp::validateUrl($input['endpoint']);
            } catch (\InvalidArgumentException) {
                throw new OperationException(
                    'validation_failed',
                    422,
                    ['endpoint' => ['public_https_domain_required']]
                );
            }
        }

        if (isset($input['name']) && trim($input['name']) === '') {
            throw new OperationException('validation_failed', 422, ['name' => ['required']]);
        }

        if (
            isset($input['event_types'])
            && ($input['event_types'] === [] || array_diff($input['event_types'], self::EVENTS) !== [])
        ) {
            throw new OperationException('validation_failed', 422, ['event_types' => ['invalid_choice']]);
        }
    }

    private static function project(array $row): array
    {
        return [
            'id' => $row['id'],
            'business_id' => $row['tenant_id'],
            'name' => $row['name'],
            'endpoint' => $row['endpoint'],
            'event_types' => json_decode($row['event_types'], true, flags: JSON_THROW_ON_ERROR),
            'is_active' => (bool) $row['is_active'],
            'version' => self::version($row),
            'secret_available' => $row['secret_revealed_at'] === null,
            'secret_browser_url' => Endpoints::baseUrl() . '/admin/tenants/' . $row['tenant_id'] . '/webhooks/'
                . $row['id'] . '/secret',
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }

    private static function version(array $row): string
    {
        return hash('sha256', $row['id'] . ':' . $row['generation']);
    }

    private static function audit(ActorContext $actor, string $event, string $id, string $businessId): void
    {
        AuditLog::log($event, 'webhook', $id, [], $businessId, $actor->auditType(), $actor->principalId);
    }
}
