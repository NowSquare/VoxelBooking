<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * TimeSlotCalculator — computes available booking slots for timeslot tenants.
 *
 * Per PRD §III (SlotCalculator):
 * 1. Query availability rules for the requested day of week
 * 2. Subtract existing confirmed bookings (+ buffer time)
 * 3. Subtract blocked dates
 * 4. Apply service duration to generate valid start times
 * 5. Apply min_advance_hours and max_advance_days constraints
 *
 * Returns: array of ['time' => 'HH:MM', 'end_time' => 'HH:MM', 'staff_id' => ?string]
 */
final class TimeSlotCalculator
{
    /**
     * Get available time slots for a given date, service, and optional staff.
     *
     * @param array $tenant Tenant record
     * @param string $date YYYY-MM-DD
     * @param string|null $serviceId Filter to service (determines duration)
     * @param string|null $staffId Filter to specific staff member (null = any available)
     *
     * @return array{slots: array, date: string, timezone: string}
     */
    public static function getAvailableSlots(
        array $tenant,
        string $date,
        ?string $serviceId = null,
        ?string $staffId = null,
        ?string $excludeBookingId = null,
        ?int $durationOverride = null,
        bool $ignoreAdvanceWindow = false,
    ): array {
        $tenantId = $tenant['id'];
        $timezone = $tenant['timezone'] ?? 'UTC';
        $bufferMinutes = (int) ($tenant['buffer_minutes'] ?? 0);

        // Resolve service duration
        $serviceDuration = (int) ($tenant['slot_duration_minutes'] ?? 30);
        if ($serviceId) {
            $service = Database::query(
                'SELECT `duration_minutes` FROM `services` WHERE `id` = ? AND `tenant_id` = ? AND `is_active` = 1 LIMIT 1',
                [$serviceId, $tenantId]
            );
            if (!empty($service)) {
                $serviceDuration = (int) $service[0]['duration_minutes'];
            } else {
                return ['slots' => [], 'date' => $date, 'timezone' => $timezone];
            }
        }
        if ($durationOverride !== null) $serviceDuration = max(1, $durationOverride);

        // Check if date is blocked (tenant-level)
        $blockedTenant = Database::query(
            'SELECT 1 FROM `blocked_dates` WHERE `tenant_id` = ? AND `staff_id` IS NULL AND `resource_id` IS NULL AND `start_date` <= ? AND `end_date` >= ? LIMIT 1',
            [$tenantId, $date, $date]
        );
        if (!empty($blockedTenant)) {
            return ['slots' => [], 'date' => $date, 'timezone' => $timezone];
        }

        // Enforce min_advance_hours and max_advance_days
        $now = new \DateTimeImmutable('now', new \DateTimeZone($timezone));
        $requestedDate = new \DateTimeImmutable($date, new \DateTimeZone($timezone));

        $minAdvance = (int) ($tenant['min_advance_hours'] ?? 1);
        $maxAdvanceDays = (int) ($tenant['max_advance_days'] ?? 90);
        $earliestBookable = $now->modify("+{$minAdvance} hours");
        $latestBookable = $now->modify("+{$maxAdvanceDays} days")->setTime(23, 59, 59);

        if (!$ignoreAdvanceWindow && $requestedDate->format('Y-m-d') > $latestBookable->format('Y-m-d')) {
            return ['slots' => [], 'date' => $date, 'timezone' => $timezone];
        }

        // Get day of week (0=Mon, 6=Sun per ISO-8601)
        $dayOfWeek = ((int) $requestedDate->format('N')) - 1; // N: 1=Mon, 7=Sun → 0=Mon, 6=Sun

        // Resolve eligible staff
        $eligibleStaff = self::resolveEligibleStaff($tenantId, $serviceId, $staffId);

        if (empty($eligibleStaff)) {
            // A chosen staff member who cannot perform the service has no times.
            // Without that choice, a business with no active staff books
            // against its own opening hours. `requires_staff` is presentation
            // metadata and never removes times.
            if ($staffId !== null) {
                return ['slots' => [], 'date' => $date, 'timezone' => $timezone];
            }
            // No staff: use tenant-level availability
            $eligibleStaff = [null]; // null = no staff filter
        }

        $allSlots = [];

        foreach ($eligibleStaff as $currentStaffId) {
            // Check staff-level blocked dates
            if ($currentStaffId !== null) {
                $blockedStaff = Database::query(
                    'SELECT 1 FROM `blocked_dates` WHERE `tenant_id` = ? AND `staff_id` = ? AND `start_date` <= ? AND `end_date` >= ? LIMIT 1',
                    [$tenantId, $currentStaffId, $date, $date]
                );
                if (!empty($blockedStaff)) {
                    continue;
                }
            }

            // Get availability windows for this day
            $availWindows = self::getAvailabilityWindows($tenantId, $currentStaffId, $dayOfWeek);
            if (empty($availWindows)) {
                continue;
            }

            // Get existing bookings for this date + staff
            $existingBookings = self::getExistingBookings($tenantId, $date, $currentStaffId, $excludeBookingId);

            // Generate slots from availability windows
            foreach ($availWindows as $window) {
                $windowStart = self::timeToMinutes($window['start_time']);
                $windowEnd = self::timeToMinutes($window['end_time']);

                // Walk through the window in slot_duration_minutes increments
                for ($startMin = $windowStart; $startMin + $serviceDuration <= $windowEnd; $startMin += (int) ($tenant['slot_duration_minutes'] ?? 30)) {
                    $endMin = $startMin + $serviceDuration;
                    $startTime = sprintf('%02d:%02d', (int) floor($startMin / 60), $startMin % 60);
                    if (!VenueDateTime::wallTimeExists($date, $startTime, $requestedDate->getTimezone())) {
                        continue;
                    }

                    // Advance limits can span more than one day.
                    $slotDateTime = $requestedDate->setTime((int) floor($startMin / 60), $startMin % 60);
                    if (!$ignoreAdvanceWindow && $slotDateTime < $earliestBookable) {
                        continue;
                    }

                    // Check against existing bookings (with buffer)
                    $conflicts = false;
                    foreach ($existingBookings as $booking) {
                        $bookingStart = self::timeToMinutes(substr($booking['start_datetime'], 11, 5));
                        $bookingEnd = self::timeToMinutes(substr($booking['end_datetime'], 11, 5));

                        // Add buffer after existing booking
                        $bookingEndWithBuffer = $bookingEnd + $bufferMinutes;

                        // Overlap check: slot overlaps if it starts before booking ends (+ buffer) AND ends after booking starts
                        if ($startMin < $bookingEndWithBuffer && $endMin + $bufferMinutes > $bookingStart) {
                            $conflicts = true;
                            break;
                        }
                    }

                    if (!$conflicts) {
                        $allSlots[] = [
                            'time'     => $startTime,
                            'end_time' => sprintf('%02d:%02d', (int) floor($endMin / 60), $endMin % 60),
                            'staff_id' => $currentStaffId,
                        ];
                    }
                }
            }
        }

        // Deduplicate by time (for "any staff" mode, pick first available)
        if ($staffId === null && count($eligibleStaff) > 1) {
            $seen = [];
            $deduped = [];
            foreach ($allSlots as $slot) {
                if (!isset($seen[$slot['time']])) {
                    $seen[$slot['time']] = true;
                    $deduped[] = $slot;
                }
            }
            $allSlots = $deduped;
        }

        // Sort by time
        usort($allSlots, fn($a, $b) => strcmp($a['time'], $b['time']));

        return ['slots' => $allSlots, 'date' => $date, 'timezone' => $timezone];
    }

    /**
     * Get dates with availability in a given month.
     * Returns array of YYYY-MM-DD strings that have at least one possible slot.
     */
    public static function getAvailableDates(
        array $tenant,
        int $year,
        int $month,
        ?string $serviceId = null,
        ?string $staffId = null,
        ?string $excludeBookingId = null,
        ?int $durationOverride = null,
    ): array {
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $availableDates = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $result = self::getAvailableSlots($tenant, $date, $serviceId, $staffId, $excludeBookingId, $durationOverride);
            if (!empty($result['slots'])) {
                $availableDates[] = $date;
            }
        }

        return $availableDates;
    }

    /**
     * Resolve eligible staff members for a service.
     */
    private static function resolveEligibleStaff(string $tenantId, ?string $serviceId, ?string $staffId): array
    {
        if ($staffId !== null) {
            return self::staffCanPerform($tenantId, $serviceId, $staffId) ? [$staffId] : [];
        }

        if ($serviceId !== null) {
            // Get staff linked to this service via pivot
            $staffRows = Database::query(
                'SELECT s.`id`
                 FROM `staff` s
                 JOIN `service_staff` ss ON ss.`staff_id` = s.`id`
                 WHERE ss.`service_id` = ? AND s.`tenant_id` = ? AND s.`is_active` = 1
                 ORDER BY s.`sort_order` ASC',
                [$serviceId, $tenantId]
            );

            if (!empty($staffRows)) {
                return array_column($staffRows, 'id');
            }
            // No staff linked: any active staff member can perform the service.
        }

        // Fallback: all active staff for tenant
        $staffRows = Database::query(
            'SELECT `id` FROM `staff` WHERE `tenant_id` = ? AND `is_active` = 1 ORDER BY `sort_order` ASC',
            [$tenantId]
        );

        return array_column($staffRows, 'id');
    }

    /**
     * Whether an active staff member can perform a service.
     *
     * Linked staff define who performs a service. A service with no linked
     * staff can be performed by any active staff member, so a booking that
     * fell back to one of them stays valid when it is approved or moved.
     */
    public static function staffCanPerform(string $tenantId, ?string $serviceId, string $staffId): bool
    {
        $active = Database::query(
            'SELECT 1 FROM `staff` WHERE `id` = ? AND `tenant_id` = ? AND `is_active` = 1 LIMIT 1',
            [$staffId, $tenantId]
        );
        if (empty($active) || $serviceId === null) {
            return !empty($active);
        }

        $links = Database::query(
            'SELECT `staff_id` FROM `service_staff` WHERE `service_id` = ?',
            [$serviceId]
        );

        return $links === [] || in_array($staffId, array_column($links, 'staff_id'), true);
    }

    /**
     * Get availability windows for a day.
     * Staff-level overrides tenant-level if both exist.
     */
    private static function getAvailabilityWindows(string $tenantId, ?string $staffId, int $dayOfWeek): array
    {
        if ($staffId !== null) {
            // Try staff-level first
            $windows = Database::query(
                'SELECT `start_time`, `end_time` FROM `availability`
                 WHERE `tenant_id` = ? AND `staff_id` = ? AND `day_of_week` = ? AND `is_available` = 1
                 ORDER BY `start_time` ASC',
                [$tenantId, $staffId, $dayOfWeek]
            );

            if (!empty($windows) || Database::query('SELECT 1 FROM availability WHERE tenant_id = ? AND staff_id = ? LIMIT 1', [$tenantId, $staffId])) {
                return $windows;
            }
        }

        // Tenant-level default
        return Database::query(
            'SELECT `start_time`, `end_time` FROM `availability`
             WHERE `tenant_id` = ? AND `staff_id` IS NULL AND `day_of_week` = ? AND `is_available` = 1
             ORDER BY `start_time` ASC',
            [$tenantId, $dayOfWeek]
        );
    }

    /**
     * Get occupied time windows, excluding replacement-linked history.
     */
    private static function getExistingBookings(string $tenantId, string $date, ?string $staffId, ?string $excludeBookingId): array
    {
        $sql = 'SELECT `start_datetime`, `end_datetime` FROM `bookings`
                WHERE `tenant_id` = ? AND `status` IN (\'confirmed\', \'rescheduled\')
                AND (`status` <> \'rescheduled\' OR NULLIF(`rescheduled_to_id`, \'\') IS NULL)
                AND DATE(`start_datetime`) = ?
                AND (? IS NULL OR `id` <> ?)';
        $params = [$tenantId, $date, $excludeBookingId, $excludeBookingId];

        if ($staffId !== null) {
            $sql .= ' AND `staff_id` = ?';
            $params[] = $staffId;
        }

        return Database::query($sql, $params);
    }

    /**
     * Convert "HH:MM" or "HH:MM:SS" to minutes since midnight.
     */
    private static function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        return ((int) $parts[0]) * 60 + ((int) $parts[1]);
    }
}
