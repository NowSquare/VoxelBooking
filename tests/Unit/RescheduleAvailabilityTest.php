<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Booking\BookingApiController;
use App\Engine\CapacityCalculator;
use App\Engine\Database;
use App\Engine\EventCalculator;
use App\Engine\Locale;
use App\Engine\Request;
use App\Engine\ResourceCalculator;
use App\Engine\TimeSlotCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RescheduleAvailabilityTest extends TestCase
{
    private \ReflectionProperty $connection;
    private mixed $originalConnection;
    private array $originalLocale;
    private string $date;
    private array $tenant;

    protected function setUp(): void
    {
        $this->connection = new \ReflectionProperty(Database::class, 'pdo');
        $this->originalConnection = $this->connection->getValue();
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->connection->setValue(null, $pdo);
        foreach ([
            'tenants (id TEXT, slug TEXT, status TEXT, booking_pattern TEXT, timezone TEXT, min_advance_hours INT, max_advance_days INT, slot_duration_minutes INT, allow_rescheduling INT, rescheduling_hours_before INT, currency TEXT)',
            'services (id TEXT, tenant_id TEXT, duration_minutes INT, is_active INT, requires_staff INT)',
            'staff (id TEXT, tenant_id TEXT, is_active INT, sort_order INT)',
            'service_staff (service_id TEXT, staff_id TEXT)',
            'availability (tenant_id TEXT, staff_id TEXT, day_of_week INT, start_time TEXT, end_time TEXT, is_available INT)',
            'blocked_dates (tenant_id TEXT, staff_id TEXT, resource_id TEXT, start_date TEXT, end_date TEXT)',
            'resources (id TEXT, tenant_id TEXT, name TEXT, is_active INT, capacity INT, min_stay_nights INT, max_stay_nights INT, price_per_night REAL)',
            'seasonal_pricing (tenant_id TEXT, resource_id TEXT, start_date TEXT, end_date TEXT, price_per_night REAL, label TEXT)',
            'capacity_slots (id TEXT, tenant_id TEXT, day_of_week INT, start_time TEXT, end_time TEXT, max_capacity INT, min_party_size INT, max_party_size INT, label TEXT, is_active INT)',
            'events (id TEXT, tenant_id TEXT, name TEXT, description TEXT, location TEXT, price REAL, start_datetime TEXT, end_datetime TEXT, is_active INT, is_recurring INT, rrule TEXT, max_participants INT, allow_waitlist INT, waitlist_max INT)',
            'bookings (id TEXT, tenant_id TEXT, booking_pattern TEXT, service_id TEXT, staff_id TEXT, resource_id TEXT, event_id TEXT, start_datetime TEXT, end_datetime TEXT, status TEXT, party_size INT, rescheduled_to_id TEXT, customer_id TEXT)',
        ] as $table) $pdo->exec('CREATE TABLE ' . $table);

        \Tests\Support\CustomerAccessTest::createSqliteCapabilities($pdo);
        $pdo->exec("CREATE TABLE customers (id TEXT, tenant_id TEXT, is_anonymized INT)");
        $pdo->exec("INSERT INTO customers VALUES ('customer','ours',0)");
        $this->originalLocale = [];
        foreach ((new \ReflectionClass(Locale::class))->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            $this->originalLocale[$property->getName()] = $property->getValue();
        }
        Locale::reset();
        Locale::init(dirname(__DIR__, 2));
        Locale::setSystemDefaults([]);

        $this->date = (new \DateTimeImmutable('+14 days', new \DateTimeZone('UTC')))->format('Y-m-d');
        $dow = (int) (new \DateTimeImmutable($this->date))->format('N') - 1;
        Database::execute("INSERT INTO tenants VALUES ('ours', 'ours', 'active', 'timeslot', 'UTC', 0, 365, 30, 1, 0, 'EUR')");
        $this->tenant = Database::query('SELECT * FROM tenants')[0];
        Database::execute("INSERT INTO services VALUES ('service', 'ours', 60, 1, 1)");
        Database::execute("INSERT INTO staff VALUES ('staff', 'ours', 1, 0)");
        Database::execute("INSERT INTO service_staff VALUES ('service', 'staff')");
        Database::execute("INSERT INTO availability VALUES ('ours', NULL, ?, '10:00:00', '12:00:00', 1)", [$dow]);
        Database::execute("INSERT INTO resources VALUES ('room', 'ours', 'Room', 1, 4, 1, 30, 100)");
        Database::execute("INSERT INTO capacity_slots VALUES ('slot', 'ours', ?, '18:00:00', '19:00:00', 4, 1, 4, 'Dinner', 1)", [$dow]);
        Database::execute("INSERT INTO events VALUES ('event', 'ours', 'Workshop', '', '', 0, ?, ?, 1, 0, NULL, 4, 0, 0)", [$this->date . ' 18:00:00', $this->date . ' 19:00:00']);
    }

    protected function tearDown(): void
    {
        $this->connection->setValue(null, $this->originalConnection);
        foreach ($this->originalLocale as $name => $value) (new \ReflectionProperty(Locale::class, $name))->setValue(null, $value);
    }

    public static function occupancyStates(): array
    {
        return [
            'confirmed' => ['confirmed', null, false],
            'legacy rescheduled' => ['rescheduled', null, false],
            'legacy empty link' => ['rescheduled', '', false],
            'replaced origin' => ['rescheduled', 'replacement', true],
            'cancelled' => ['cancelled', null, true],
        ];
    }

    #[DataProvider('occupancyStates')]
    public function test_timeslot_history_releases_inventory_but_legacy_rows_still_occupy(string $status, ?string $replacement, bool $available): void
    {
        $this->booking('original', 'timeslot', $status, $replacement);
        $slots = TimeSlotCalculator::getAvailableSlots($this->tenant, $this->date, 'service', 'staff');
        $this->assertSame($available, in_array('10:30', array_column($slots['slots'], 'time'), true));
    }

    #[DataProvider('occupancyStates')]
    public function test_resource_month_and_range_share_occupancy_rules(string $status, ?string $replacement, bool $available): void
    {
        $this->booking('original', 'resource', $status, $replacement);
        $range = ResourceCalculator::checkAvailability($this->tenant, 'room', $this->day(1), $this->day(4));
        $this->assertSame($available, $range['available']);
        $month = ResourceCalculator::getAvailableDates($this->tenant, 'room', (int) substr($this->date, 0, 4), (int) substr($this->date, 5, 2));
        $this->assertSame($available, in_array($this->date, $month['dates'], true));
    }

    #[DataProvider('occupancyStates')]
    public function test_capacity_list_month_and_confirmation_share_occupancy_rules(string $status, ?string $replacement, bool $available): void
    {
        $this->booking('original', 'capacity', $status, $replacement);
        $slots = CapacityCalculator::getAvailableSlots($this->tenant, $this->date, 4);
        $this->assertSame($available, count($slots['slots']) === 1);
        $month = CapacityCalculator::getAvailableDates($this->tenant, substr($this->date, 0, 7), 4);
        $this->assertSame($available, in_array($this->date, $month['dates'], true));
        $check = CapacityCalculator::checkSlotAvailability($this->tenant, 'slot', $this->date, 4);
        $this->assertSame($available, $check['available']);
    }

    #[DataProvider('occupancyStates')]
    public function test_event_list_detail_and_confirmation_release_replaced_origins(string $status, ?string $replacement, bool $available): void
    {
        $this->booking('original', 'event', $status, $replacement);
        $expected = $available ? 4 : 0;
        $this->assertSame($expected, EventCalculator::getUpcomingEvents($this->tenant)['events'][0]['remaining']);
        $this->assertSame($expected, EventCalculator::getEventDetail($this->tenant, 'event')['event']['remaining']);
        $this->assertSame($available, EventCalculator::checkAvailability($this->tenant, 'event', $this->date, 4)['available']);
    }

    #[DataProvider('occupancyStates')]
    public function test_daily_customer_limit_does_not_count_replaced_history(string $status, ?string $replacement, bool $available): void
    {
        $this->booking('original', 'timeslot', $status, $replacement);
        Database::execute("UPDATE bookings SET customer_id = 'customer'");
        $this->assertSame($available ? 0 : 1, \App\Application\Bookings\BookingCommands::customerBookingCountForDate( 'customer', 'ours', $this->date));
        $this->assertSame(0, \App\Application\Bookings\BookingCommands::customerBookingCountForDate( 'other', 'ours', $this->date));
        $this->assertSame(0, \App\Application\Bookings\BookingCommands::customerBookingCountForDate( 'customer', 'theirs', $this->date));
        $this->assertSame(0, \App\Application\Bookings\BookingCommands::customerBookingCountForDate( 'customer', 'ours', $this->day(1)));
    }

    public function test_only_the_new_timeslot_in_a_reschedule_chain_consumes_inventory(): void
    {
        $this->booking('original', 'timeslot', 'rescheduled', 'replacement');
        $this->booking('replacement', 'timeslot');
        Database::execute("UPDATE bookings SET start_datetime = ?, end_datetime = ? WHERE id = 'replacement'", [$this->date . ' 11:00:00', $this->date . ' 12:00:00']);
        $times = array_column(TimeSlotCalculator::getAvailableSlots($this->tenant, $this->date, 'service', 'staff')['slots'], 'time');
        $this->assertContains('10:00', $times);
        $this->assertNotContains('11:00', $times);
    }

    public function test_authorized_timeslot_preview_excludes_only_the_original_booking(): void
    {
        $this->booking('original', 'timeslot');
        $query = ['date' => $this->date, 'service_id' => 'service', 'staff_id' => 'staff'];
        $ordinary = $this->read('availability', $query);
        $this->assertNotContains('10:30', array_column($ordinary['slots'], 'time'));
        $preview = $this->read('availability', $query + ['reschedule_booking_id' => 'original']);
        $this->assertContains('10:30', array_column($preview['slots'], 'time'));
        $this->booking('other', 'timeslot');
        $preview = $this->read('availability', $query + ['reschedule_booking_id' => 'original']);
        $this->assertNotContains('10:30', array_column($preview['slots'], 'time'));
        $this->assertSame('confirmed', Database::query("SELECT status FROM bookings WHERE id = 'original'")[0]['status']);
    }

    public function test_authorized_timeslot_month_can_show_a_day_occupied_by_the_original(): void
    {
        Database::execute("UPDATE availability SET end_time = '11:00:00'");
        $this->booking('original', 'timeslot');
        $query = $this->month() + ['service_id' => 'service', 'staff_id' => 'staff'];
        $this->assertNotContains($this->date, $this->read('availableDates', $query)['dates']);
        $this->assertContains($this->date, $this->read('availableDates', $query + ['reschedule_booking_id' => 'original'])['dates']);
    }

    public function test_authorized_resource_preview_supports_an_overlapping_stay(): void
    {
        $this->booking('original', 'resource');
        $query = ['check_in' => $this->day(1), 'check_out' => $this->day(4), 'guests' => 1];
        $this->assertFalse($this->read('resourceAvailability', $query, 'room')['available']);
        $this->assertTrue($this->read('resourceAvailability', $query + ['reschedule_booking_id' => 'original'], 'room')['available']);
        $month = $this->read('resourceAvailability', $this->month() + ['reschedule_booking_id' => 'original'], 'room');
        $this->assertContains($this->date, $month['dates']);
        $this->booking('other', 'resource');
        $this->assertFalse($this->read('resourceAvailability', $query + ['reschedule_booking_id' => 'original'], 'room')['available']);
    }

    public function test_authorized_capacity_preview_restores_only_the_original_party(): void
    {
        $this->booking('original', 'capacity');
        $query = ['date' => $this->date, 'party_size' => 4];
        $this->assertSame([], $this->read('capacitySlots', $query)['slots']);
        $preview = $this->read('capacitySlots', $query + ['reschedule_booking_id' => 'original']);
        $this->assertCount(1, $preview['slots']);
        $this->assertSame(4, $preview['slots'][0]['remaining']);
        $month = $this->read('capacityAvailableDates', $this->month() + ['party_size' => 4, 'reschedule_booking_id' => 'original']);
        $this->assertContains($this->date, $month['dates']);
    }

    public static function contextEndpoints(): array
    {
        return [
            ['availability', 'timeslot', null], ['availableDates', 'timeslot', null],
            ['resourceAvailability', 'resource', 'room'],
            ['capacitySlots', 'capacity', null], ['capacityAvailableDates', 'capacity', null],
        ];
    }

    #[DataProvider('contextEndpoints')]
    public function test_preview_context_cannot_exclude_an_unknown_or_other_tenant_booking(string $method, string $pattern, ?string $resource): void
    {
        $this->booking('original', $pattern);
        $query = $this->month() + ['date' => $this->date, 'party_size' => 4, 'service_id' => 'service', 'staff_id' => 'staff'];
        $this->read($method, $query + ['reschedule_booking_id' => 'unknown'], $resource, 403);
        Database::execute("UPDATE bookings SET tenant_id = 'theirs' WHERE id = 'original'");
        $this->read($method, $query + ['reschedule_booking_id' => 'original'], $resource, 403);
    }

    #[DataProvider('contextEndpoints')]
    public function test_preview_requires_a_current_reschedulable_booking(string $method, string $pattern, ?string $resource): void
    {
        $this->booking('original', $pattern);
        Database::execute("UPDATE tenants SET allow_rescheduling = 0");
        $query = $this->month() + ['date' => $this->date, 'party_size' => 4, 'service_id' => 'service', 'staff_id' => 'staff', 'reschedule_booking_id' => 'original'];
        $this->read($method, $query, $resource, 409);
        Database::execute("UPDATE tenants SET allow_rescheduling = 1");
        Database::execute("UPDATE tenants SET rescheduling_hours_before = 10000");
        $this->read($method, $query, $resource, 409);
        Database::execute("UPDATE tenants SET rescheduling_hours_before = 0");
        Database::execute("UPDATE bookings SET status = 'cancelled'");
        $this->read($method, $query, $resource, 409);
    }

    public function test_preview_rejects_mismatched_pattern_service_staff_resource_and_party_size(): void
    {
        $this->booking('original', 'timeslot');
        $query = ['date' => $this->date, 'reschedule_booking_id' => 'original', 'service_id' => 'service', 'staff_id' => 'staff'];
        $this->read('availability', array_replace($query, ['service_id' => 'other']), status: 422);
        $this->read('availability', array_replace($query, ['staff_id' => 'other']), status: 422);
        $this->read('resourceAvailability', $this->month() + ['reschedule_booking_id' => 'original'], 'room', 422);
        Database::execute("UPDATE bookings SET booking_pattern = 'resource', resource_id = 'room'");
        $this->read('resourceAvailability', $this->month() + ['reschedule_booking_id' => 'original'], 'other-room', 422);
        $this->read('resourceAvailability', ['check_in' => $this->date, 'check_out' => $this->day(3), 'guests' => 4, 'reschedule_booking_id' => 'original'], 'room', 422);
        Database::execute("UPDATE bookings SET booking_pattern = 'capacity', party_size = 4");
        $this->read('capacitySlots', ['date' => $this->date, 'party_size' => 1, 'reschedule_booking_id' => 'original'], status: 422);
    }

    public function test_arbitrary_exclusion_parameter_does_not_change_ordinary_reads(): void
    {
        $this->booking('original', 'timeslot');
        $data = $this->read('availability', ['date' => $this->date, 'service_id' => 'service', 'staff_id' => 'staff', 'exclude_booking_id' => 'original']);
        $this->assertNotContains('10:30', array_column($data['slots'], 'time'));
    }

    public static function canonicalSlotTimes(): array
    {
        return [
            'Amsterdam overlap' => ['Europe/Amsterdam', '2026-10-25', '02:30', '03:00', '2026-10-25T01:30:00Z'],
            'Sydney overlap' => ['Australia/Sydney', '2027-04-04', '02:30', '03:00', '2027-04-03T16:30:00Z'],
            'New York overlap' => ['America/New_York', '2026-11-01', '01:30', '02:00', '2026-11-01T05:30:00Z'],
            'normal date' => ['Europe/Amsterdam', '2026-12-12', '10:30', '11:00', '2026-12-12T09:30:00Z'],
        ];
    }

    #[DataProvider('canonicalSlotTimes')]
    public function test_public_slot_reads_include_canonical_utc_without_changing_wall_time_fields(string $timezone, string $date, string $start, string $end, string $expectedStart): void
    {
        $dow = (int) (new \DateTimeImmutable($date))->format('N') - 1;
        Database::execute('UPDATE tenants SET timezone = ?, max_advance_days = 730', [$timezone]);
        Database::execute('UPDATE services SET duration_minutes = 30');
        Database::execute('UPDATE availability SET day_of_week = ?, start_time = ?, end_time = ?', [$dow, $start, $end]);
        Database::execute('UPDATE capacity_slots SET day_of_week = ?, start_time = ?, end_time = ?', [$dow, $start . ':00', $end . ':00']);

        foreach (['availability', 'capacitySlots'] as $method) {
            $data = $this->read($method, ['date' => $date, 'service_id' => 'service', 'staff_id' => 'staff']);
            $this->assertNotEmpty($data['slots']);
            $slot = $data['slots'][0];
            $this->assertSame($start, $slot['time']);
            $this->assertSame($end, $slot['end_time']);
            $this->assertArrayHasKey('start_utc', $slot);
            $this->assertArrayHasKey('end_utc', $slot);
            $this->assertSame($expectedStart, $slot['start_utc']);
            $this->assertSame((new \DateTimeImmutable($date . ' ' . $end, new \DateTimeZone($timezone)))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), $slot['end_utc']);
        }
    }

    public function test_event_occurrence_utc_uses_full_overnight_end_date(): void
    {
        Database::execute("UPDATE tenants SET timezone = 'Europe/Amsterdam', max_advance_days = 730");
        Database::execute("UPDATE events SET start_datetime = '2026-10-23 23:30:00', end_datetime = '2026-10-24 02:30:00'");
        $list = $this->read('events', []);
        $detail = $this->read('eventDetail', [], 'event');
        foreach ([$list['events'][0], $detail['event']] as $event) {
            $this->assertSame('2026-10-24 02:30:00', $event['end_datetime']);
            $this->assertArrayHasKey('start_utc', $event);
            $this->assertSame('2026-10-23T21:30:00Z', $event['start_utc']);
            $this->assertSame('2026-10-24T00:30:00Z', $event['end_utc']);
        }
    }

    public static function eventInstanceDates(): array
    {
        return ['one-off' => [false, '2026-10-24'], 'recurring across DST' => [true, '2026-10-31']];
    }

    #[DataProvider('eventInstanceDates')]
    public function test_events_preserve_configured_end_clock_and_calendar_day_offset_across_fall_back(bool $recurring, string $date): void
    {
        Database::execute("UPDATE tenants SET timezone = 'Europe/Amsterdam', max_advance_days = 730");
        Database::execute("UPDATE events SET start_datetime = '2026-10-24 23:30:00', end_datetime = '2026-10-25 02:30:00', is_recurring = ?, rrule = ?", [(int) $recurring, $recurring ? 'FREQ=WEEKLY;COUNT=3' : null]);
        $endDate = (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
        $list = $this->read('events', []);
        $detail = $this->read('eventDetail', ['date' => $date], 'event');
        $matches = array_values(array_filter($list['events'], fn($event) => $event['date'] === $date));
        $this->assertCount(1, $matches);
        foreach ([$matches[0], $detail['event']] as $event) {
            $this->assertSame($date . ' 23:30:00', $event['start_datetime']);
            $this->assertSame($endDate . ' 02:30:00', $event['end_datetime']);
            $this->assertSame((new \DateTimeImmutable($endDate . ' 02:30:00', new \DateTimeZone('Europe/Amsterdam')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), $event['end_utc']);
        }
    }

    public static function springGaps(): array
    {
        return [
            'Amsterdam' => ['Europe/Amsterdam', '2027-03-28'],
            'Sydney' => ['Australia/Sydney', '2027-10-03'],
            'New York' => ['America/New_York', '2027-03-14'],
        ];
    }

    #[DataProvider('springGaps')]
    public function test_nonexistent_spring_starts_do_not_produce_slots_or_monthly_dates(string $timezone, string $date): void
    {
        $dow = (int) (new \DateTimeImmutable($date))->format('N') - 1;
        Database::execute('UPDATE tenants SET timezone = ?, max_advance_days = 730', [$timezone]);
        Database::execute('UPDATE services SET duration_minutes = 30');
        Database::execute("UPDATE availability SET day_of_week = ?, start_time = '02:00:00', end_time = '03:00:00'", [$dow]);
        Database::execute("UPDATE capacity_slots SET day_of_week = ?, start_time = '02:30:00', end_time = '03:00:00'", [$dow]);
        $tenant = Database::query('SELECT * FROM tenants')[0];
        $this->assertSame([], TimeSlotCalculator::getAvailableSlots($tenant, $date, 'service', 'staff')['slots']);
        $this->assertNotContains($date, TimeSlotCalculator::getAvailableDates($tenant, (int) substr($date, 0, 4), (int) substr($date, 5, 2), 'service', 'staff'));
        $this->assertSame([], CapacityCalculator::getAvailableSlots($tenant, $date)['slots']);
        $this->assertNotContains($date, CapacityCalculator::getAvailableDates($tenant, substr($date, 0, 7))['dates']);
        $this->assertFalse(CapacityCalculator::checkSlotAvailability($tenant, 'slot', $date, 1)['available']);
    }

    #[DataProvider('springGaps')]
    public function test_nonexistent_recurring_event_start_cannot_offer_an_inverted_range(string $timezone, string $date): void
    {
        $originalDate = (new \DateTimeImmutable($date))->modify('-7 days')->format('Y-m-d');
        Database::execute('UPDATE tenants SET timezone = ?, max_advance_days = 730', [$timezone]);
        Database::execute("UPDATE events SET start_datetime = ?, end_datetime = ?, is_recurring = 1, rrule = 'FREQ=WEEKLY;COUNT=3'", [$originalDate . ' 02:30:00', $originalDate . ' 03:00:00']);
        $list = $this->read('events', []);
        $this->assertSame([], array_values(array_filter($list['events'], fn($event) => $event['date'] === $date)),
            'A nonexistent recurring start must not turn into a range ending before it begins');
        $this->read('eventDetail', ['date' => $date], 'event', 404);
        $tenant = Database::query('SELECT * FROM tenants')[0];
        $this->assertFalse(EventCalculator::checkAvailability($tenant, 'event', $date, 1)['available']);
    }

    private function read(string $method, array $query, ?string $resource = null, int $status = 200): array
    {
        $pattern = match ($method) {
            'resourceAvailability' => 'resource', 'capacitySlots', 'capacityAvailableDates' => 'capacity',
            'events', 'eventDetail' => 'event', default => 'timeslot',
        };
        Database::execute('UPDATE tenants SET booking_pattern = ? WHERE id = ?', [$pattern, 'ours']);
        $before = $_GET;
        $_GET = $query;
        $server = $_SERVER;
        $_SERVER['HTTP_X_CUSTOMER_CAPABILITY'] = '';
        $id = $query['reschedule_booking_id'] ?? null;
        if (is_string($id) && Database::query('SELECT id FROM bookings WHERE id = ? AND tenant_id = ?', [$id, 'ours'])) {
            $_SERVER['HTTP_X_CUSTOMER_CAPABILITY'] = \App\Engine\CustomerCapability::issueManage('ours', $id);
        }
        $request = new Request();
        $_SERVER = $server;
        $_GET = $before;
        $request->setAttribute('slug', 'ours');
        if ($resource !== null) $request->setAttribute('id', $resource);
        $response = (new BookingApiController())->{$method}($request);
        $this->assertSame($status, $response->getStatusCode(), $response->getBody());
        return json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function booking(string $id, string $pattern, string $status = 'confirmed', ?string $replacement = null): void
    {
        $start = $this->date . ($pattern === 'resource' ? ' 00:00:00' : ($pattern === 'timeslot' ? ' 10:00:00' : ' 18:00:00'));
        $end = $pattern === 'resource' ? $this->day(3) . ' 00:00:00' : $this->date . ($pattern === 'timeslot' ? ' 11:00:00' : ' 19:00:00');
        Database::execute('INSERT INTO bookings (id, tenant_id, booking_pattern, service_id, staff_id, resource_id, event_id, start_datetime, end_datetime, status, party_size, rescheduled_to_id, customer_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $id, 'ours', $pattern, $pattern === 'timeslot' ? 'service' : null, $pattern === 'timeslot' ? 'staff' : null,
            $pattern === 'resource' ? 'room' : null, $pattern === 'event' ? 'event' : null,
            $start, $end, $status, in_array($pattern, ['capacity', 'event'], true) ? 4 : 1, $replacement, 'customer',
        ]);
    }

    private function month(): array { return ['year' => (int) substr($this->date, 0, 4), 'month' => (int) substr($this->date, 5, 2)]; }
    private function day(int $offset): string { return (new \DateTimeImmutable($this->date))->modify('+' . $offset . ' days')->format('Y-m-d'); }
}
