<?php

declare(strict_types=1);

namespace App\Application\Availability;

use App\Agent\{ActorContext, OperationException, CanonicalJson, PayloadCipher};
use App\Application\Bookings\{BookingCommands, BookingDraft};
use App\Engine\{BookingService, CapacityCalculator, Database, EventCalculator, ResourceCalculator, TimeSlotCalculator, VenueDateTime};

final class AvailabilityService
{
    public static function tenant(ActorContext $actor, array $input): array
    {
        $actor = BookingCommands::currentActor($actor);
        $id = $actor->business($input['business_id'] ?? null);

        // Availability is a public surface: only active businesses are visible.
        return Database::query("SELECT * FROM tenants WHERE id=? AND status='active'", [$id])[0] ?? throw new OperationException('not_found', 404);
    }

    public static function business(ActorContext $actor, array $input): array
    {
        $actor = BookingCommands::currentActor($actor);
        $t = self::tenant($actor, $input);

        return [
            'business_id' => $t['id'],
            'name' => $t['name'],
            'slug' => $t['slug'],
            'booking_pattern' => $t['booking_pattern'],
            'timezone' => $t['timezone'],
            'currency' => $t['currency'],
            'locale' => $t['locale_override'] ?: $t['locale'],
            'description' => $t['booking_page_description'],
            'confirmation_message' => $t['confirmation_message'],
            'require_phone' => (bool) $t['require_phone'],
            'requires_consent' => (bool) $t['requires_consent'],
            'consent_text' => BookingService::resolveConsentText($t),
            'privacy_policy_url' => $t['privacy_policy_url'],
            'custom_fields' => json_decode($t['custom_fields'] ?? '[]', true) ?: [],
            'cancellation_policy' => $t['cancellation_policy'],
            'allow_cancellation' => (bool) $t['allow_cancellation'],
            'cancellation_hours_before' => (int) $t['cancellation_hours_before'],
            'allow_rescheduling' => (bool) $t['allow_rescheduling'],
            'rescheduling_hours_before' => (int) $t['rescheduling_hours_before'],
            'requires_approval' => (bool) $t['booking_requires_approval'],
            'min_advance_hours' => (int) $t['min_advance_hours'],
            'max_advance_days' => (int) $t['max_advance_days'],
            'booking_url' => '/book/' . rawurlencode($t['slug'])
        ];
    }

    public static function catalog(ActorContext $actor, array $input): array
    {
        $actor = BookingCommands::currentActor($actor);
        $t = self::tenant($actor, $input);
        $kind = $input['kind'] ?? match ($t['booking_pattern']) {
            'timeslot' => 'services',
            'resource' => 'resources',
            'capacity' => 'capacity_slots',
            'event' => 'events'
        };

        // An explicit column list per table keeps internal columns out of the public catalog,
        // and doubles as the allowlist that makes interpolating $kind into SQL safe.
        $columns = match ($kind) {
            'services' => 'id,name,description,duration_minutes,price,price_label,category,preparation_text,cover_image_path,color,is_virtual,requires_staff',
            'staff' => 'id,name,title,avatar_path',
            'resources' => 'id,name,description,capacity,amenities,price_per_night,min_stay_nights,max_stay_nights,check_in_days,check_out_days,cover_image_path',
            'capacity_slots' => 'id,day_of_week,start_time,end_time,max_capacity,min_party_size,max_party_size,label',
            'events' => 'id,name,description,start_datetime,end_datetime,max_participants,price,location,min_spot_count,max_spot_count,allow_waitlist,waitlist_max,is_recurring,rrule,exception_dates',
            default => throw new OperationException('validation_failed', 422, ['kind' => 'unsupported']),
        };
        $sort = $kind === 'capacity_slots' ? 'day_of_week' : 'sort_order';
        if ($kind !== 'capacity_slots') $columns .= ',sort_order';

        $where = 'tenant_id=? AND is_active=1';
        $params = [$t['id']];
        if ($kind === 'staff' && !empty($input['service_id'])) {
            self::reference($t, 'services', $input['service_id']);
            $where .= ' AND id IN (SELECT staff_id FROM service_staff WHERE service_id=?)';
            $params[] = $input['service_id'];
        }
        $limit = max(1, min(100, (int) ($input['limit'] ?? 50)));

        // Anonymous callers share one binding so a public cursor works across their requests.
        $binding = CanonicalJson::hash([
            $t['id'],
            $kind,
            $input['service_id'] ?? null,
            $actor->principalType,
            $actor->authMode === 'public' ? 'public' : $actor->principalId,
            $actor->connectionId
        ]);
        $snapshot = Database::query("SELECT MAX(id) AS id FROM $kind WHERE $where", $params)[0]['id'] ?? '';
        $after = null;
        if (!empty($input['cursor'])) {
            try {
                $cursor = (new PayloadCipher())->decrypt($input['cursor']);
            } catch (\Throwable) {
                throw new OperationException('invalid_cursor', 422);
            }
            if (
                ($cursor['binding'] ?? null) !== $binding
                || ($cursor['expires'] ?? 0) < time()
            ) throw new OperationException('invalid_cursor', 422);
            $snapshot = $cursor['snapshot'];
            $after = $cursor['after'];
        }

        $pageWhere = $where . ' AND id<=?';
        $pageParams = [...$params, $snapshot];
        if ($after !== null) {
            $pageWhere .= " AND ($sort>? OR ($sort=? AND id>?))";
            array_push($pageParams, $after[0], $after[0], $after[1]);
        }
        $rows = Database::query("SELECT $columns FROM $kind WHERE $pageWhere ORDER BY $sort,id LIMIT ?", [...$pageParams, $limit + 1]);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $next = $more ? (new PayloadCipher())->encrypt([
            'binding' => $binding,
            'snapshot' => $snapshot,
            'after' => [end($rows)[$sort], end($rows)['id']],
            'expires' => time() + 3600
        ]) : null;

        // PDO returns strings; cast to the types the published output schema promises.
        foreach ($rows as &$row) {
            foreach ([
                'sort_order', 'capacity', 'duration_minutes', 'min_stay_nights', 'max_stay_nights', 'day_of_week', 'max_capacity',
                'min_party_size', 'max_party_size', 'max_participants', 'min_spot_count', 'max_spot_count', 'waitlist_max'
            ] as $key) if (isset($row[$key])) $row[$key] = (int) $row[$key];
            foreach (['is_virtual', 'requires_staff', 'allow_waitlist', 'is_recurring'] as $key) if (isset($row[$key])) $row[$key] = (bool) $row[$key];
            foreach ([
                'amenities', 'check_in_days', 'check_out_days', 'exception_dates'
            ] as $key) if (array_key_exists($key, $row)) $row[$key] = $row[$key] !== null ? json_decode($row[$key], true) : null;
            foreach (['price', 'price_per_night'] as $key) if (isset($row[$key])) $row[$key] = (string) $row[$key];
        }
        unset($row);

        return [
            'kind' => $kind,
            'items' => $rows,
            'timezone' => $t['timezone'],
            'currency' => $t['currency'],
            'limit' => $limit,
            'truncated' => $more,
            'next_cursor' => $next
        ];
    }

    public static function search(ActorContext $actor, array $input): array
    {
        $actor = BookingCommands::currentActor($actor);
        $t = self::tenant($actor, $input);
        $pattern = $t['booking_pattern'];
        $exclude = null;
        $duration = null;

        // Rescheduling searches exclude the booking being moved, so its own slot shows as free,
        // and pin the offering and party size so a customer cannot change what they booked.
        if (isset($input['reschedule_booking_id']) || $actor->role === 'verified_customer') {
            $id = $input['reschedule_booking_id'] ?? $actor->bookingId;
            if ($id !== null) {
                $booking = BookingCommands::booking($actor, $t, $id, scope: $actor->isStaff() ? 'bookings:read' : 'availability:read');
                if (
                    !BookingService::canReschedule($booking, $t)['allowed']
                    && !$actor->isStaff()
                ) throw new OperationException('rescheduling_unavailable', 409);
                if ($booking['booking_pattern'] !== $pattern) throw new OperationException('pattern_not_supported', 422);
                $exclude = $id;
                if ($pattern === 'timeslot') $duration = (int) ((strtotime($booking['end_datetime']) - strtotime($booking['start_datetime'])) / 60);
                foreach (['service_id', 'staff_id', 'resource_id', 'event_id'] as $key) if (!empty($booking[$key])) {
                    if (
                        isset($input[$key])
                        && $input[$key] !== $booking[$key]
                        && !$actor->isStaff()
                    ) throw new OperationException('reschedule_context_mismatch', 422);
                    $input[$key] ??= $booking[$key];
                }
                if (!$actor->isStaff()) foreach (['party_size', 'guest_count', 'spot_count'] as $key) if (
                    isset($input[$key])
                    && (int) $input[$key] !== (int) $booking['party_size']
                ) throw new OperationException('reschedule_context_mismatch', 422);
                $input['party_size'] ??= (int) $booking['party_size'];
                $input['guest_count'] ??= (int) $booking['party_size'];
                $input['spot_count'] ??= (int) $booking['party_size'];
            }
        }

        $date = isset($input['date']) ? BookingDraft::date($input['date']) : null;
        $month = $input['month'] ?? null;
        if ($month !== null && $date !== null) throw new OperationException('validation_failed', 422, ['date' => 'choose_date_or_month']);
        // Only event searches page; other patterns return one bounded day or month.
        if ($pattern !== 'event' && isset($input['cursor'])) throw new OperationException('invalid_cursor', 422);
        if (
            $month !== null
            && (!is_string($month) || !preg_match('/^(20\d{2})-(0[1-9]|1[012])$/D', $month, $parts))
        ) throw new OperationException('invalid_month', 422);

        $result = [
            'business_id' => $t['id'],
            'booking_pattern' => $pattern,
            'timezone' => $t['timezone'],
            'currency' => $t['currency'],
            'choices' => [],
            'dates' => [],
            'quote' => null,
            'next_cursor' => null,
            'date_rules' => null
        ];

        // A month returns the bookable dates; a single date (or stay range) returns concrete choices.
        switch ($pattern) {
            case 'timeslot':
                $service = $input['service_id'] ?? null;
                $staff = $input['staff_id'] ?? null;
                if ($service !== null) self::reference($t, 'services', $service);
                if ($staff !== null) {
                    self::reference($t, 'staff', $staff);
                    if ($service !== null && !TimeSlotCalculator::staffCanPerform($t['id'], $service, $staff)) {
                        throw new OperationException('staff_invalid', 422);
                    }
                }
                if ($month !== null) $result['dates'] = TimeSlotCalculator::getAvailableDates(
                    $t,
                    (int) $parts[1],
                    (int) $parts[2],
                    $service,
                    $staff,
                    $exclude,
                    $duration
                );
                elseif ($date !== null) {
                    foreach (
                        TimeSlotCalculator::getAvailableSlots($t, $date, $service, $staff, $exclude, $duration)['slots'] as $slot
                    ) $result['choices'][] = $slot + [
                        'date' => $date,
                        ...VenueDateTime::utcRange($date . ' ' . $slot['time'], $date . ' ' . $slot['end_time'], $t['timezone'])
                    ];
                } else throw new OperationException('validation_failed', 422, ['date' => 'required']);
                break;

            case 'resource':
                $id = $input['resource_id'] ?? '';
                $resource = self::reference($t, 'resources', $id);
                $result['date_rules'] = [
                    'check_in_days' => json_decode($resource['check_in_days'] ?? 'null', true),
                    'check_out_days' => json_decode($resource['check_out_days'] ?? 'null', true)
                ];
                if ($month !== null) $result['dates'] = ResourceCalculator::getAvailableDates($t, $id, (int) $parts[1], (int) $parts[2], $exclude)['dates'];
                else {
                    $in = BookingDraft::date($input['check_in'] ?? null);
                    $out = BookingDraft::date($input['check_out'] ?? null);
                    $quote = ResourceCalculator::checkAvailability(
                        $t,
                        $id,
                        $in,
                        $out,
                        self::size($input['guest_count'] ?? $input['party_size'] ?? 1),
                        $exclude
                    );
                    // Money leaves as fixed two-decimal strings so agents never see float artefacts.
                    $result['quote'] = [
                        'available' => $quote['available'],
                        'error' => $quote['error'] ?? null,
                        'nights' => $quote['nights'] ?? null,
                        'total' => isset($quote['total']) ? sprintf('%.2f', $quote['total']) : null,
                        'check_in' => $in,
                        'check_out' => $out,
                        'resource' => $quote['resource'],
                        'pricing' => array_map(
                            static fn ($night) => ['date' => $night['date'], 'price' => sprintf('%.2f', $night['price']), 'label' => $night['label']],
                            $quote['pricing']
                        )
                    ];
                }
                break;

            case 'capacity':
                $size = self::size($input['party_size'] ?? 1);
                if ($month !== null) $result['dates'] = CapacityCalculator::getAvailableDates($t, $month, $size, $exclude)['dates'];
                elseif ($date !== null) {
                    foreach (CapacityCalculator::getAvailableSlots($t, $date, $size, $exclude)['slots'] as $slot) if (
                        self::inHorizon($t, $date . ' ' . $slot['time'])
                    ) $result['choices'][] = $slot + [
                        'date' => $date,
                        ...VenueDateTime::utcRange($date . ' ' . $slot['time'], $date . ' ' . $slot['end_time'], $t['timezone'])
                    ];
                } else throw new OperationException('validation_failed', 422, ['date' => 'required']);
                break;

            case 'event':
                $size = self::size($input['spot_count'] ?? $input['party_size'] ?? 1);
                $page = self::eventOccurrences($actor, $input);
                $events = $page['events'];
                $result['next_cursor'] = $page['next_cursor'];
                foreach ($events as $event) {
                    if ($event === null || !self::inHorizon($t, $event['start_datetime'])) continue;
                    $availability = EventCalculator::checkAvailability($t, $event['id'], $event['date'], $size, $exclude);
                    if (!$availability['available']) continue;
                    // A confirmed booking cannot be rescheduled onto a waitlist.
                    if ($exclude !== null && $availability['waitlisted']) continue;
                    $result['choices'][] = [
                        'id' => $event['id'],
                        'name' => $event['name'],
                        'date' => $event['date'],
                        'time' => substr($event['start_datetime'], 11, 5),
                        'end_time' => substr($event['end_datetime'], 11, 5),
                        'remaining' => $availability['remaining'],
                        'waitlisted' => $availability['waitlisted'],
                        'price' => $event['price'] === null ? null : sprintf('%.2f', $event['price']),
                        ...VenueDateTime::utcRange($event['start_datetime'], $event['end_datetime'], $t['timezone'])
                    ];
                }
                break;
        }

        return $result;
    }

    /** Shared event discovery, with stable occurrence continuation and live inventory. */
    public static function eventOccurrences(ActorContext $actor, array $input): array
    {
        $actor = BookingCommands::currentActor($actor);
        $tenant = self::tenant($actor, $input);
        if ($tenant['booking_pattern'] !== 'event') throw new OperationException('pattern_not_supported', 422);

        $window = [];
        if (!empty($input['event_id'])) {
            self::reference($tenant, 'events', $input['event_id']);
            $window['event_id'] = $input['event_id'];
        }
        if (isset($input['date']) && isset($input['month'])) throw new OperationException('validation_failed', 422, ['date' => 'choose_date_or_month']);
        if (isset($input['date'])) $window['from'] = $window['to'] = BookingDraft::date($input['date']);
        if (isset($input['month'])) {
            if (
                !is_string($input['month'])
                || !preg_match('/^20[0-9]{2}-(0[1-9]|1[012])$/D', $input['month'])
            ) throw new OperationException('invalid_month', 422);
            $window['from'] = $input['month'] . '-01';
            $window['to'] = (new \DateTimeImmutable($window['from']))->format('Y-m-t');
        }

        // Paging inputs are excluded from the binding so the same query keeps one cursor chain.
        $filter = $input;
        unset($filter['cursor'], $filter['limit'], $filter['business_id']);
        $binding = CanonicalJson::hash([
            $tenant['id'],
            $actor->principalType,
            $actor->authMode === 'public' ? 'public' : $actor->principalId,
            $actor->connectionId,
            $actor->credentialId,
            $filter
        ]);
        $window['snapshot'] = Database::query('SELECT MAX(id) AS id FROM events WHERE tenant_id=?', [$tenant['id']])[0]['id'] ?? '';
        if (!empty($input['cursor'])) {
            try {
                $cursor = (new PayloadCipher())->decrypt($input['cursor']);
            } catch (\Throwable) {
                throw new OperationException('invalid_cursor', 422);
            }
            if (
                ($cursor['binding'] ?? null) !== $binding
                || ($cursor['expires'] ?? 0) < time()
            ) throw new OperationException('invalid_cursor', 422);
            $window['snapshot'] = $cursor['snapshot'];
            $window['after'] = $cursor['after'];
        }

        $limit = max(1, min(100, (int) ($input['limit'] ?? 50)));
        $events = EventCalculator::getUpcomingEvents($tenant, $limit + 1, $window)['events'];
        $more = count($events) > $limit;
        $events = array_slice($events, 0, $limit);
        $last = $events ? end($events) : null;
        $next = $more ? (new PayloadCipher())->encrypt([
            'binding' => $binding,
            'snapshot' => $window['snapshot'],
            'after' => [$last['start_datetime'], $last['id']],
            'expires' => time() + 3600
        ]) : null;
        foreach ($events as &$event) $event += VenueDateTime::utcRange($event['start_datetime'], $event['end_datetime'], $tenant['timezone']);
        unset($event);

        return ['events' => $events, 'next_cursor' => $next];
    }

    public static function eventDetail(ActorContext $actor, array $input): array
    {
        $actor = BookingCommands::currentActor($actor);
        $tenant = self::tenant($actor, $input);
        $date = isset($input['date']) ? BookingDraft::date($input['date']) : null;
        $event = EventCalculator::getEventDetail($tenant, $input['event_id'], $date)['event'] ?? throw new OperationException('event_not_found', 404);

        return ['event' => $event + VenueDateTime::utcRange($event['start_datetime'], $event['end_datetime'], $tenant['timezone'])];
    }

    public static function publicActor(array $tenant): ActorContext
    {
        // Each anonymous visitor gets a throwaway principal id; nothing ties it to a person.
        return new ActorContext(
            'anonymous',
            \App\Engine\Ulid::generate(),
            'prospective_customer',
            [$tenant['id']],
            ['public:read'],
            authMode: 'public'
        );
    }

    public static function reference(array $tenant, string $table, string $id): array
    {
        return Database::query(
            "SELECT * FROM $table WHERE tenant_id=? AND id=? AND is_active=1",
            [$tenant['id'], $id]
        )[0] ?? throw new OperationException('not_found', 404);
    }

    private static function size(mixed $size): int
    {
        if (
            filter_var($size, FILTER_VALIDATE_INT) === false
            || (int) $size < 1
            || (int) $size > 10000
        ) throw new OperationException('invalid_party_size', 422);

        return (int) $size;
    }

    /** The same lead-time and booking-window rules BookingDraft enforces, applied before showing a choice. */
    private static function inHorizon(array $tenant, string $start): bool
    {
        $tz = new \DateTimeZone($tenant['timezone']);
        $now = new \DateTimeImmutable('now', $tz);
        $at = new \DateTimeImmutable($start, $tz);

        return $at > $now
            && $at >= $now->modify('+' . (int) $tenant['min_advance_hours'] . ' hours')
            && $at <= $now->modify('+' . (int) $tenant['max_advance_days'] . ' days')->setTime(23, 59, 59);
    }
}
