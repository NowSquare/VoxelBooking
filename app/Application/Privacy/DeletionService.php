<?php

declare(strict_types=1);

namespace App\Application\Privacy;

use App\Agent\{ActorContext, CanonicalJson, ConnectionAuthenticator, OperationException, PayloadCipher};
use App\Application\Shared\Access;
use App\Engine\{AuditLog, CustomerAnonymizer, Database, DemoMode, Ulid};

final class DeletionService
{
    private static function authorize(ActorContext $actor, string $scope): ActorContext
    {
        $actor = \App\Application\Bookings\BookingCommands::currentActor($actor);
        if (
            $actor->role !== 'operator'
            || !in_array($scope, $actor->scopes, true)
            || !Database::query('SELECT id FROM operators WHERE id=?', [$actor->principalId])
        ) {
            throw new OperationException('forbidden', 403);
        }
        return $actor;
    }

    public static function queue(ActorContext $actor, array $input): array
    {
        $actor = self::authorize($actor, 'privacy-review:read');
        $business = $actor->business($input['business_id'] ?? null);
        $state = $input['state'] ?? 'pending';
        if (!in_array($state, ['pending', 'processed'], true)) {
            throw new OperationException('validation_failed', 422, ['state' => 'invalid']);
        }
        $limit = max(1, min(100, (int) ($input['limit'] ?? 50)));
        $binding = CanonicalJson::hash([
            $actor->principalType,
            $actor->principalId,
            $actor->connectionId,
            $actor->credentialId,
            $business,
            $state
        ]);
        $snapshot = Database::query(
            'SELECT UTC_TIMESTAMP(6) AS stamp,MAX(id) AS id FROM customers WHERE tenant_id=?',
            [$business]
        )[0];
        $after = null;
        if (!empty($input['cursor'])) {
            try {
                $cursor = (new PayloadCipher())->decrypt($input['cursor']);
            } catch (\Throwable) {
                throw new OperationException('invalid_cursor', 422);
            }

            if (($cursor['binding'] ?? null) !== $binding || ($cursor['expires'] ?? 0) < time()) {
                throw new OperationException('invalid_cursor', 422);
            }
            $snapshot = $cursor['snapshot'];
            $after = $cursor['after'];
        }

        $where = [
            'c.tenant_id=?',
            'c.deletion_requested_at IS NOT NULL',
            'c.is_anonymized=?',
            'c.id<=?',
            'c.deletion_requested_at<=?'
        ];
        $args = [$business, $state === 'processed' ? 1 : 0, $snapshot['id'] ?? '', $snapshot['stamp']];
        if ($after !== null) {
            $where[] = '(c.deletion_requested_at>? OR (c.deletion_requested_at=? AND c.id>?))';
            array_push($args, $after['stamp'], $after['stamp'], $after['id']);
        }

        $rows = Database::query(
            'SELECT c.id,c.tenant_id AS business_id,c.name,c.email,c.deletion_requested_at,c.anonymized_at,(SELECT COUNT(*) FROM bookings b WHERE b.customer_id=c.id AND b.tenant_id=c.tenant_id) AS booking_count FROM customers c WHERE '
                . implode(' AND ', $where) . ' ORDER BY c.deletion_requested_at,c.id LIMIT ?',
            [...$args, $limit + 1]
        );
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        foreach ($rows as &$row) {
            $row['booking_count'] = (int) $row['booking_count'];
        }
        unset($row);
        $last = $rows ? end($rows) : null;
        $next = $more
            ? (new PayloadCipher())->encrypt([
                'binding' => $binding,
                'snapshot' => $snapshot,
                'after' => ['stamp' => $last['deletion_requested_at'], 'id' => $last['id']],
                'expires' => time() + 3600
            ])
            : null;
        return ['requests' => $rows, 'next_cursor' => $next];
    }

    private static function customer(ActorContext $actor, array $input, bool $lock = false): array
    {
        $business = $actor->business($input['business_id'] ?? null);
        if (!Database::query('SELECT id FROM tenants WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [$business])) {
            throw new OperationException('not_found', 404);
        }
        return Database::query(
            'SELECT * FROM customers WHERE id=? AND tenant_id=?' . ($lock ? ' FOR UPDATE' : ''),
            [$input['customer_id'] ?? '', $business]
        )[0] ?? throw new OperationException('not_found', 404);
    }

    public static function preview(ActorContext $actor, array $input): array
    {
        $actor = self::authorize($actor, 'privacy-review:read');
        $c = self::customer($actor, $input);
        if ((bool) $c['is_anonymized'] || $c['deletion_requested_at'] === null) {
            throw new OperationException('request_not_pending', 409);
        }
        $bookings = Database::query(
            'SELECT id,status,notes,custom_field_data,customer_name_snapshot,customer_email_snapshot,customer_phone_snapshot,consent_given_at,consent_text_shown,internal_notes,updated_at FROM bookings WHERE customer_id=? AND tenant_id=? ORDER BY id',
            [$c['id'], $c['tenant_id']]
        );
        $emails = Database::query(
            'SELECT id,to_email,subject,error FROM email_log WHERE tenant_id=? AND (to_email=? OR booking_id IN (SELECT id FROM bookings WHERE customer_id=? AND tenant_id=?)) ORDER BY id',
            [$c['tenant_id'], $c['email'], $c['id'], $c['tenant_id']]
        );
        $capabilities = Database::query(
            'SELECT id,purpose,subject_id FROM customer_capabilities WHERE customer_id=? AND tenant_id=? AND revoked_at IS NULL ORDER BY id',
            [$c['id'], $c['tenant_id']]
        );
        $connections = Database::query(
            "SELECT id FROM agent_connections WHERE principal_type='customer' AND principal_id=? AND revoked_at IS NULL ORDER BY id",
            [$c['id']]
        );
        $previews = Database::query(
            "SELECT id,request_hash FROM agent_previews WHERE tenant_id=? AND operation_id NOT IN ('deletion_confirm','deletion_dismiss') ORDER BY id",
            [$c['tenant_id']]
        );
        $receipts = Database::query(
            'SELECT id,result_cipher FROM agent_operation_receipts WHERE tenant_id=? AND result_cipher IS NOT NULL ORDER BY id',
            [$c['tenant_id']]
        );
        $webhooks = Database::query(
            'SELECT d.id,d.payload_cipher,o.status FROM webhook_deliveries d LEFT JOIN delivery_outbox o ON o.id=d.outbox_id WHERE d.tenant_id=? AND d.payload_cipher IS NOT NULL ORDER BY d.id',
            [$c['tenant_id']]
        );
        $effects = [
            'customers' => 1,
            'bookings' => count($bookings),
            'email_logs' => count($emails),
            'customer_capabilities' => count($capabilities),
            'customer_connections' => count($connections),
            'other_business_reviews' => count($previews),
            'business_receipt_payloads' => count($receipts),
            'business_webhook_payloads' => count($webhooks),
            'active_bookings' => count(array_filter(
                $bookings,
                static fn($b) => in_array($b['status'], ['pending', 'confirmed', 'waitlisted'], true)
            ))
        ];
        return [
            'customer_id' => $c['id'],
            'business_id' => $c['tenant_id'],
            'customer_name' => $c['name'],
            'customer_email' => $c['email'],
            'requested_at' => $c['deletion_requested_at'],
            'effects' => $effects,
            'retained' => [
                'booking_structure',
                'consent_evidence',
                'internal_operational_notes',
                'audit_metadata',
                'receipt_dedupe_tombstones'
            ],
            'version' => CanonicalJson::hash([
                $c,
                $bookings,
                $emails,
                $capabilities,
                $connections,
                $previews,
                $receipts,
                $webhooks
            ])
        ];
    }

    public static function confirm(ActorContext $actor, array $input): array
    {
        $actor = self::authorize($actor, 'privacy-review:manage');
        if (DemoMode::isActive()) {
            throw new OperationException('demo_restricted', 403);
        }
        return Access::transaction(static function () use ($actor, $input) {
            $c = self::customer($actor, $input, true);
            $actor = self::authorize($actor, 'privacy-review:manage');
            $actor->business($c['tenant_id']);
            $review = self::preview($actor, $input);
            if (isset($input['expected_version']) && !hash_equals($review['version'], $input['expected_version'])) {
                throw new OperationException('preview_changed', 409);
            }
            return CustomerAnonymizer::anonymize($c['id'], 'operator_review', $actor);
        });
    }

    public static function dismiss(ActorContext $actor, array $input): array
    {
        $actor = self::authorize($actor, 'privacy-review:manage');
        if (DemoMode::isActive()) {
            throw new OperationException('demo_restricted', 403);
        }
        return Access::transaction(static function () use ($actor, $input) {
            $reason = trim((string) ($input['reason'] ?? ''));
            if (mb_strlen($reason) > 1000) {
                throw new OperationException('validation_failed', 422, ['reason' => 'too_long']);
            }
            $c = self::customer($actor, $input, true);
            $actor = self::authorize($actor, 'privacy-review:manage');
            $actor->business($c['tenant_id']);
            if ((bool) $c['is_anonymized']) {
                throw new OperationException('request_not_pending', 409);
            }
            if ($c['deletion_requested_at'] === null) {
                return ['customer_id' => $c['id'], 'dismissed' => true];
            }
            if (
                isset($input['expected_version'])
                && !hash_equals(self::preview($actor, $input)['version'], $input['expected_version'])
            ) {
                throw new OperationException('preview_changed', 409);
            }
            Database::execute(
                'UPDATE customers SET deletion_requested_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND tenant_id=?',
                [$c['id'], $c['tenant_id']]
            );
            Database::execute(
                'INSERT INTO audit_log(id,tenant_id,actor_type,actor_id,action,entity_type,entity_id,details,request_id,created_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())',
                [
                    Ulid::generate(),
                    $c['tenant_id'],
                    'operator',
                    $actor->principalId,
                    'privacy.deletion_dismissed',
                    'customer',
                    $c['id'],
                    json_encode([
                        'requested_at' => $c['deletion_requested_at'],
                        'connection_id' => $actor->connectionId,
                        'reason_present' => $reason !== '',
                        'reason_digest' => $reason !== '' ? hash('sha256', $reason) : null
                    ]),
                    AuditLog::getRequestId() ?: Ulid::generate()
                ]
            );
            return ['customer_id' => $c['id'], 'dismissed' => true];
        });
    }
}
