<?php

declare(strict_types=1);

namespace App\Models;

use App\Engine\Database;

/**
 * Booking data model.
 *
 * Provides query methods for the admin bookings list:
 * - cross-tenant views (operator)
 * - per-tenant views (business user + operator)
 * - status updates with validation
 *
 * All queries join tenant name for the "Tenant" column where needed.
 */
final class Booking
{
    /** Valid booking statuses */
    private const VALID_STATUSES = [
        'pending', 'confirmed', 'cancelled', 'completed', 'no_show', 'rescheduled', 'waitlisted',
    ];

    /** Allowlisted sort columns (prevents SQL injection) */
    private const SORT_COLUMNS = [
        'start_datetime' => 'b.`start_datetime`',
        'customer'       => 'c.`name`',
        'service'        => 's.`name`',
        'status'         => 'b.`status`',
        'tenant'         => 't.`name`',
    ];

    /**
     * Find a booking by ID.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $id): ?array
    {
        $rows = Database::query(
            "SELECT b.*, t.`name` AS `tenant_name`, t.`slug` AS `tenant_slug`,
                    c.`name` AS `customer_name`, c.`email` AS `customer_email`,
                    s.`name` AS `service_name`,
                    st.`name` AS `staff_name`,
                    r.`name` AS `resource_name`,
                    ev.`name` AS `event_name`
             FROM `bookings` b
             LEFT JOIN `tenants` t ON t.`id` = b.`tenant_id`
             LEFT JOIN `customers` c ON c.`id` = b.`customer_id`
             LEFT JOIN `services` s ON s.`id` = b.`service_id`
             LEFT JOIN `staff` st ON st.`id` = b.`staff_id`
             LEFT JOIN `resources` r ON r.`id` = b.`resource_id`
             LEFT JOIN `events` ev ON ev.`id` = b.`event_id`
             WHERE b.`id` = ?
             LIMIT 1",
            [$id]
        );

        return $rows[0] ?? null;
    }

    /**
     * Get all bookings cross-tenant (operator view).
     *
     * @return list<array<string, mixed>>
     */
    public static function all(
        ?string $status,
        ?string $from,
        ?string $to,
        ?string $search,
        int $limit,
        int $offset,
        string $sort = 'start_datetime',
        string $direction = 'DESC',
    ): array {
        [$where, $bindings] = self::buildFilters($status, $from, $to, $search);

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        [$orderCol, $orderDir] = self::resolveSort($sort, $direction);

        return Database::query(
            "SELECT b.*, t.`name` AS `tenant_name`, t.`slug` AS `tenant_slug`,
                    c.`name` AS `customer_name`, c.`email` AS `customer_email`,
                    s.`name` AS `service_name`,
                    r.`name` AS `resource_name`,
                    ev.`name` AS `event_name`
             FROM `bookings` b
             LEFT JOIN `tenants` t ON t.`id` = b.`tenant_id`
             LEFT JOIN `customers` c ON c.`id` = b.`customer_id`
             LEFT JOIN `services` s ON s.`id` = b.`service_id`
             LEFT JOIN `resources` r ON r.`id` = b.`resource_id`
             LEFT JOIN `events` ev ON ev.`id` = b.`event_id`
             {$whereClause}
             ORDER BY {$orderCol} {$orderDir}
             LIMIT ? OFFSET ?",
            array_merge($bindings, [$limit, $offset])
        );
    }

    /**
     * Get all bookings for CSV export (no pagination, capped at 10 000 rows).
     *
     * Reuses the same filters/sorting as all() but removes LIMIT/OFFSET
     * pagination so the full filtered dataset can be streamed as CSV.
     *
     * @return list<array<string, mixed>>
     */
    public static function allForExport(
        ?string $status,
        ?string $from,
        ?string $to,
        ?string $search,
        string $sort = 'start_datetime',
        string $direction = 'DESC',
    ): array {
        [$where, $bindings] = self::buildFilters($status, $from, $to, $search);

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        [$orderCol, $orderDir] = self::resolveSort($sort, $direction);

        return Database::query(
            "SELECT b.*, t.`name` AS `tenant_name`, t.`slug` AS `tenant_slug`,
                    c.`name` AS `customer_name`, c.`email` AS `customer_email`,
                    s.`name` AS `service_name`,
                    r.`name` AS `resource_name`,
                    ev.`name` AS `event_name`
             FROM `bookings` b
             LEFT JOIN `tenants` t ON t.`id` = b.`tenant_id`
             LEFT JOIN `customers` c ON c.`id` = b.`customer_id`
             LEFT JOIN `services` s ON s.`id` = b.`service_id`
             LEFT JOIN `resources` r ON r.`id` = b.`resource_id`
             LEFT JOIN `events` ev ON ev.`id` = b.`event_id`
             {$whereClause}
             ORDER BY {$orderCol} {$orderDir}
             LIMIT 10000",
            $bindings
        );
    }

    /**
     * Get bookings for a tenant for CSV export (no pagination, capped at 10 000 rows).
     *
     * @return list<array<string, mixed>>
     */
    public static function forTenantExport(
        string $tenantId,
        ?string $status,
        ?string $from,
        ?string $to,
        string $sort = 'start_datetime',
        string $direction = 'DESC',
    ): array {
        [$where, $bindings] = self::buildFilters($status, $from, $to);
        $where[] = 'b.`tenant_id` = ?';
        $bindings[] = $tenantId;

        $whereClause = 'WHERE ' . implode(' AND ', $where);
        [$orderCol, $orderDir] = self::resolveSort($sort, $direction);

        return Database::query(
            "SELECT b.*, t.`name` AS `tenant_name`, t.`slug` AS `tenant_slug`,
                    c.`name` AS `customer_name`, c.`email` AS `customer_email`,
                    s.`name` AS `service_name`,
                    r.`name` AS `resource_name`,
                    ev.`name` AS `event_name`
             FROM `bookings` b
             LEFT JOIN `tenants` t ON t.`id` = b.`tenant_id`
             LEFT JOIN `customers` c ON c.`id` = b.`customer_id`
             LEFT JOIN `services` s ON s.`id` = b.`service_id`
             LEFT JOIN `resources` r ON r.`id` = b.`resource_id`
             LEFT JOIN `events` ev ON ev.`id` = b.`event_id`
             {$whereClause}
             ORDER BY {$orderCol} {$orderDir}
             LIMIT 10000",
            $bindings
        );
    }

    /**
     * Count all bookings cross-tenant, with optional filters.
     */
    public static function countAll(
        ?string $status,
        ?string $from,
        ?string $to,
        ?string $search,
    ): int {
        [$where, $bindings] = self::buildFilters($status, $from, $to, $search);

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $rows = Database::query(
            "SELECT COUNT(*) as cnt FROM `bookings` b {$whereClause}",
            $bindings
        );

        return (int) ($rows[0]['cnt'] ?? 0);
    }

    /**
     * Get bookings for a specific tenant.
     *
     * @return list<array<string, mixed>>
     */
    public static function forTenant(
        string $tenantId,
        ?string $status,
        ?string $from,
        ?string $to,
        int $limit,
        int $offset,
        string $sort = 'start_datetime',
        string $direction = 'DESC',
    ): array {
        [$where, $bindings] = self::buildFilters($status, $from, $to);
        $where[] = 'b.`tenant_id` = ?';
        $bindings[] = $tenantId;

        $whereClause = 'WHERE ' . implode(' AND ', $where);
        [$orderCol, $orderDir] = self::resolveSort($sort, $direction);

        return Database::query(
            "SELECT b.*, t.`name` AS `tenant_name`, t.`slug` AS `tenant_slug`,
                    c.`name` AS `customer_name`, c.`email` AS `customer_email`,
                    s.`name` AS `service_name`,
                    r.`name` AS `resource_name`,
                    ev.`name` AS `event_name`
             FROM `bookings` b
             LEFT JOIN `tenants` t ON t.`id` = b.`tenant_id`
             LEFT JOIN `customers` c ON c.`id` = b.`customer_id`
             LEFT JOIN `services` s ON s.`id` = b.`service_id`
             LEFT JOIN `resources` r ON r.`id` = b.`resource_id`
             LEFT JOIN `events` ev ON ev.`id` = b.`event_id`
             {$whereClause}
             ORDER BY {$orderCol} {$orderDir}
             LIMIT ? OFFSET ?",
            array_merge($bindings, [$limit, $offset])
        );
    }

    /**
     * Get nearest upcoming bookings for a tenant (ASC order).
     *
     * @return list<array<string, mixed>>
     */
    public static function forTenantUpcoming(
        string $tenantId,
        string $afterDatetime,
        int $limit = 5,
    ): array {
        return Database::query(
            "SELECT b.*, t.`name` AS `tenant_name`, t.`slug` AS `tenant_slug`,
                    c.`name` AS `customer_name`, c.`email` AS `customer_email`,
                    s.`name` AS `service_name`,
                    r.`name` AS `resource_name`,
                    ev.`name` AS `event_name`
             FROM `bookings` b
             LEFT JOIN `tenants` t ON t.`id` = b.`tenant_id`
             LEFT JOIN `customers` c ON c.`id` = b.`customer_id`
             LEFT JOIN `services` s ON s.`id` = b.`service_id`
             LEFT JOIN `resources` r ON r.`id` = b.`resource_id`
             LEFT JOIN `events` ev ON ev.`id` = b.`event_id`
             WHERE b.`tenant_id` = ? AND b.`status` = 'confirmed'
                   AND b.`start_datetime` >= ?
             ORDER BY b.`start_datetime` ASC
             LIMIT ?",
            [$tenantId, $afterDatetime, $limit]
        );
    }

    /**
     * Get nearest upcoming bookings across all tenants (operator view).
     * The threshold is an instant in the application timezone; storage is in
     * each tenant's wall time. Bound each timezone group before merging by instant.
     *
     * @return list<array<string, mixed>>
     */
    public static function allUpcoming(string $afterDatetime, int $limit = 10): array
    {
        if ($limit <= 0) {
            return [];
        }
        $after = new \DateTimeImmutable($afterDatetime);
        $upcoming = [];
        foreach (self::upcomingTimezones() as $timezoneName => $timezone) {
            $rows = Database::query(
                "SELECT b.*, t.`name` AS `tenant_name`, t.`slug` AS `tenant_slug`,
                    t.`timezone` AS `tenant_timezone`,
                    c.`name` AS `customer_name`, c.`email` AS `customer_email`,
                    s.`name` AS `service_name`,
                    r.`name` AS `resource_name`,
                    ev.`name` AS `event_name`
             FROM `bookings` b
             LEFT JOIN `tenants` t ON t.`id` = b.`tenant_id`
             LEFT JOIN `customers` c ON c.`id` = b.`customer_id`
             LEFT JOIN `services` s ON s.`id` = b.`service_id`
             LEFT JOIN `resources` r ON r.`id` = b.`resource_id`
             LEFT JOIN `events` ev ON ev.`id` = b.`event_id`
             WHERE b.`status` = 'confirmed'
                   AND COALESCE(NULLIF(t.`timezone`, ''), 'UTC') = ?
                   AND b.`start_datetime` >= ?
             ORDER BY b.`start_datetime` ASC, b.`id` ASC
             LIMIT ?",
                [$timezoneName, $after->setTimezone($timezone)->format('Y-m-d H:i:s'), $limit]
            );
            foreach ($rows as $row) {
                $upcoming[] = ['booking' => $row, 'instant' => (new \DateTimeImmutable($row['start_datetime'], $timezone))->getTimestamp()];
            }
        }
        usort($upcoming, static fn(array $a, array $b): int =>
            ($a['instant'] <=> $b['instant']) ?: strcmp($a['booking']['id'], $b['booking']['id'])
        );
        return array_column(array_slice($upcoming, 0, $limit), 'booking');
    }

    /** Count confirmed starts between two application-timezone instants. */
    public static function countUpcomingBetween(string $afterDatetime, string $beforeDatetime): int
    {
        $after = new \DateTimeImmutable($afterDatetime);
        $before = new \DateTimeImmutable($beforeDatetime);
        $count = 0;
        foreach (self::upcomingTimezones() as $timezoneName => $timezone) {
            $rows = Database::query(
                "SELECT COUNT(*) AS cnt FROM `bookings` b
                 INNER JOIN `tenants` t ON t.`id` = b.`tenant_id`
                 WHERE b.`status` = 'confirmed'
                   AND COALESCE(NULLIF(t.`timezone`, ''), 'UTC') = ?
                   AND b.`start_datetime` BETWEEN ? AND ?",
                [$timezoneName, $after->setTimezone($timezone)->format('Y-m-d H:i:s'), $before->setTimezone($timezone)->format('Y-m-d H:i:s')]
            );
            $count += (int) ($rows[0]['cnt'] ?? 0);
        }
        return $count;
    }

    /** @return array<string, \DateTimeZone> */
    private static function upcomingTimezones(): array
    {
        $timezones = [];
        foreach (Database::query("SELECT DISTINCT COALESCE(NULLIF(`timezone`, ''), 'UTC') AS timezone FROM `tenants`") as $row) {
            $name = $row['timezone'];
            try {
                $timezones[$name] = new \DateTimeZone($name);
            } catch (\Exception) {
                $timezones[$name] = new \DateTimeZone('UTC');
            }
        }
        return $timezones;
    }

    /**
     * Count bookings for a specific tenant.
     */
    public static function countForTenant(
        string $tenantId,
        ?string $status,
        ?string $from,
        ?string $to,
    ): int {
        [$where, $bindings] = self::buildFilters($status, $from, $to);
        $where[] = 'b.`tenant_id` = ?';
        $bindings[] = $tenantId;

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $rows = Database::query(
            "SELECT COUNT(*) as cnt FROM `bookings` b {$whereClause}",
            $bindings
        );

        return (int) ($rows[0]['cnt'] ?? 0);
    }

    /**
     * Update a booking's status.
     *
     * @return bool True if a row was updated.
     */
    public static function updateStatus(string $id, string $status): bool
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            return false;
        }

        $affected = Database::execute(
            "UPDATE `bookings` SET `status` = ?, `updated_at` = NOW() WHERE `id` = ?",
            [$status, $id]
        );

        return $affected > 0;
    }

    /**
     * Get booking counts by status for a specific tenant.
     *
     * @return array<string, int>
     */
    public static function statusCounts(string $tenantId): array
    {
        $rows = Database::query(
            "SELECT `status`, COUNT(*) as cnt FROM `bookings` WHERE `tenant_id` = ? GROUP BY `status`",
            [$tenantId]
        );

        $counts = [];
        foreach (self::VALID_STATUSES as $s) {
            $counts[$s] = 0;
        }
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Build filter clauses for booking queries.
     *
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private static function buildFilters(
        ?string $status = null,
        ?string $from = null,
        ?string $to = null,
        ?string $search = null,
    ): array {
        $where = [];
        $bindings = [];

        if ($status !== null && $status !== '') {
            $where[] = 'b.`status` = ?';
            $bindings[] = $status;
        }

        if ($from !== null && $from !== '') {
            $where[] = 'b.`start_datetime` >= ?';
            $bindings[] = str_contains($from, ':') ? $from : $from . ' 00:00:00';
        }

        if ($to !== null && $to !== '') {
            $where[] = 'b.`start_datetime` <= ?';
            $bindings[] = str_contains($to, ':') ? $to : $to . ' 23:59:59';
        }

        if ($search !== null && $search !== '') {
            // Search in customer name/email via a subquery join
            $where[] = '(b.`id` LIKE ? OR EXISTS (SELECT 1 FROM `customers` cs WHERE cs.`id` = b.`customer_id` AND (cs.`name` LIKE ? OR cs.`email` LIKE ?)))';
            $term = '%' . $search . '%';
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }

        return [$where, $bindings];
    }

    /**
     * Resolve sort column and direction from user input.
     *
     * Uses SORT_COLUMNS allowlist to prevent SQL injection.
     * Invalid columns fall back to start_datetime DESC.
     *
     * @return array{0: string, 1: string} [column, direction]
     */
    private static function resolveSort(string $sort, string $direction): array
    {
        $col = self::SORT_COLUMNS[$sort] ?? self::SORT_COLUMNS['start_datetime'];
        $dir = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

        return [$col, $dir];
    }

    /**
     * Get bookings for a tenant on a specific date.
     *
     * Used by calendar day view (single date) and week view (per-day).
     * Excludes cancelled and rescheduled bookings from the visual timeline.
     *
     * @return list<array<string, mixed>>
     */
    public static function forTenantDate(string $tenantId, string $date): array
    {
        return Database::query(
            "SELECT b.`id`, b.`customer_id`, b.`start_datetime`, b.`end_datetime`, b.`status`,
                    b.`booking_pattern`, b.`staff_id`,
                    c.`name` AS `customer_name`, c.`email` AS `customer_email`,
                    s.`name` AS `service_name`, s.`color` AS `service_color`,
                    st.`name` AS `staff_name`,
                    r.`name` AS `resource_name`,
                    ev.`name` AS `event_name`
             FROM `bookings` b
             LEFT JOIN `customers` c ON c.`id` = b.`customer_id`
             LEFT JOIN `services` s ON s.`id` = b.`service_id`
             LEFT JOIN `staff` st ON st.`id` = b.`staff_id`
             LEFT JOIN `resources` r ON r.`id` = b.`resource_id`
             LEFT JOIN `events` ev ON ev.`id` = b.`event_id`
             WHERE b.`tenant_id` = ?
               AND DATE(b.`start_datetime`) = ?
               AND b.`status` NOT IN ('cancelled', 'rescheduled')
             ORDER BY b.`start_datetime` ASC",
            [$tenantId, $date]
        );
    }

    /**
     * Get bookings for a tenant within a date range (inclusive).
     *
     * Used by the calendar month view to fetch all bookings in one query
     * instead of N per-day queries.
     *
     * @return list<array<string, mixed>>
     */
    public static function forTenantDateRange(string $tenantId, string $startDate, string $endDate): array
    {
        return Database::query(
            "SELECT b.`id`, b.`customer_id`, b.`start_datetime`, b.`end_datetime`, b.`status`,
                    b.`booking_pattern`, b.`staff_id`,
                    c.`name` AS `customer_name`, c.`email` AS `customer_email`,
                    s.`name` AS `service_name`, s.`color` AS `service_color`,
                    st.`name` AS `staff_name`,
                    r.`name` AS `resource_name`,
                    ev.`name` AS `event_name`
             FROM `bookings` b
             LEFT JOIN `customers` c ON c.`id` = b.`customer_id`
             LEFT JOIN `services` s ON s.`id` = b.`service_id`
             LEFT JOIN `staff` st ON st.`id` = b.`staff_id`
             LEFT JOIN `resources` r ON r.`id` = b.`resource_id`
             LEFT JOIN `events` ev ON ev.`id` = b.`event_id`
             WHERE b.`tenant_id` = ?
               AND DATE(b.`start_datetime`) >= ?
               AND DATE(b.`start_datetime`) <= ?
               AND b.`status` NOT IN ('cancelled', 'rescheduled')
             ORDER BY b.`start_datetime` ASC",
            [$tenantId, $startDate, $endDate]
        );
    }
}
