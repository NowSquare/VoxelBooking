<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * ResourceCalculator — computes availability for resource-pattern tenants.
 *
 * Per roadmap Phase R:
 * 1. Query the resource record (validates existence + active)
 * 2. Check blocked dates for the resource and the tenant
 * 3. Check existing bookings that overlap the requested date range
 * 4. Apply min/max stay rules
 * 5. Calculate pricing per night (base + seasonal overrides)
 *
 * Resource bookings are date-based (full-day granularity, V1).
 * start_datetime/end_datetime store midnight-to-midnight ranges.
 */
final class ResourceCalculator
{
    /**
     * Get available date ranges for a resource in a given month.
     *
     * Returns dates that are not fully blocked and where the resource
     * has no overlapping confirmed booking.
     *
     * @param array  $tenant     Tenant record
     * @param string $resourceId Resource ID
     * @param int    $year       Year
     * @param int    $month      Month (1-12)
     *
     * @return array{dates: list<string>, year: int, month: int, check_in_days: ?list<int>, check_out_days: ?list<int>}
     */
    public static function getAvailableDates(
        array $tenant,
        string $resourceId,
        int $year,
        int $month,
        ?string $excludeBookingId = null,
    ): array {
        $tenantId = $tenant['id'];
        $timezone = $tenant['timezone'] ?? 'UTC';
        $now = new \DateTimeImmutable('now', new \DateTimeZone($timezone));

        // Validate resource
        $resource = self::loadResource($resourceId, $tenantId);
        if (!$resource) {
            return ['dates' => [], 'year' => $year, 'month' => $month];
        }

        $maxAdvanceDays = (int) ($tenant['max_advance_days'] ?? 90);
        $latestBookable = $now->modify("+{$maxAdvanceDays} days");
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $availableDates = [];

        // Pre-load all blocked date ranges for this resource + tenant in this month
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
        $blockedRanges = self::getBlockedRanges($tenantId, $resourceId, $monthStart, $monthEnd);

        // Pre-load all bookings for this resource in this month
        $bookedDates = self::getBookedDates($tenantId, $resourceId, $monthStart, $monthEnd, $excludeBookingId);

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $date = new \DateTimeImmutable($dateStr, new \DateTimeZone($timezone));

            // Past dates
            if ($date < $now->setTime(0, 0, 0)) {
                continue;
            }

            // Beyond max advance days
            if ($date > $latestBookable) {
                continue;
            }

            // Blocked?
            if (self::isDateBlocked($dateStr, $blockedRanges)) {
                continue;
            }

            // Already booked?
            if (in_array($dateStr, $bookedDates, true)) {
                continue;
            }

            $availableDates[] = $dateStr;
        }

        // Parse check-in/out day restrictions from resource
        $checkInDays = null;
        $checkOutDays = null;
        if ($resource) {
            $raw = $resource['check_in_days'] ?? null;
            if ($raw !== null && $raw !== '') {
                $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
                if (is_array($decoded) && count($decoded) > 0) {
                    $checkInDays = array_map('intval', $decoded);
                }
            }
            $raw = $resource['check_out_days'] ?? null;
            if ($raw !== null && $raw !== '') {
                $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
                if (is_array($decoded) && count($decoded) > 0) {
                    $checkOutDays = array_map('intval', $decoded);
                }
            }
        }

        return [
            'dates'          => $availableDates,
            'year'           => $year,
            'month'          => $month,
            'check_in_days'  => $checkInDays,
            'check_out_days' => $checkOutDays,
        ];
    }

    /**
     * Check availability for a specific date range on a resource.
     *
     * Returns availability status, pricing breakdown, and total price.
     *
     * @param array  $tenant     Tenant record
     * @param string $resourceId Resource ID
     * @param string $checkIn    Check-in date (YYYY-MM-DD)
     * @param string $checkOut   Check-out date (YYYY-MM-DD)
     * @param int    $guestCount Number of guests
     *
     * @return array{available: bool, resource: array|null, nights: int, pricing: list<array>, total: float, error: string|null}
     */
    public static function checkAvailability(
        array $tenant,
        string $resourceId,
        string $checkIn,
        string $checkOut,
        int $guestCount = 1,
        ?string $excludeBookingId = null,
        bool $ignoreAdvanceWindow = false,
    ): array {
        $tenantId = $tenant['id'];
        $timezone = $tenant['timezone'] ?? 'UTC';
        $tz = new \DateTimeZone($timezone);

        $resource = self::loadResource($resourceId, $tenantId);
        if (!$resource) {
            return self::unavailable('resource_not_found');
        }

        $checkInDate = new \DateTimeImmutable($checkIn, $tz);
        $checkOutDate = new \DateTimeImmutable($checkOut, $tz);
        $now = new \DateTimeImmutable('now', $tz);

        // Check-out must be after check-in
        if ($checkOutDate <= $checkInDate) {
            return self::unavailable('invalid_date_range');
        }

        $nights = (int) $checkInDate->diff($checkOutDate)->days;

        // Min/max stay
        $minStay = (int) ($resource['min_stay_nights'] ?? 1);
        $maxStay = (int) ($resource['max_stay_nights'] ?? 30);
        if ($nights < $minStay) {
            return self::unavailable('min_stay_violation');
        }
        if ($nights > $maxStay) {
            return self::unavailable('max_stay_violation');
        }

        // Guest count vs capacity
        if ($guestCount > (int) $resource['capacity']) {
            return self::unavailable('capacity_exceeded');
        }

        // Check-in day-of-week restriction
        $checkInDaysRaw = $resource['check_in_days'] ?? null;
        if ($checkInDaysRaw !== null && $checkInDaysRaw !== '') {
            $allowedDays = is_string($checkInDaysRaw) ? json_decode($checkInDaysRaw, true) : $checkInDaysRaw;
            if (is_array($allowedDays) && count($allowedDays) > 0) {
                $dow = (int) $checkInDate->format('w'); // 0=Sunday
                if (!in_array($dow, array_map('intval', $allowedDays), true)) {
                    return self::unavailable('invalid_check_in_day');
                }
            }
        }

        // Check-out day-of-week restriction
        $checkOutDaysRaw = $resource['check_out_days'] ?? null;
        if ($checkOutDaysRaw !== null && $checkOutDaysRaw !== '') {
            $allowedDays = is_string($checkOutDaysRaw) ? json_decode($checkOutDaysRaw, true) : $checkOutDaysRaw;
            if (is_array($allowedDays) && count($allowedDays) > 0) {
                $dow = (int) $checkOutDate->format('w');
                if (!in_array($dow, array_map('intval', $allowedDays), true)) {
                    return self::unavailable('invalid_check_out_day');
                }
            }
        }

        // Min advance
        $minAdvance = (int) ($tenant['min_advance_hours'] ?? 1);
        $earliest = $now->modify("+{$minAdvance} hours");
        if (!$ignoreAdvanceWindow && $checkInDate < $earliest->setTime(0, 0, 0)) {
            return self::unavailable('too_soon');
        }

        // Max advance
        $maxAdvanceDays = (int) ($tenant['max_advance_days'] ?? 90);
        $latest = $now->modify("+{$maxAdvanceDays} days");
        if (!$ignoreAdvanceWindow && $checkInDate > $latest) {
            return self::unavailable('too_far');
        }

        // Blocked dates check
        $blockedRanges = self::getBlockedRanges($tenantId, $resourceId, $checkIn, $checkOut);
        $current = $checkInDate;
        while ($current < $checkOutDate) {
            if (self::isDateBlocked($current->format('Y-m-d'), $blockedRanges)) {
                return self::unavailable('date_blocked');
            }
            $current = $current->modify('+1 day');
        }

        // Existing bookings overlap check
        $conflicts = Database::query(
            'SELECT `id` FROM `bookings`
             WHERE `tenant_id` = ? AND `resource_id` = ?
             AND `status` IN (\'confirmed\', \'rescheduled\')
             AND (`status` <> \'rescheduled\' OR NULLIF(`rescheduled_to_id`, \'\') IS NULL)
             AND (? IS NULL OR `id` <> ?)
             AND `start_datetime` < ? AND `end_datetime` > ?
             LIMIT 1',
            [$tenantId, $resourceId, $excludeBookingId, $excludeBookingId, $checkOut . ' 00:00:00', $checkIn . ' 00:00:00']
        );

        if (!empty($conflicts)) {
            return self::unavailable('already_booked');
        }

        // Calculate pricing
        $pricing = self::calculatePricing($resourceId, $tenantId, $checkInDate, $checkOutDate, (float) ($resource['price_per_night'] ?? 0));

        return [
            'available' => true,
            'resource'  => [
                'id'       => $resource['id'],
                'name'     => $resource['name'],
                'capacity' => (int) $resource['capacity'],
            ],
            'nights'    => $nights,
            'pricing'   => $pricing['breakdown'],
            'total'     => $pricing['total'],
            'error'     => null,
        ];
    }

    /**
     * Load an active resource by ID and tenant.
     */
    private static function loadResource(string $resourceId, string $tenantId): ?array
    {
        $rows = Database::query(
            'SELECT * FROM `resources` WHERE `id` = ? AND `tenant_id` = ? AND `is_active` = 1 LIMIT 1',
            [$resourceId, $tenantId]
        );

        return $rows[0] ?? null;
    }

    /**
     * Get blocked date ranges for a resource (resource-level + tenant-level).
     *
     * @return list<array{start: string, end: string}>
     */
    private static function getBlockedRanges(string $tenantId, string $resourceId, string $rangeStart, string $rangeEnd): array
    {
        $rows = Database::query(
            'SELECT `start_date`, `end_date` FROM `blocked_dates`
             WHERE `tenant_id` = ?
             AND ((`resource_id` = ?) OR (`resource_id` IS NULL AND `staff_id` IS NULL))
             AND `start_date` <= ? AND `end_date` >= ?
             ORDER BY `start_date` ASC',
            [$tenantId, $resourceId, $rangeEnd, $rangeStart]
        );

        return array_map(fn($r) => ['start' => $r['start_date'], 'end' => $r['end_date']], $rows);
    }

    /**
     * Check if a specific date falls within any blocked range.
     *
     * @param list<array{start: string, end: string}> $blockedRanges
     */
    private static function isDateBlocked(string $date, array $blockedRanges): bool
    {
        foreach ($blockedRanges as $range) {
            if ($date >= $range['start'] && $date <= $range['end']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get dates that have an existing confirmed booking for a resource.
     *
     * Returns individual date strings within the range that are occupied.
     *
     * @return list<string>
     */
    private static function getBookedDates(string $tenantId, string $resourceId, string $rangeStart, string $rangeEnd, ?string $excludeBookingId): array
    {
        $bookings = Database::query(
            'SELECT `start_datetime`, `end_datetime` FROM `bookings`
             WHERE `tenant_id` = ? AND `resource_id` = ?
             AND `status` IN (\'confirmed\', \'rescheduled\')
             AND (`status` <> \'rescheduled\' OR NULLIF(`rescheduled_to_id`, \'\') IS NULL)
             AND (? IS NULL OR `id` <> ?)
             AND `start_datetime` < ? AND `end_datetime` > ?',
            [$tenantId, $resourceId, $excludeBookingId, $excludeBookingId, $rangeEnd . ' 23:59:59', $rangeStart . ' 00:00:00']
        );

        $dates = [];
        foreach ($bookings as $booking) {
            $start = new \DateTimeImmutable(substr($booking['start_datetime'], 0, 10));
            $end = new \DateTimeImmutable(substr($booking['end_datetime'], 0, 10));
            $current = $start;
            while ($current < $end) {
                $d = $current->format('Y-m-d');
                if ($d >= $rangeStart && $d <= $rangeEnd) {
                    $dates[] = $d;
                }
                $current = $current->modify('+1 day');
            }
        }

        return array_unique($dates);
    }

    /**
     * Calculate per-night pricing with seasonal overrides.
     *
     * @return array{breakdown: list<array{date: string, price: float, label: string|null}>, total: float}
     */
    private static function calculatePricing(
        string $resourceId,
        string $tenantId,
        \DateTimeImmutable $checkIn,
        \DateTimeImmutable $checkOut,
        float $basePrice,
    ): array {
        // Load seasonal pricing entries that overlap the stay
        $seasonalRows = Database::query(
            'SELECT `start_date`, `end_date`, `price_per_night`, `label`
             FROM `seasonal_pricing`
             WHERE `resource_id` = ? AND `tenant_id` = ?
             AND `start_date` <= ? AND `end_date` >= ?
             ORDER BY `start_date` ASC',
            [$resourceId, $tenantId, $checkOut->format('Y-m-d'), $checkIn->format('Y-m-d')]
        );

        $breakdown = [];
        $total = 0.0;
        $current = $checkIn;

        while ($current < $checkOut) {
            $dateStr = $current->format('Y-m-d');
            $nightPrice = $basePrice;
            $label = null;

            // Check if this night falls in a seasonal range
            foreach ($seasonalRows as $season) {
                if ($dateStr >= $season['start_date'] && $dateStr <= $season['end_date']) {
                    $nightPrice = (float) $season['price_per_night'];
                    $label = $season['label'];
                    break; // First matching season wins
                }
            }

            $breakdown[] = [
                'date'  => $dateStr,
                'price' => $nightPrice,
                'label' => $label,
            ];

            $total += $nightPrice;
            $current = $current->modify('+1 day');
        }

        return ['breakdown' => $breakdown, 'total' => $total];
    }

    /**
     * Build an unavailable result.
     */
    private static function unavailable(string $reason): array
    {
        return [
            'available' => false,
            'resource'  => null,
            'nights'    => 0,
            'pricing'   => [],
            'total'     => 0.0,
            'error'     => $reason,
        ];
    }
}
