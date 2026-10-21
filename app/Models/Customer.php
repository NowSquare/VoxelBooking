<?php

declare(strict_types=1);

namespace App\Models;

use App\Engine\Database;

/**
 * Customer data model.
 *
 * No ORM — raw PDO queries against the `customers` table.
 * All methods return arrays for consistency with the codebase.
 *
 * Customers are tenant-scoped, created when bookings are placed,
 * and support anonymization per GDPR Art. 17.
 */
final class Customer
{
    /**
     * Find a customer by ID.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $id): ?array
    {
        $rows = Database::query(
            'SELECT * FROM `customers` WHERE `id` = ? LIMIT 1',
            [$id]
        );

        return $rows[0] ?? null;
    }

    /**
     * Get paginated customers for a tenant, ordered by most recent first.
     *
     * Non-anonymized customers only (anonymized records are hidden from
     * the operational list — operators see them in the deletion queue).
     *
     * @return list<array<string, mixed>>
     */
    public static function forTenant(
        string $tenantId,
        ?string $search,
        int $limit,
        int $offset,
    ): array {
        [$where, $bindings] = self::buildFilters($tenantId, $search);

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        return Database::query(
            "SELECT `id`, `name`, `email`, `phone`, `booking_count`,
                    `last_booking_at`, `is_anonymized`, `created_at`
             FROM `customers`
             {$whereClause}
             ORDER BY `last_booking_at` DESC, `created_at` DESC, `id` DESC
             LIMIT ? OFFSET ?",
            array_merge($bindings, [$limit, $offset])
        );
    }

    /**
     * Get all customers for a tenant for CSV export (no pagination, capped at 10 000).
     *
     * @return list<array<string, mixed>>
     */
    public static function forTenantExport(string $tenantId, ?string $search): array
    {
        [$where, $bindings] = self::buildFilters($tenantId, $search);

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        return Database::query(
            "SELECT `name`, `email`, `phone`, `booking_count`,
                    `last_booking_at`, `created_at`
             FROM `customers`
             {$whereClause}
             ORDER BY `last_booking_at` DESC, `created_at` DESC
             LIMIT 10000",
            $bindings
        );
    }

    /**
     * Count customers for a tenant with optional search.
     */
    public static function countForTenant(string $tenantId, ?string $search): int
    {
        [$where, $bindings] = self::buildFilters($tenantId, $search);

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $rows = Database::query(
            "SELECT COUNT(*) as cnt FROM `customers` {$whereClause}",
            $bindings
        );

        return (int) ($rows[0]['cnt'] ?? 0);
    }

    /**
     * Get recent bookings for a customer (for the detail view).
     *
     * @return list<array<string, mixed>>
     */
    public static function bookingsForCustomer(string $customerId, int $limit = 25): array
    {
        return Database::query(
            "SELECT b.`id`, b.`start_datetime`, b.`end_datetime`, b.`status`,
                    b.`booking_pattern`, b.`created_at`,
                    s.`name` AS `service_name`, s.`price` AS `service_price`,
                    r.`name` AS `resource_name`,
                    ev.`name` AS `event_name`
             FROM `bookings` b
             LEFT JOIN `services` s ON s.`id` = b.`service_id`
             LEFT JOIN `resources` r ON r.`id` = b.`resource_id`
             LEFT JOIN `events` ev ON ev.`id` = b.`event_id`
             WHERE b.`customer_id` = ?
             ORDER BY b.`start_datetime` DESC
             LIMIT ?",
            [$customerId, $limit]
        );
    }

    /**
     * Build filter clauses for customer queries.
     *
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private static function buildFilters(string $tenantId, ?string $search): array
    {
        $where = ['`tenant_id` = ?', '`is_anonymized` = 0'];
        $bindings = [$tenantId];

        if ($search !== null && $search !== '') {
            $where[] = '(`name` LIKE ? OR `email` LIKE ? OR `phone` LIKE ?)';
            $term = '%' . $search . '%';
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }

        return [$where, $bindings];
    }
}
