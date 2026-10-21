<?php

declare(strict_types=1);

namespace App\Engine;

/** Generic recovery requests enqueue record references, never reusable credentials. */
final class CustomerCapabilityRecovery
{
    public static function allow(string $ip, string $slug, string $email): bool
    {
        $ipAllowed = self::consume('ip:' . $ip, 10, 900);
        $recipientAllowed = self::consume('recipient:' . $slug . ':' . $email, 3, 3600);
        return $ipAllowed && $recipientAllowed;
    }

    private static function consume(string $key, int $limit, int $window): bool
    {
        $hash = hash('sha256', $key);
        $expires = gmdate('Y-m-d H:i:s', time() + $window);
        Database::execute(
            'INSERT INTO `customer_recovery_limits` (`bucket_hash`, `attempts`, `expires_at`) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE `attempts` = IF(`expires_at` <= UTC_TIMESTAMP(), 1, `attempts` + 1),
             `expires_at` = IF(`expires_at` <= UTC_TIMESTAMP(), ?, `expires_at`)',
            [$hash, $expires, $expires]
        );
        $row = Database::query('SELECT `attempts` FROM `customer_recovery_limits` WHERE `bucket_hash` = ?', [$hash]);
        return (int) ($row[0]['attempts'] ?? PHP_INT_MAX) <= $limit;
    }

    public static function request(string $tenantId, string $purpose, string $subjectId, string $email): void
    {
        $customer = self::customer($tenantId, $purpose, $subjectId);
        if ($customer === null || !hash_equals(mb_strtolower($customer['email']), $email)) {
            return;
        }
        $payload = [
            'tenant_id' => $tenantId,
            'purpose' => $purpose,
            'subject_id' => $subjectId,
            'recipient_hash' => hash('sha256', mb_strtolower($customer['email'])),
        ];
        // The ten-minute bucket in the dedupe key collapses repeated requests into one email.
        Outbox::enqueue(
            'customer.access_recovery',
            $payload,
            $tenantId,
            'customer.access:' . hash('sha256', $tenantId . ':' . $purpose . ':' . $subjectId . ':' . intdiv(time(), 600))
        );
    }

    /** Called by the durable dispatcher. Mint only when an email is about to leave. */
    public static function deliver(array $payload): array
    {
        $tenantId = (string) ($payload['tenant_id'] ?? '');
        $purpose = (string) ($payload['purpose'] ?? '');
        $subjectId = (string) ($payload['subject_id'] ?? '');
        $customer = self::customer($tenantId, $purpose, $subjectId);
        // If the address changed since the request, quietly drop it rather than mail a new recipient.
        if ($customer === null
            || !hash_equals(hash('sha256', mb_strtolower($customer['email'])), (string) ($payload['recipient_hash'] ?? ''))) {
            return ['sent' => true, 'error' => null, 'suppressed' => true];
        }
        $tenant = Database::query(
            'SELECT * FROM `tenants` WHERE `id` = ? AND `status` = ? LIMIT 1',
            [$tenantId, 'active']
        )[0] ?? null;
        if ($tenant === null) {
            return ['sent' => true, 'error' => null, 'suppressed' => true];
        }
        Locale::resolveForBooking($tenant, null);
        $token = $purpose === CustomerCapability::MANAGE
            ? CustomerCapability::issueManage($tenantId, $subjectId)
            : CustomerCapability::issuePrivacy($tenantId, $subjectId);
        return Mailer::sendCustomerAccessLink(
            $customer['email'],
            $tenant,
            app_url(CustomerCapability::link($tenant['slug'], $purpose, $subjectId, $token)),
            $purpose
        );
    }

    private static function customer(string $tenantId, string $purpose, string $subjectId): ?array
    {
        if ($purpose === CustomerCapability::MANAGE) {
            return Database::query(
                'SELECT c.`id`, c.`email` FROM `bookings` b JOIN `customers` c ON c.`id` = b.`customer_id` AND c.`tenant_id` = b.`tenant_id`
                 JOIN `tenants` t ON t.`id` = b.`tenant_id`
                 WHERE b.`id` = ? AND b.`tenant_id` = ? AND c.`is_anonymized` = 0 AND t.`status` = ? LIMIT 1',
                [$subjectId, $tenantId, 'active']
            )[0] ?? null;
        }
        if ($purpose !== CustomerCapability::PRIVACY) {
            return null;
        }
        return Database::query(
            'SELECT c.`id`, c.`email` FROM `customers` c JOIN `tenants` t ON t.`id` = c.`tenant_id`
             WHERE c.`id` = ? AND c.`tenant_id` = ? AND c.`is_anonymized` = 0 AND t.`status` = ? LIMIT 1',
            [$subjectId, $tenantId, 'active']
        )[0] ?? null;
    }
}
