<?php

declare(strict_types=1);

namespace App\Engine;

use RRule\RRule;

/**
 * Event booking engine.
 *
 * Handles event-pattern tenants (yoga classes, workshops, wine tastings,
 * recurring group sessions) with fixed-date or recurring events and
 * optional waitlist support.
 *
 * For one-off events:
 * - Counts confirmed bookings and legacy rescheduled rows without a replacement
 * - Waitlisted bookings do NOT count toward capacity
 *
 * For recurring events:
 * - Expands RRULE into concrete instances within a date window
 * - Subtracts exception dates from expanded set
 * - Counts registrations per instance date
 *
 * All methods are static. No state is held between calls.
 */
final class EventCalculator
{
    /**
     * Get upcoming events with remaining spots for a tenant.
     *
     * Returns both one-off and expanded recurring events, sorted chronologically.
     * Past events are excluded. Each instance includes remaining spots and waitlist status.
     *
     * @param array  $tenant Tenant row (must include id, timezone)
     * @param int    $limit  Max events to return (default 50)
     *
     * @return array{events: list<array{
     *   id: string, name: string, description: ?string, location: ?string,
     *   price: ?string, start_datetime: string, end_datetime: string,
     *   date: string, start_time: string, end_time: string,
     *   max_participants: int, remaining: int, waitlist_count: int,
     *   allow_waitlist: bool, waitlist_max: int, is_full: bool,
     *   can_waitlist: bool
     * }>}
     */
    public static function getUpcomingEvents(array $tenant, int $limit = 50, array $window = []): array
    {
        $tenantId = $tenant['id'];
        $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
        $now = new \DateTimeImmutable('now', $tz);
        $maxAdvance = (int) ($tenant['max_advance_days'] ?? 90);
        $maxDate = $now->modify("+{$maxAdvance} days")->setTime(23,59,59);
        if (!empty($window['from'])) $now = max($now, new \DateTimeImmutable($window['from'].' 00:00:00', $tz));
        if (!empty($window['to'])) $maxDate = min($maxDate, new \DateTimeImmutable($window['to'].' 23:59:59', $tz));
        if ($maxDate < $now) return ['events'=>[]];

        // Load all active events for this tenant
        $events = Database::query(
            'SELECT * FROM `events`
             WHERE `tenant_id` = ? AND `is_active` = 1
             ORDER BY `start_datetime` ASC',
            [$tenantId]
        );

        if (empty($events)) {
            return ['events' => []];
        }

        // Expand events into concrete instances
        $instances = [];
        foreach ($events as $event) {
            if (isset($window['event_id']) && $event['id'] !== $window['event_id']) continue;
            if (isset($window['snapshot']) && $event['id'] > $window['snapshot']) continue;
            if ((int) $event['is_recurring'] && !empty($event['rrule'])) {
                $expanded = self::expandRecurring($event, $tz, $now, $maxDate);
                $instances = array_merge($instances, $expanded);
            } else {
                // One-off event
                if (!VenueDateTime::wallTimeExists(substr($event['start_datetime'], 0, 10), substr($event['start_datetime'], 11, 5), $tz)) {
                    continue;
                }
                $startDt = new \DateTimeImmutable($event['start_datetime'], $tz);
                if ($startDt >= $now && $startDt <= $maxDate) {
                    $instances[] = self::buildInstance($event, $startDt, $tz);
                }
            }
        }

        // Sort by start datetime
        usort($instances, fn($a, $b) => [$a['start_datetime'],$a['id']] <=> [$b['start_datetime'],$b['id']]);
        if (isset($window['after'])) $instances=array_values(array_filter($instances,static fn($row)=>[$row['start_datetime'],$row['id']]>$window['after']));

        // Limit
        $instances = array_slice($instances, 0, $limit);

        if (empty($instances)) {
            return ['events' => []];
        }

        // Load live booking counts per event and occurrence date. A booking whose
        // event was deleted keeps a null event_id and belongs to no occurrence.
        $bookingSums = Database::query(
            'SELECT `event_id`, DATE(`start_datetime`) AS `d`, SUM(`party_size`) AS `booked`
             FROM `bookings`
             WHERE `tenant_id` = ? AND `booking_pattern` = \'event\' AND `event_id` IS NOT NULL
             AND `status` IN (\'confirmed\', \'rescheduled\')
             AND (`status` <> \'rescheduled\' OR NULLIF(`rescheduled_to_id`, \'\') IS NULL)
             GROUP BY `event_id`, DATE(`start_datetime`)',
            [$tenantId]
        );

        $bookedMap = [];
        foreach ($bookingSums as $row) {
            $bookedMap[$row['event_id']][$row['d']] = (int) $row['booked'];
        }

        // Load waitlist counts per event+date
        $waitlistSums = Database::query(
            'SELECT `event_id`, DATE(`start_datetime`) AS `d`, SUM(`party_size`) AS `waitlisted`
             FROM `bookings`
             WHERE `tenant_id` = ? AND `booking_pattern` = \'event\' AND `event_id` IS NOT NULL
             AND `status` = \'waitlisted\'
             GROUP BY `event_id`, DATE(`start_datetime`)',
            [$tenantId]
        );

        $waitlistMap = [];
        foreach ($waitlistSums as $row) {
            $waitlistMap[$row['event_id']][$row['d']] = (int) $row['waitlisted'];
        }

        // Enrich instances with availability
        foreach ($instances as &$inst) {
            $booked = $bookedMap[$inst['id']][$inst['date']] ?? 0;
            $waitlisted = $waitlistMap[$inst['id']][$inst['date']] ?? 0;
            $max = $inst['max_participants'];
            $remaining = max(0, $max - $booked);
            $isFull = $remaining === 0;
            $allowWaitlist = $inst['allow_waitlist'];
            $waitlistMax = $inst['waitlist_max'];
            $canWaitlist = $isFull && $allowWaitlist && $waitlisted < $waitlistMax;

            $inst['remaining'] = $remaining;
            $inst['waitlist_count'] = $waitlisted;
            $inst['is_full'] = $isFull;
            $inst['can_waitlist'] = $canWaitlist;
        }
        unset($inst);

        return ['events' => $instances];
    }

    /**
     * Get a single event detail with full availability info.
     *
     * For recurring events, the date parameter selects the specific instance.
     *
     * @param array   $tenant  Tenant row
     * @param string  $eventId Event ULID
     * @param ?string $date    Instance date (Y-m-d) for recurring events
     *
     * @return array{event: ?array}
     */
    public static function getEventDetail(array $tenant, string $eventId, ?string $date = null): array
    {
        $tenantId = $tenant['id'];
        $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');

        $events = Database::query(
            'SELECT * FROM `events` WHERE `id` = ? AND `tenant_id` = ? AND `is_active` = 1',
            [$eventId, $tenantId]
        );

        if (empty($events)) {
            return ['event' => null];
        }

        $event = $events[0];

        // Determine the instance date
        if ($date !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            || ((int)$event['is_recurring'] && !self::isValidOccurrence($event, $date, $tz))
            || (!(int)$event['is_recurring'] && $date !== substr($event['start_datetime'],0,10)))) {
            return ['event' => null];
        }
        if ((int) $event['is_recurring'] && $date) {
            $instanceDate = new \DateTimeImmutable($date, $tz);
        } else {
            $instanceDate = new \DateTimeImmutable($event['start_datetime'], $tz);
        }

        if (!VenueDateTime::wallTimeExists($instanceDate->format('Y-m-d'), substr($event['start_datetime'], 11, 5), $tz)) {
            return ['event' => null];
        }

        $instance = self::buildInstance($event, $instanceDate, $tz);

        // Check exception dates for recurring events
        if ((int) $event['is_recurring']) {
            $exceptions = json_decode($event['exception_dates'] ?? '[]', true) ?: [];
            if (in_array($instance['date'], $exceptions, true)) {
                return ['event' => null]; // This instance is cancelled
            }
        }

        // Load booking count
        $booked = Database::query(
            'SELECT COALESCE(SUM(`party_size`), 0) AS `total`
             FROM `bookings`
             WHERE `tenant_id` = ? AND `event_id` = ? AND `booking_pattern` = \'event\'
             AND DATE(`start_datetime`) = ? AND `status` IN (\'confirmed\', \'rescheduled\')
             AND (`status` <> \'rescheduled\' OR NULLIF(`rescheduled_to_id`, \'\') IS NULL)',
            [$tenantId, $eventId, $instance['date']]
        );

        $waitlisted = Database::query(
            'SELECT COALESCE(SUM(`party_size`), 0) AS `total`
             FROM `bookings`
             WHERE `tenant_id` = ? AND `event_id` = ? AND `booking_pattern` = \'event\'
             AND DATE(`start_datetime`) = ? AND `status` = \'waitlisted\'',
            [$tenantId, $eventId, $instance['date']]
        );

        $bookedCount = (int) ($booked[0]['total'] ?? 0);
        $waitlistCount = (int) ($waitlisted[0]['total'] ?? 0);
        $max = $instance['max_participants'];
        $remaining = max(0, $max - $bookedCount);
        $isFull = $remaining === 0;
        $allowWaitlist = $instance['allow_waitlist'];
        $waitlistMax = $instance['waitlist_max'];
        $canWaitlist = $isFull && $allowWaitlist && $waitlistCount < $waitlistMax;

        $instance['remaining'] = $remaining;
        $instance['waitlist_count'] = $waitlistCount;
        $instance['is_full'] = $isFull;
        $instance['can_waitlist'] = $canWaitlist;

        return ['event' => $instance];
    }

    /**
     * Check availability for a specific event instance.
     *
     * Used for overbooking prevention before creating a booking.
     *
     * @return array{available: bool, waitlisted: bool, remaining: int, error: ?string}
     */
    public static function checkAvailability(
        array $tenant,
        string $eventId,
        string $date,
        int $spotCount,
        ?string $excludeBookingId = null,
    ): array {
        $tenantId = $tenant['id'];
        $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');

        $events = Database::query(
            'SELECT * FROM `events` WHERE `id` = ? AND `tenant_id` = ? AND `is_active` = 1',
            [$eventId, $tenantId]
        );

        if (empty($events)) {
            return ['available' => false, 'waitlisted' => false, 'remaining' => 0, 'error' => 'event_not_found'];
        }

        $event = $events[0];

        if (!VenueDateTime::wallTimeExists($date, substr($event['start_datetime'], 11, 5), $tz)) {
            return ['available' => false, 'waitlisted' => false, 'remaining' => 0, 'error' => 'invalid_date'];
        }

        // Check exception dates for recurring events
        if ((int) $event['is_recurring']) {
            $exceptions = json_decode($event['exception_dates'] ?? '[]', true) ?: [];
            if (in_array($date, $exceptions, true)) {
                return ['available' => false, 'waitlisted' => false, 'remaining' => 0, 'error' => 'instance_cancelled'];
            }

            // Validate date is a valid RRULE occurrence
            if (!self::isValidOccurrence($event, $date, $tz)) {
                return ['available' => false, 'waitlisted' => false, 'remaining' => 0, 'error' => 'invalid_date'];
            }
        } else {
            // One-off: date must match event date
            $eventDate = (new \DateTimeImmutable($event['start_datetime'], $tz))->format('Y-m-d');
            if ($date !== $eventDate) {
                return ['available' => false, 'waitlisted' => false, 'remaining' => 0, 'error' => 'invalid_date'];
            }
        }

        // Replacement-linked origins retain history without consuming spots.
        $booked = Database::query(
            'SELECT COALESCE(SUM(`party_size`), 0) AS `total`
             FROM `bookings`
             WHERE `tenant_id` = ? AND `event_id` = ? AND `booking_pattern` = \'event\'
             AND DATE(`start_datetime`) = ? AND `status` IN (\'confirmed\', \'rescheduled\')
             AND (`status` <> \'rescheduled\' OR NULLIF(`rescheduled_to_id`, \'\') IS NULL) AND (? IS NULL OR id <> ?)',
            [$tenantId, $eventId, $date, $excludeBookingId, $excludeBookingId]
        );

        $totalBooked = (int) ($booked[0]['total'] ?? 0);
        $maxParticipants = (int) $event['max_participants'];
        $remaining = max(0, $maxParticipants - $totalBooked);

        // Direct booking: check per-booking limits
        $minSpots = (int) ($event['min_spot_count'] ?? 1);
        $maxSpotsPerBooking = ($event['max_spot_count'] ?? null) !== null ? (int) $event['max_spot_count'] : null;

        if ($spotCount < $minSpots) {
            return ['available' => false, 'waitlisted' => false, 'remaining' => $remaining, 'error' => 'spot_count_too_few'];
        }
        if ($maxSpotsPerBooking !== null && $spotCount > $maxSpotsPerBooking) {
            return ['available' => false, 'waitlisted' => false, 'remaining' => $remaining, 'error' => 'spot_count_too_many'];
        }

        // Direct booking: spots available
        if ($remaining >= $spotCount) {
            return ['available' => true, 'waitlisted' => false, 'remaining' => $remaining - $spotCount, 'error' => null];
        }

        // Waitlist check
        if ((int) $event['allow_waitlist']) {
            $waitlisted = Database::query(
                'SELECT COALESCE(SUM(`party_size`), 0) AS `total`
                 FROM `bookings`
                 WHERE `tenant_id` = ? AND `event_id` = ? AND `booking_pattern` = \'event\'
                 AND DATE(`start_datetime`) = ? AND `status` = \'waitlisted\'',
                [$tenantId, $eventId, $date]
            );

            $waitlistCount = (int) ($waitlisted[0]['total'] ?? 0);
            $waitlistMax = (int) $event['waitlist_max'];

            if ($waitlistCount + $spotCount <= $waitlistMax) {
                return ['available' => true, 'waitlisted' => true, 'remaining' => 0, 'error' => null];
            }

            return ['available' => false, 'waitlisted' => false, 'remaining' => 0, 'error' => 'waitlist_full'];
        }

        return ['available' => false, 'waitlisted' => false, 'remaining' => max(0, $remaining), 'error' => 'event_full'];
    }

    /**
     * Expand a recurring event into concrete instances using RRULE.
     *
     * @return list<array> Instances within [now, maxDate]
     */
    private static function expandRecurring(array $event, \DateTimeZone $tz, \DateTimeImmutable $now, \DateTimeImmutable $maxDate): array
    {
        $exceptions = json_decode($event['exception_dates'] ?? '[]', true) ?: [];
        $startDt = new \DateTimeImmutable($event['start_datetime'], $tz);

        try {
            $rrule = new RRule(
                $event['rrule'],
                $startDt
            );
        } catch (\Exception) {
            return []; // Invalid RRULE — skip silently
        }

        $instances = [];
        foreach ($rrule as $occurrence) {
            /** @var \DateTime $occurrence */
            $occDt = \DateTimeImmutable::createFromMutable($occurrence)->setTimezone($tz);
            $occDate = $occDt->format('Y-m-d');

            // Skip past occurrences
            if ($occDt < $now) {
                continue;
            }

            // Stop beyond max advance window
            if ($occDt > $maxDate) {
                break;
            }

            if (!VenueDateTime::wallTimeExists($occDate, substr($event['start_datetime'], 11, 5), $tz)) {
                continue;
            }

            // Skip exception dates
            if (in_array($occDate, $exceptions, true)) {
                continue;
            }

            $instances[] = self::buildInstance($event, $occDt, $tz);
        }

        return $instances;
    }

    /**
     * Check if a date is a valid occurrence for a recurring event.
     */
    private static function isValidOccurrence(array $event, string $date, \DateTimeZone $tz): bool
    {
        $startDt = new \DateTimeImmutable($event['start_datetime'], $tz);

        try {
            $rrule = new RRule(
                $event['rrule'],
                $startDt
            );

            // RRule::occursAt mutates its date argument when normalizing the timezone.
            $target = new \DateTime($date . ' ' . $startDt->format('H:i:s'), $tz);
            return $rrule->occursAt($target);
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Build an event instance array from an event row and a specific datetime.
     */
    private static function buildInstance(array $event, \DateTimeImmutable $startDt, \DateTimeZone $tz): array
    {
        $range = VenueDateTime::eventRange($event['start_datetime'], $event['end_datetime'], $startDt->format('Y-m-d'), $tz);
        $startDt = $range['start'];
        $endDt = $range['end'];

        return [
            'id'               => $event['id'],
            'name'             => $event['name'],
            'description'      => $event['description'],
            'location'         => $event['location'],
            'price'            => $event['price'],
            'start_datetime'   => $startDt->format('Y-m-d H:i:s'),
            'end_datetime'     => $endDt->format('Y-m-d H:i:s'),
            'date'             => $startDt->format('Y-m-d'),
            'start_time'       => $startDt->format('H:i'),
            'end_time'         => $endDt->format('H:i'),
            'max_participants' => (int) $event['max_participants'],
            'min_spot_count'   => (int) ($event['min_spot_count'] ?? 1),
            'max_spot_count'   => ($event['max_spot_count'] ?? null) !== null ? (int) $event['max_spot_count'] : null,
            'allow_waitlist'   => (bool) (int) $event['allow_waitlist'],
            'waitlist_max'     => (int) $event['waitlist_max'],
            // Availability fields filled by caller
            'remaining'        => (int) $event['max_participants'],
            'waitlist_count'   => 0,
            'is_full'          => false,
            'can_waitlist'     => false,
        ];
    }
}
