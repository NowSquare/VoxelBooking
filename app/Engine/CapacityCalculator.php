<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Capacity booking engine.
 *
 * Handles capacity-pattern tenants (restaurants, escape rooms, group classes)
 * with fixed time windows and limited seats per slot.
 *
 * For each configured slot on a requested date:
 * - Counts confirmed bookings and legacy rescheduled rows without a replacement
 * - Subtracts from max_capacity to get remaining spots
 * - Filters by max_party_size and blocked dates
 *
 * All methods are static. No state is held between calls.
 */
final class CapacityCalculator
{
    /**
     * Get available capacity slots for a given date and party size.
     *
     * @param array  $tenant    Tenant row (must include id, timezone)
     * @param string $date      Date in Y-m-d format
     * @param int    $partySize Number of guests requesting
     *
     * @return array{slots: list<array{id: string, time: string, end_time: string, label: string|null, remaining: int, max: int, max_party_size: int}>}
     */
    public static function getAvailableSlots(array $tenant, string $date, int $partySize = 1, ?string $excludeBookingId = null): array
    {
        $tenantId = $tenant['id'];
        $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');

        // Determine day of week (0=Monday, 6=Sunday)
        $dt = new \DateTimeImmutable($date, $tz);
        $dayOfWeek = ((int) $dt->format('N')) - 1; // N: 1=Mon → 0, 7=Sun → 6

        // Check if date is globally blocked for this tenant
        $blockedGlobal = Database::query(
            'SELECT COUNT(*) AS `cnt` FROM `blocked_dates`
             WHERE `tenant_id` = ? AND `start_date` <= ? AND `end_date` >= ?
             AND `resource_id` IS NULL AND `staff_id` IS NULL',
            [$tenantId, $date, $date]
        );
        if ((int) ($blockedGlobal[0]['cnt'] ?? 0) > 0) {
            return ['slots' => []];
        }

        // Load active capacity slots for this day of week
        $slots = Database::query(
            'SELECT `id`, `start_time`, `end_time`, `max_capacity`, `min_party_size`, `max_party_size`, `label`
             FROM `capacity_slots`
             WHERE `tenant_id` = ? AND `day_of_week` = ? AND `is_active` = 1
             ORDER BY `start_time` ASC',
            [$tenantId, $dayOfWeek]
        );

        if (empty($slots)) {
            return ['slots' => []];
        }

        // Sum existing bookings per slot time window on this date
        $dayStart = $date . ' 00:00:00';
        $dayEnd   = $date . ' 23:59:59';

        $bookings = Database::query(
            'SELECT TIME(`start_datetime`) AS `slot_time`, SUM(`party_size`) AS `booked`
             FROM `bookings`
             WHERE `tenant_id` = ? AND `booking_pattern` = \'capacity\'
             AND `start_datetime` >= ? AND `start_datetime` <= ?
             AND `status` IN (\'confirmed\', \'rescheduled\')
             AND (`status` <> \'rescheduled\' OR NULLIF(`rescheduled_to_id`, \'\') IS NULL)
             AND (? IS NULL OR `id` <> ?)
             GROUP BY TIME(`start_datetime`)',
            [$tenantId, $dayStart, $dayEnd, $excludeBookingId, $excludeBookingId]
        );

        $bookedByTime = [];
        foreach ($bookings as $row) {
            $bookedByTime[$row['slot_time']] = (int) $row['booked'];
        }

        $result = [];
        foreach ($slots as $slot) {
            $startTime = substr($slot['start_time'], 0, 5); // HH:MM
            $endTime   = substr($slot['end_time'], 0, 5);
            if (!VenueDateTime::wallTimeExists($date, $startTime, $tz)) {
                continue;
            }
            $maxCapacity  = (int) $slot['max_capacity'];
            $minPartySize = (int) ($slot['min_party_size'] ?? 1);
            $maxPartySize = (int) $slot['max_party_size'];

            // Match booking times to slot (normalize to HH:MM:SS for lookup)
            $booked = $bookedByTime[$slot['start_time']] ?? 0;
            $remaining = $maxCapacity - $booked;

            // Skip if not enough remaining capacity for the requested party size
            if ($remaining < $partySize) {
                continue;
            }

            // Skip if party size exceeds max allowed per booking
            if ($partySize > $maxPartySize) {
                continue;
            }

            // Skip if party size is below minimum per booking
            if ($partySize < $minPartySize) {
                continue;
            }

            $result[] = [
                'id'             => $slot['id'],
                'time'           => $startTime,
                'end_time'       => $endTime,
                'label'          => $slot['label'],
                'remaining'      => $remaining,
                'max'            => $maxCapacity,
                'min_party_size' => $minPartySize,
                'max_party_size' => $maxPartySize,
            ];
        }

        return ['slots' => $result];
    }

    /**
     * Get available dates in a month with capacity remaining for a given party size.
     *
     * @param array  $tenant    Tenant row
     * @param string $month     Month in Y-m format (e.g. "2026-04")
     * @param int    $partySize Number of guests
     *
     * @return array{dates: list<string>} List of Y-m-d dates with available capacity
     */
    public static function getAvailableDates(array $tenant, string $month, int $partySize = 1, ?string $excludeBookingId = null): array
    {
        $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
        $today = new \DateTimeImmutable('today', $tz);
        $maxAdvance = (int) ($tenant['max_advance_days'] ?? 90);
        $maxDate = $today->modify("+{$maxAdvance} days");

        // Parse month boundaries
        $monthStart = new \DateTimeImmutable($month . '-01', $tz);
        $monthEnd   = $monthStart->modify('last day of this month');

        // Clamp to today..maxDate
        $start = $monthStart < $today ? $today : $monthStart;
        $end   = $monthEnd > $maxDate ? $maxDate : $monthEnd;

        if ($start > $end) {
            return ['dates' => []];
        }

        // Load all active capacity slots for this tenant (grouped by day_of_week)
        $allSlots = Database::query(
            'SELECT `day_of_week`, `max_capacity`, `min_party_size`, `max_party_size`, `start_time`
             FROM `capacity_slots`
             WHERE `tenant_id` = ? AND `is_active` = 1
             ORDER BY `day_of_week`, `start_time`',
            [$tenant['id']]
        );

        if (empty($allSlots)) {
            return ['dates' => []];
        }

        // Group slots by day of week
        $slotsByDay = [];
        foreach ($allSlots as $slot) {
            $slotsByDay[(int) $slot['day_of_week']][] = $slot;
        }

        // Load blocked dates in the range
        $blockedRows = Database::query(
            'SELECT `start_date`, `end_date` FROM `blocked_dates`
             WHERE `tenant_id` = ? AND `start_date` <= ? AND `end_date` >= ?
             AND `resource_id` IS NULL AND `staff_id` IS NULL',
            [$tenant['id'], $end->format('Y-m-d'), $start->format('Y-m-d')]
        );
        // Build a set of blocked date strings
        $blocked = [];
        foreach ($blockedRows as $row) {
            $cur = new \DateTimeImmutable($row['start_date'], $tz);
            $blockEnd = new \DateTimeImmutable($row['end_date'], $tz);
            while ($cur <= $blockEnd) {
                $blocked[] = $cur->format('Y-m-d');
                $cur = $cur->modify('+1 day');
            }
        }

        // Load booking sums for the month
        $bookingSums = Database::query(
            'SELECT DATE(`start_datetime`) AS `d`, TIME(`start_datetime`) AS `t`, SUM(`party_size`) AS `booked`
             FROM `bookings`
             WHERE `tenant_id` = ? AND `booking_pattern` = \'capacity\'
             AND `start_datetime` >= ? AND `start_datetime` <= ?
             AND `status` IN (\'confirmed\', \'rescheduled\')
             AND (`status` <> \'rescheduled\' OR NULLIF(`rescheduled_to_id`, \'\') IS NULL)
             AND (? IS NULL OR `id` <> ?)
             GROUP BY DATE(`start_datetime`), TIME(`start_datetime`)',
            [$tenant['id'], $start->format('Y-m-d') . ' 00:00:00', $end->format('Y-m-d') . ' 23:59:59', $excludeBookingId, $excludeBookingId]
        );

        $bookedMap = [];
        foreach ($bookingSums as $row) {
            $bookedMap[$row['d']][$row['t']] = (int) $row['booked'];
        }

        $dates = [];
        $current = $start;
        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');
            $dow = ((int) $current->format('N')) - 1;

            if (!in_array($dateStr, $blocked, true) && isset($slotsByDay[$dow])) {
                // Check if any slot on this day has enough remaining capacity
                foreach ($slotsByDay[$dow] as $slot) {
                    if (!VenueDateTime::wallTimeExists($dateStr, $slot['start_time'], $tz)) {
                        continue;
                    }
                    $booked = $bookedMap[$dateStr][$slot['start_time']] ?? 0;
                    $remaining = (int) $slot['max_capacity'] - $booked;
                    $maxParty = (int) $slot['max_party_size'];
                    $minParty = (int) ($slot['min_party_size'] ?? 1);

                    if ($remaining >= $partySize && $partySize >= $minParty && $partySize <= $maxParty) {
                        $dates[] = $dateStr;
                        break; // One available slot is enough
                    }
                }
            }

            $current = $current->modify('+1 day');
        }

        return ['dates' => $dates];
    }

    /**
     * Check if a specific slot has capacity for a given party size on a date.
     *
     * Used for overbooking prevention before creating a booking.
     *
     * @return array{available: bool, remaining: int, error: string|null}
     */
    public static function checkSlotAvailability(
        array $tenant,
        string $slotId,
        string $date,
        int $partySize,
    ): array {
        $tenantId = $tenant['id'];

        // Load the slot
        $slots = Database::query(
            'SELECT `start_time`, `end_time`, `max_capacity`, `min_party_size`, `max_party_size`, `day_of_week`, `is_active`
             FROM `capacity_slots`
             WHERE `id` = ? AND `tenant_id` = ?',
            [$slotId, $tenantId]
        );

        if (empty($slots) || (int) $slots[0]['is_active'] === 0) {
            return ['available' => false, 'remaining' => 0, 'error' => 'slot_not_found'];
        }

        $slot = $slots[0];

        // Validate day of week matches
        $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
        $dt = new \DateTimeImmutable($date, $tz);
        $expectedDow = ((int) $dt->format('N')) - 1;
        if ((int) $slot['day_of_week'] !== $expectedDow) {
            return ['available' => false, 'remaining' => 0, 'error' => 'slot_not_on_day'];
        }
        if (!VenueDateTime::wallTimeExists($date, $slot['start_time'], $tz)) {
            return ['available' => false, 'remaining' => 0, 'error' => 'slot_not_on_day'];
        }

        // Check blocked date
        $blockedCount = Database::query(
            'SELECT COUNT(*) AS `cnt` FROM `blocked_dates`
             WHERE `tenant_id` = ? AND `start_date` <= ? AND `end_date` >= ?
             AND `resource_id` IS NULL AND `staff_id` IS NULL',
            [$tenantId, $date, $date]
        );
        if ((int) ($blockedCount[0]['cnt'] ?? 0) > 0) {
            return ['available' => false, 'remaining' => 0, 'error' => 'date_blocked'];
        }

        // Check party size limits
        if ($partySize < (int) ($slot['min_party_size'] ?? 1)) {
            return ['available' => false, 'remaining' => 0, 'error' => 'party_too_small'];
        }
        if ($partySize > (int) $slot['max_party_size']) {
            return ['available' => false, 'remaining' => 0, 'error' => 'party_too_large'];
        }

        // Sum existing bookings for this slot on this date
        $startDt = $date . ' ' . $slot['start_time'];
        $booked = Database::query(
            'SELECT COALESCE(SUM(`party_size`), 0) AS `total`
             FROM `bookings`
             WHERE `tenant_id` = ? AND `booking_pattern` = \'capacity\'
             AND `start_datetime` = ? AND `status` IN (\'confirmed\', \'rescheduled\')
             AND (`status` <> \'rescheduled\' OR NULLIF(`rescheduled_to_id`, \'\') IS NULL)',
            [$tenantId, $startDt]
        );

        $totalBooked = (int) ($booked[0]['total'] ?? 0);
        $maxCapacity = (int) $slot['max_capacity'];
        $remaining = $maxCapacity - $totalBooked;

        if ($remaining < $partySize) {
            return ['available' => false, 'remaining' => max(0, $remaining), 'error' => 'capacity_exceeded'];
        }

        return ['available' => true, 'remaining' => $remaining - $partySize, 'error' => null];
    }
}
