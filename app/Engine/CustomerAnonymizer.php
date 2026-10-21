<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Customer anonymization engine.
 *
 * Per .ai/23-VoxelBooking-Legal-Logging.md §7 (Anonymization Specification)
 * and PRD §XIX (Privacy Architecture).
 *
 * Anonymizes customer PII while preserving:
 * - Booking structure (dates, services, statuses) for operational history
 * - Consent records (consent_given_at, consent_text_shown) — GDPR Art. 7(1) evidence
 * - Internal notes (operator's operational data, not customer PII)
 *
 * Fields transformed:
 * - customers.name          → "Deleted"
 * - customers.email         → SHA-256(original_email) (for deduplication)
 * - customers.phone         → NULL
 * - customers.notes         → NULL
 * - bookings.notes          → NULL (customer-facing notes)
 * - bookings.custom_field_data → NULL
 * - email_log.to_email      → SHA-256(original_email)
 *
 * Fields preserved:
 * - bookings.internal_notes       (operator data)
 * - bookings.consent_given_at     (legal hold)
 * - bookings.consent_text_shown   (legal hold)
 * - All booking dates, times, services, statuses, sources
 */
final class CustomerAnonymizer
{
    /**
     * Anonymize a single customer and all related data.
     *
     * @param string $customerId The customer ULID
     * @param string $reason     Why the anonymization happened (for audit log)
     *
     * @return array{customer_id: string, bookings_affected: int, emails_affected: int}
     *
     * @throws \RuntimeException if the customer doesn't exist or is already anonymized
     */
    public static function anonymize(string $customerId, string $reason = 'manual', ?\App\Agent\ActorContext $actor = null, ?int $retentionMonths = null): array
    {
        $initial = self::loadCustomer($customerId);
        if ($initial === null) throw new \RuntimeException('Customer not found');
        return \App\Application\Shared\Access::transaction(static function () use ($customerId, $initial, $reason, $actor, $retentionMonths): array {
            $tenant = Database::query('SELECT * FROM tenants WHERE id = ? FOR UPDATE', [$initial['tenant_id']])[0] ?? null;
            $customer = Database::query('SELECT * FROM customers WHERE id = ? AND tenant_id = ? FOR UPDATE', [$customerId, $initial['tenant_id']])[0] ?? null;
            if ($tenant === null || $customer === null) throw new \RuntimeException('Customer not found');
            if ((bool)$customer['is_anonymized']) throw new \RuntimeException('Customer is already anonymized');
            if ($reason === 'retention_cron') {
                $months = $retentionMonths ?? (int)$tenant['data_retention_months'];
                $eligible = $months > 0 && Database::query("SELECT id FROM customers WHERE id = ? AND last_booking_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MONTH) AND NOT EXISTS (SELECT 1 FROM bookings WHERE customer_id = ? AND status NOT IN ('completed','cancelled','no_show'))", [$customerId, $months, $customerId]);
                if (!$eligible) throw new \RuntimeException('Customer no longer eligible for retention');
            }
            $emailHash = hash('sha256', mb_strtolower(trim($customer['email'])));
            Database::execute("UPDATE customers SET name = 'Deleted', email = ?, phone = NULL, notes = NULL, is_anonymized = 1, anonymized_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ? AND tenant_id = ?", [$emailHash, $customerId, $tenant['id']]);
            $bookings = Database::execute('UPDATE bookings SET notes = NULL, custom_field_data = NULL, customer_name_snapshot = NULL, customer_email_snapshot = NULL, customer_phone_snapshot = NULL, updated_at = UTC_TIMESTAMP() WHERE customer_id = ? AND tenant_id = ?', [$customerId,$tenant['id']]);
            $emails = Database::execute("UPDATE email_log SET to_email = ?, subject = 'Anonymized communication', error = NULL WHERE tenant_id = ? AND (to_email = ? OR booking_id IN (SELECT id FROM bookings WHERE customer_id = ? AND tenant_id = ?))", [$emailHash,$tenant['id'],$customer['email'],$customerId,$tenant['id']]);
            $capabilities = Database::execute('UPDATE customer_capabilities SET revoked_at = UTC_TIMESTAMP() WHERE customer_id = ? AND tenant_id = ? AND revoked_at IS NULL', [$customerId,$tenant['id']]);
            $connections = Database::execute("UPDATE agent_connections SET revoked_at = UTC_TIMESTAMP() WHERE principal_type = 'customer' AND principal_id = ? AND revoked_at IS NULL", [$customerId]);
            foreach (['agent_credentials','oauth_auth_codes','oauth_access_tokens','oauth_refresh_tokens'] as $table) {
                Database::execute("UPDATE $table SET revoked_at = UTC_TIMESTAMP() WHERE connection_id IN (SELECT id FROM agent_connections WHERE principal_type = 'customer' AND principal_id = ?) AND revoked_at IS NULL", [$customerId]);
            }
            // Payloads may contain this customer's contact data even under staff credentials.
            // Preserve only the current consumed confirmation's selectors for exact retry.
            // The original review contains personal data and is always erased.
            $retainedConfirmations = [];
            if ($actor !== null && Outbox::currentReceiptId() !== null) {
                $cipher = new \App\Agent\PayloadCipher();
                $candidates = Database::query("SELECT id,principal_type,principal_id,connection_id,payload_cipher FROM agent_previews WHERE tenant_id=? AND operation_id='deletion_confirm' AND consumed_at IS NOT NULL", [$tenant['id']]);
                foreach ($candidates as $preview) {
                    if ($preview['principal_type'] !== $actor->principalType || $preview['principal_id'] !== $actor->principalId || $preview['connection_id'] !== $actor->connectionId) continue;
                    $arguments = $cipher->decrypt($preview['payload_cipher']);
                    if (($arguments['customer_id'] ?? null) !== $customerId || ($arguments['business_id'] ?? null) !== $tenant['id']
                        || array_diff(array_keys($arguments), ['business_id','customer_id']) !== []) continue;
                    Database::execute('UPDATE agent_previews SET review_cipher=? WHERE id=?', [$cipher->encrypt([]), $preview['id']]);
                    $retainedConfirmations[] = $preview['id'];
                }
            }
            $previews = Database::execute('DELETE FROM agent_previews WHERE tenant_id = ?'
                . ($retainedConfirmations ? ' AND id NOT IN ('.implode(',', array_fill(0,count($retainedConfirmations),'?')).')' : ''),
                [$tenant['id'], ...$retainedConfirmations]);
            $receipts = Database::execute('UPDATE agent_operation_receipts SET result_cipher = NULL WHERE tenant_id = ? AND result_cipher IS NOT NULL', [$tenant['id']]);
            $webhooks = Database::execute('UPDATE webhook_deliveries SET payload_cipher=NULL WHERE tenant_id=? AND payload_cipher IS NOT NULL',[$tenant['id']]);
            $webhookIntents = Database::execute("UPDATE delivery_outbox o JOIN webhook_deliveries d ON d.outbox_id=o.id SET o.status='delivered',o.delivered_at=UTC_TIMESTAMP(),o.last_error_code='privacy_erasure' WHERE d.tenant_id=? AND o.status IN ('pending','failed')",[$tenant['id']]);
            $result = ['customer_id'=>$customerId,'anonymized'=>true,'bookings_affected'=>$bookings,'emails_affected'=>$emails,'capabilities_revoked'=>$capabilities,'connections_revoked'=>$connections,'previews_removed'=>$previews,'receipt_payloads_removed'=>$receipts,'webhook_payloads_removed'=>$webhooks,'webhook_intents_suppressed'=>$webhookIntents];
            Database::execute('INSERT INTO audit_log (id,tenant_id,actor_type,actor_id,action,entity_type,entity_id,details,request_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())', [Ulid::generate(),$tenant['id'],$actor?->auditType() ?? 'system',$actor?->principalId,'customer.anonymized','customer',$customerId,json_encode(['reason'=>in_array($reason,['manual','retention_cron','operator_review'],true)?$reason:'manual','effects'=>$result],JSON_THROW_ON_ERROR),AuditLog::getRequestId() ?: Ulid::generate()]);
            return $result;
        });
    }

    /**
     * Batch anonymize customers for a tenant based on retention policy.
     *
     * Selects customers whose most recent booking is older than retention period
     * and whose bookings are all in a terminal state (completed, cancelled, no_show).
     *
     * @param string $tenantId           Tenant ULID
     * @param int    $retentionMonths    Months after which data is anonymized
     * @param int    $batchSize          Max customers to process per run (prevents timeout)
     *
     * @return array{processed: int, skipped: int, errors: int}
     */
    public static function processRetention(
        string $tenantId,
        int $retentionMonths = 24,
        int $batchSize = 50,
    ): array {
        if ($retentionMonths <= 0) {
            return ['processed' => 0, 'skipped' => 0, 'errors' => 0];
        }

        // Find customers eligible for anonymization:
        // - Not already anonymized
        // - Last booking is older than retention period
        // - All bookings are in terminal state
        $candidates = Database::query(
            'SELECT c.`id`, c.`email`
             FROM `customers` c
             WHERE c.`tenant_id` = ?
               AND c.`is_anonymized` = 0
               AND c.`last_booking_at` IS NOT NULL
               AND c.`last_booking_at` < DATE_SUB(NOW(), INTERVAL ? MONTH)
               AND NOT EXISTS (
                   SELECT 1 FROM `bookings` b
                   WHERE b.`customer_id` = c.`id`
                     AND b.`status` NOT IN (\'completed\', \'cancelled\', \'no_show\')
               )
             ORDER BY c.`last_booking_at` ASC
             LIMIT ?',
            [$tenantId, $retentionMonths, $batchSize]
        );

        $processed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($candidates as $candidate) {
            try {
                self::anonymize($candidate['id'], 'retention_cron', retentionMonths: $retentionMonths);
                $processed++;
            } catch (\RuntimeException $e) {
                if (str_contains($e->getMessage(), 'already anonymized')) {
                    $skipped++;
                } else {
                    $errors++;
                    Logger::warning('Retention anonymization failed', [
                        'customer_id' => $candidate['id'],
                        'error'       => $e->getMessage(),
                    ]);
                }
            }
        }

        return [
            'processed' => $processed,
            'skipped'   => $skipped,
            'errors'    => $errors,
        ];
    }

    /**
     * Load a customer record by ID.
     *
     * @return array<string, mixed>|null
     */
    private static function loadCustomer(string $customerId): ?array
    {
        $rows = Database::query(
            'SELECT * FROM `customers` WHERE `id` = ? LIMIT 1',
            [$customerId]
        );

        return $rows[0] ?? null;
    }
}
