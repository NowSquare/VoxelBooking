<?php

declare(strict_types=1);

namespace App\Application\Bookings;

use App\Agent\{ActorContext, CanonicalJson, OperationException, PayloadCipher};
use App\Engine\{BookingService, Database, VenueDateTime};

/** Explicit projections never include management credentials. */
final class BookingQueries
{
    public static function tenant(ActorContext $actor, array $input, ?string $scope = null): array
    {
        $actor = BookingCommands::currentActor($actor);
        $id = $actor->business($input['business_id'] ?? null);
        // Operators can still read archived businesses; everyone else sees them as gone.
        $tenant = Database::query(
            "SELECT * FROM tenants WHERE id = ?" . ($actor->role === 'operator' ? '' : " AND status != 'archived'"),
            [$id]
        )[0] ?? throw new OperationException('not_found', 404);
        BookingCommands::authorize($actor, $tenant, false, $scope);

        return $tenant;
    }

    public static function get(ActorContext $actor, array $input): array
    {
        $actor = BookingCommands::currentActor($actor);
        $tenant = self::tenant($actor, $input);
        $id = $input['booking_id'] ?? $actor->bookingId ?? '';
        BookingCommands::booking($actor, $tenant, $id);

        return self::project(BookingService::findByIdWithDetails($id, $tenant['id']), $tenant, $actor);
    }

    public static function project(array $row, array $tenant, ActorContext $actor): array
    {
        $result = [
            'id' => $row['id'],
            'business_id' => $row['tenant_id'],
            'booking_pattern' => $row['booking_pattern'],
            'status' => $row['status'],
            'start_datetime' => $row['start_datetime'],
            'end_datetime' => $row['end_datetime'],
            'timezone' => $tenant['timezone'],
            'business_name' => $tenant['name'],
            'business_slug' => $tenant['slug'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'price_total' => $row['price_total'] ?? null,
            'price_currency' => $row['price_currency'] ?? null,
            'service_name' => $row['service_name'] ?? null,
            'service_color' => $row['service_color'] ?? null,
            'staff_name' => $row['staff_name'] ?? null,
            'resource_name' => $row['resource_name'] ?? null,
            'event_name' => $row['event_name'] ?? null,
            'party_size' => (int) $row['party_size'],
            'service_id' => $row['service_id'],
            'staff_id' => $row['staff_id'],
            'resource_id' => $row['resource_id'],
            'event_id' => $row['event_id'],
            'customer' => ['name' => $row['customer_name'] ?? null, 'email' => $row['customer_email'] ?? null],
            'notes' => $row['notes'],
            'custom_fields' => json_decode($row['custom_field_data'] ?? 'null', true),
            'customer_timezone' => $row['customer_timezone'],
            'rescheduled_to_id' => $row['rescheduled_to_id'],
            'cancellation' => BookingService::canCancel($row, $tenant),
            'rescheduling' => BookingService::canReschedule($row, $tenant),
            'allowed_statuses' => $actor->isStaff() ? BookingCommands::transitions($row['status']) : [],
            ...VenueDateTime::presentRange($row['booking_pattern'], $row['start_datetime'], $row['end_datetime'], $tenant['timezone'])
        ];
        // Internal fields are staff-only; customers and public agents never see them.
        if ($actor->isStaff()) $result += [
            'customer_id' => $row['customer_id'],
            'internal_notes' => $row['internal_notes'],
            'source' => $row['source']
        ];

        return $result;
    }

    public static function listing(ActorContext $actor, array $input): array
    {
        return self::page($actor, $input, false);
    }

    public static function operatorListing(ActorContext $actor, array $input): array
    {
        $actor = BookingCommands::currentActor($actor);
        if ($actor->role !== 'operator') throw new OperationException('forbidden', 403);

        return self::page($actor, $input, true);
    }

    private static function page(ActorContext $actor, array $input, bool $crossBusiness): array
    {
        $actor = BookingCommands::currentActor($actor);
        if (!$actor->isStaff()) throw new OperationException('forbidden', 403);

        // Every tenant passes through self::tenant(), so authorization runs per business even cross-business.
        if ($crossBusiness) {
            $tenants = [];
            foreach ($actor->businessIds as $id) {
                $tenant = self::tenant($actor, ['business_id' => $id]);
                $tenants[$id] = $tenant;
            }
        } else {
            $tenant = self::tenant($actor, $input);
            $tenants = [$tenant['id'] => $tenant];
        }
        if ($tenants === []) return ['bookings' => [], 'next_cursor' => null, 'total' => 0, 'timezone' => null];

        // filters() builds a single-tenant clause; widen it to the full tenant set.
        [$where, $bindings] = self::filters(['id' => array_key_first($tenants)], $input);
        $where[0] = 'b.tenant_id IN (' . implode(',', array_fill(0, count($tenants), '?')) . ')';
        array_shift($bindings);
        $bindings = [...array_keys($tenants), ...$bindings];

        $joins = ' FROM bookings b JOIN customers c ON c.id=b.customer_id AND c.tenant_id=b.tenant_id JOIN tenants t ON t.id=b.tenant_id LEFT JOIN services s ON s.id=b.service_id AND s.tenant_id=b.tenant_id LEFT JOIN staff st ON st.id=b.staff_id AND st.tenant_id=b.tenant_id LEFT JOIN resources r ON r.id=b.resource_id AND r.tenant_id=b.tenant_id LEFT JOIN events e ON e.id=b.event_id AND e.tenant_id=b.tenant_id';
        $sort = match ($input['sort'] ?? 'id') {
            'start_datetime' => 'b.start_datetime',
            'customer' => "COALESCE(b.customer_name_snapshot,c.name,'')",
            'service' => "COALESCE(s.name,r.name,e.name,'')",
            'status' => 'b.status',
            'tenant' => 't.name',
            default => 'b.id'
        };
        $direction = strtolower($input['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $comparison = $direction === 'ASC' ? '>' : '<';
        $limit = isset($input['_export_limit']) ? 10001 : max(1, min(100, (int) ($input['limit'] ?? 50)));
        $offset = max(0, (int) ($input['_offset'] ?? 0));

        // The cursor is bound to who asked and what they filtered, so it cannot be replayed against another query.
        $filter = $input;
        unset($filter['cursor'], $filter['limit'], $filter['_offset'], $filter['_export_limit']);
        $binding = CanonicalJson::hash([
            $actor->principalType,
            $actor->principalId,
            $actor->connectionId,
            $actor->credentialId,
            array_keys($tenants),
            $filter,
            $crossBusiness
        ]);

        // Pin the result set to the highest id at the first page so later inserts do not shift pages.
        $snapshot = Database::query('SELECT MAX(b.id) AS id' . $joins . ' WHERE ' . implode(' AND ', $where), $bindings)[0]['id'] ?? '';
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
        $where[] = 'b.id<=?';
        $bindings[] = $snapshot;

        $total = (int) Database::query('SELECT COUNT(*) AS n' . $joins . ' WHERE ' . implode(' AND ', $where), $bindings)[0]['n'];

        // Keyset pagination on (sort value, id); id breaks ties between equal sort values.
        if ($after !== null) {
            $where[] = "($sort $comparison ? OR ($sort=? AND b.id $comparison ?))";
            array_push($bindings, $after[0], $after[0], $after[1]);
        }
        $rows = Database::query(
            'SELECT b.*,COALESCE(b.customer_name_snapshot,c.name) AS customer_name,COALESCE(b.customer_email_snapshot,c.email) AS customer_email,s.name AS service_name,s.color AS service_color,st.name AS staff_name,r.name AS resource_name,e.name AS event_name,'
                . $sort . ' AS cursor_value' . $joins . ' WHERE ' . implode(' AND ', $where)
                . " ORDER BY $sort $direction,b.id $direction LIMIT ? OFFSET ?",
            [...$bindings, $limit + 1, $offset]
        );

        // Fetch one extra row to learn whether another page exists without a second count.
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows ? end($rows) : null;
        $next = $more ? (new PayloadCipher())->encrypt([
            'binding' => $binding,
            'snapshot' => $snapshot,
            'after' => [$last['cursor_value'], $last['id']],
            'expires' => time() + 3600
        ]) : null;

        return [
            'bookings' => array_map(static fn ($row) => self::project($row, $tenants[$row['tenant_id']], $actor), $rows),
            'next_cursor' => $next,
            'total' => $total,
            'timezone' => $crossBusiness ? null : reset($tenants)['timezone']
        ];
    }

    /** Legacy table pagination is a presentation adapter over the same filtered read. */
    public static function browserPage(ActorContext $actor, array $input, bool $crossBusiness = false): array
    {
        $input['_offset'] = (max(1, (int) ($input['page'] ?? 1)) - 1) * 25;
        unset($input['page']);
        $input['limit'] = 25;
        $page = $crossBusiness ? self::operatorListing($actor, $input) : self::listing($actor, $input);
        $page['bookings'] = array_map(self::browserProjection(...), $page['bookings']);

        return $page;
    }

    public static function browserProjection(array $booking): array
    {
        return $booking + [
            'tenant_id' => $booking['business_id'],
            'tenant_name' => $booking['business_name'],
            'tenant_slug' => $booking['business_slug'],
            'customer_name' => $booking['customer']['name'],
            'customer_email' => $booking['customer']['email']
        ];
    }

    public static function calendar(ActorContext $actor, array $input): array
    {
        $actor = BookingCommands::currentActor($actor);
        $from = BookingDraft::date($input['from'] ?? null);
        $to = BookingDraft::date($input['to'] ?? null);
        if (
            $to < $from
            || (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days > 93
        ) throw new OperationException('invalid_interval', 422);

        $tenant = self::tenant($actor, $input);
        $result = self::listing($actor, $input + ['_calendar' => true]);
        $result['blocked_dates'] = Database::query(
            'SELECT id,start_date,end_date,staff_id,resource_id,reason FROM blocked_dates WHERE tenant_id=? AND start_date<=? AND end_date>=? ORDER BY start_date,id',
            [$tenant['id'], $to, $from]
        );
        $result['hours'] = Database::query(
            'SELECT day_of_week,start_time,end_time,is_available FROM availability WHERE tenant_id=? AND staff_id IS NULL ORDER BY day_of_week,start_time',
            [$tenant['id']]
        );
        foreach ($result['hours'] as &$hour) {
            $hour['day_of_week'] = (int) $hour['day_of_week'];
            $hour['is_available'] = (bool) $hour['is_available'];
        }
        unset($hour);

        // Capacity businesses open on the days they have slots, not on their weekly hours.
        $days = array_column(array_filter($result['hours'], static fn ($hour) => $hour['is_available']), 'day_of_week');
        if ($tenant['booking_pattern'] === 'capacity') $days = array_column(
            Database::query('SELECT DISTINCT day_of_week FROM capacity_slots WHERE tenant_id=? AND is_active=1', [$tenant['id']]),
            'day_of_week'
        );
        $result['available_weekdays'] = array_values(array_unique(array_map('intval', $days)));
        sort($result['available_weekdays']);

        return $result;
    }

    public static function export(ActorContext $actor, array $input, bool $crossBusiness = false): array
    {
        $actor = BookingCommands::currentActor($actor);
        if (!$actor->isStaff() || !in_array('bookings:export', $actor->scopes, true)) throw new OperationException('forbidden', 403);

        // Exports bypass cursor paging and read one capped block; the total reports truncation.
        $input['_export_limit'] = true;
        unset($input['cursor'], $input['_offset']);
        $page = $crossBusiness ? self::operatorListing($actor, $input) : self::listing($actor, $input);
        $rows = array_slice($page['bookings'], 0, 10000);
        $headers = ['Date', 'Time', 'End Time', 'Customer', 'Email', 'Service', 'Status'];
        if ($crossBusiness) $headers[] = 'Business';

        // The BOM makes Excel open the UTF-8 file with the right encoding.
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $headers, escape: '');
        foreach ($rows as $booking) {
            $row = [
                substr($booking['start_datetime'], 0, 10),
                substr($booking['start_datetime'], 11, 5),
                substr($booking['end_datetime'], 11, 5),
                $booking['customer']['name'],
                $booking['customer']['email'],
                $booking['service_name'] ?? $booking['resource_name'] ?? $booking['event_name'] ?? '',
                $booking['status']
            ];
            if ($crossBusiness) $row[] = $booking['business_name'];
            // Prefix formula-like cells so spreadsheets treat customer input as text (CSV injection).
            fputcsv(
                $stream,
                array_map(static fn ($value) => is_string($value) && preg_match('/^[=+@\-\t\r]/', $value) ? "'" . $value : $value, $row),
                escape: ''
            );
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return [
            'filename' => 'bookings-export-' . gmdate('Y-m-d') . '.csv',
            'media_type' => 'text/csv',
            'content' => $content,
            'count' => count($rows),
            'truncated' => $page['total'] > 10000,
            'timezone' => $page['timezone']
        ];
    }

    private static function filters(array $tenant, array $input): array
    {
        $where = ['b.tenant_id = ?'];
        $bindings = [$tenant['id']];
        if (!empty($input['_calendar'])) $where[] = "b.status != 'cancelled' AND (b.status != 'rescheduled' OR NULLIF(b.rescheduled_to_id,'') IS NULL)";
        foreach (['status', 'staff_id', 'customer_id', 'event_id'] as $key) if (!empty($input[$key])) {
            $where[] = "b.$key = ?";
            $bindings[] = $input[$key];
        }
        // The calendar wants anything overlapping the range; the list wants bookings starting in it.
        if (!empty($input['from'])) {
            $where[] = !empty($input['_calendar']) ? 'b.end_datetime > ?' : 'b.start_datetime >= ?';
            $bindings[] = BookingDraft::date($input['from']) . ' 00:00:00';
        }
        if (!empty($input['to'])) {
            $where[] = 'b.start_datetime < ?';
            $bindings[] = (new \DateTimeImmutable(BookingDraft::date($input['to'])))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        if (!empty($input['search'])) {
            $where[] = '(COALESCE(b.customer_name_snapshot,c.name) LIKE ? OR COALESCE(b.customer_email_snapshot,c.email) LIKE ? OR b.id = ?)';
            // Escape LIKE wildcards so a search for "50%" matches literally.
            $q = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], BookingCommands::text($input, 'search', 200)) . '%';
            $where[count($where) - 1] = str_replace('LIKE ?', 'LIKE ? ESCAPE \'!\'', $where[count($where) - 1]);
            array_push($bindings, $q, $q, $input['search']);
        }

        return [$where, $bindings];
    }
}
