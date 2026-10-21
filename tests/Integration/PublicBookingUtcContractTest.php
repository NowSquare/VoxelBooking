<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\Ulid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Canonical public instants must survive the persisted naive DATETIME round trip. */
final class PublicBookingUtcContractTest extends TestCase
{
    private string $baseUrl;
    private string $tenantId;
    private string $slug;
    private array $capabilities = [];

    protected function setUp(): void
    {
        TestFixtures::provision();
        $this->baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');
        $this->tenantId = Ulid::generate();
        $this->slug = 'test-utc-' . strtolower(substr($this->tenantId, -10));
        Database::execute('DELETE FROM rate_limits');
    }

    protected function tearDown(): void
    {
        if (isset($this->tenantId)) {
            Database::execute('DELETE FROM tenants WHERE id = ?', [$this->tenantId]);
        }
    }

    public static function bookingRanges(): array
    {
        return [
            'timeslot Amsterdam persisted end ambiguity' => ['timeslot', 'Europe/Amsterdam', '2026-10-25 01:30:00', '2026-10-25 02:30:00', 60],
            'timeslot Sydney overlap' => ['timeslot', 'Australia/Sydney', '2027-04-04 02:30:00', '2027-04-04 03:00:00', 30],
            'timeslot New York overlap' => ['timeslot', 'America/New_York', '2026-11-01 01:30:00', '2026-11-01 02:00:00', 30],
            'normal timeslot' => ['timeslot', 'Europe/Amsterdam', '2026-12-12 10:30:00', '2026-12-12 11:00:00', 30],
            'capacity overlap' => ['capacity', 'Europe/Amsterdam', '2026-10-25 02:30:00', '2026-10-25 03:00:00', 30],
            'resource spans offset change' => ['resource', 'Europe/Amsterdam', '2026-10-24 00:00:00', '2026-10-26 00:00:00', 30],
            'event overnight fall-back' => ['event', 'Europe/Amsterdam', '2026-10-24 23:30:00', '2026-10-25 02:30:00', 30],
        ];
    }

    #[DataProvider('bookingRanges')]
    public function test_create_manage_and_reschedule_share_php_instants_from_persisted_wall_values(string $pattern, string $timezone, string $start, string $end, int $duration): void
    {
        Database::execute(
            "INSERT INTO tenants (id, slug, name, email, status, booking_pattern, timezone, requires_consent, min_advance_hours, max_advance_days, allow_rescheduling, rescheduling_hours_before)
             VALUES (?, ?, 'UTC Contract', 'utc@example.test', 'active', ?, ?, 0, 0, 730, 1, 0)",
            [$this->tenantId, $this->slug, $pattern, $timezone]
        );
        $date = substr($start, 0, 10);
        $endDate = substr($end, 0, 10);
        $nextDate = (new \DateTimeImmutable($date))->modify('+7 days')->format('Y-m-d');
        $nextEndDate = (new \DateTimeImmutable($endDate))->modify('+7 days')->format('Y-m-d');
        $identifier = Ulid::generate();
        $payload = ['__ts' => (time() - 10) * 1000, 'customer' => ['name' => 'UTC Customer', 'email' => 'utc@example.test']];

        if ($pattern === 'timeslot') {
            Database::execute("INSERT INTO services (id, tenant_id, name, duration_minutes, is_active, requires_staff) VALUES (?, ?, 'UTC Service', ?, 1, 0)", [$identifier, $this->tenantId, $duration]);
            for ($day = 0; $day < 7; $day++) {
                Database::execute("INSERT INTO availability (id, tenant_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, '00:00', '23:59')", [Ulid::generate(), $this->tenantId, $day]);
            }
            $payload += ['service_id' => $identifier, 'start_datetime' => str_replace(' ', 'T', $start)];
            $target = ['new_date' => $nextDate, 'new_time' => substr($start, 11, 5)];
        } elseif ($pattern === 'capacity') {
            $dow = (int) (new \DateTimeImmutable($date))->format('N') - 1;
            Database::execute("INSERT INTO capacity_slots (id, tenant_id, day_of_week, start_time, end_time, max_capacity, min_party_size, max_party_size, is_active) VALUES (?, ?, ?, ?, ?, 10, 1, 10, 1)", [$identifier, $this->tenantId, $dow, substr($start, 11), substr($end, 11)]);
            $payload += ['slot_id' => $identifier, 'date' => $date, 'party_size' => 1];
            $target = ['slot_id' => $identifier, 'date' => $nextDate];
        } elseif ($pattern === 'resource') {
            Database::execute("INSERT INTO resources (id, tenant_id, name, capacity, min_stay_nights, max_stay_nights, price_per_night, is_active) VALUES (?, ?, 'UTC Room', 2, 1, 30, 100, 1)", [$identifier, $this->tenantId]);
            $payload += ['resource_id' => $identifier, 'check_in' => $date, 'check_out' => $endDate, 'guest_count' => 1];
            $target = ['check_in' => $nextDate, 'check_out' => $nextEndDate];
        } else {
            Database::execute("INSERT INTO events (id, tenant_id, name, start_datetime, end_datetime, max_participants, is_recurring, rrule, is_active) VALUES (?, ?, 'UTC Event', ?, ?, 10, 1, 'FREQ=WEEKLY;COUNT=3', 1)", [$identifier, $this->tenantId, $start, $end]);
            $payload += ['event_id' => $identifier, 'date' => $date, 'spot_count' => 1];
            $target = ['event_id' => $identifier, 'date' => $nextDate];
        }

        $created = $this->request('POST', '/api/' . $this->slug . '/bookings', $payload, 201)['booking'];
        $row = Database::query('SELECT * FROM bookings WHERE id = ? AND tenant_id = ?', [$created['id'], $this->tenantId])[0];
        $this->assertSame($start, $row['start_datetime']);
        $this->assertSame($end, $row['end_datetime']);
        if ($pattern === 'resource') {
            // A stay is local dates, never a UTC midnight that east of UTC names the previous day.
            $this->assertSame($date, $created['check_in']);
            $this->assertSame($endDate, $created['check_out']);
            $this->assertArrayNotHasKey('start_utc', $created);
            $this->assertArrayNotHasKey('end_utc', $created);
            $managed = $this->request('GET', '/api/' . $this->slug . '/bookings/' . $created['id'])['booking'];
            $this->assertStayDates($managed, $date, $endDate);
            $rescheduled = $this->request('POST', '/api/' . $this->slug . '/bookings/' . $created['id'] . '/reschedule', $target)['new_booking'];
            $this->assertStayDates($rescheduled, $nextDate, $nextEndDate);
            return;
        }
        $this->assertCanonicalRange($created, $row, $timezone);
        $managed = $this->request('GET', '/api/' . $this->slug . '/bookings/' . $created['id'])['booking'];
        $this->assertCanonicalRange($managed, $row, $timezone);
        $this->assertSame($created['start_utc'], $managed['start_utc']);
        $this->assertSame($created['end_utc'], $managed['end_utc']);

        $rescheduled = $this->request('POST', '/api/' . $this->slug . '/bookings/' . $created['id'] . '/reschedule', $target)['new_booking'];
        $replacement = Database::query('SELECT * FROM bookings WHERE id = ? AND tenant_id = ?', [$rescheduled['id'], $this->tenantId])[0];
        $this->assertCanonicalRange($rescheduled, $replacement, $timezone);
        if ($pattern === 'event') {
            $this->assertSame($nextDate . ' 23:30:00', $replacement['start_datetime']);
            $this->assertSame($nextEndDate . ' 02:30:00', $replacement['end_datetime']);
        }
        $reloaded = $this->request('GET', '/api/' . $this->slug . '/bookings/' . $rescheduled['id'])['booking'];
        $this->assertSame($rescheduled['start_utc'], $reloaded['start_utc']);
        $this->assertSame($rescheduled['end_utc'], $reloaded['end_utc']);
    }

    public function test_conflict_alternatives_keep_canonical_overlap_instants_through_recovery(): void
    {
        $timezone = 'Europe/Amsterdam';
        $date = '2026-10-25';
        $serviceId = Ulid::generate();
        Database::execute(
            "INSERT INTO tenants (id, slug, name, email, status, booking_pattern, timezone, requires_consent, min_advance_hours, max_advance_days)
             VALUES (?, ?, 'UTC Conflict', 'utc@example.test', 'active', 'timeslot', ?, 0, 0, 730)",
            [$this->tenantId, $this->slug, $timezone]
        );
        Database::execute("INSERT INTO services (id, tenant_id, name, duration_minutes, is_active, requires_staff, is_virtual) VALUES (?, ?, 'Virtual Service', 30, 1, 0, 1)", [$serviceId, $this->tenantId]);
        Database::execute("INSERT INTO availability (id, tenant_id, day_of_week, start_time, end_time) VALUES (?, ?, 6, '02:00', '04:30')", [Ulid::generate(), $this->tenantId]);
        $path = '/api/' . $this->slug;
        $payload = ['service_id' => $serviceId, 'start_datetime' => $date . 'T02:00:00', '__ts' => (time() - 10) * 1000,
            'customer' => ['name' => 'Recovery Customer', 'email' => 'recovery@example.test']];
        $this->request('POST', $path . '/bookings', $payload, 201);

        $conflict = $this->request('POST', $path . '/bookings', $payload, 409);
        $this->assertSame('slot_unavailable', $conflict['error']);
        $this->assertCount(3, $conflict['alternatives'], 'Recovery retains the three-alternative limit');
        $available = $this->request('GET', $path . '/availability?date=' . $date . '&service_id=' . $serviceId)['slots'];
        $this->assertSame('02:30', $conflict['alternatives'][0]['time']);
        foreach ($conflict['alternatives'] as $index => $alternative) {
            $this->assertArrayHasKey('start_utc', $alternative);
            $this->assertArrayHasKey('end_utc', $alternative);
            foreach (['time', 'end_time', 'staff_id', 'start_utc', 'end_utc'] as $field) {
                $this->assertSame($available[$index][$field], $alternative[$field], 'Conflict and availability must agree on ' . $field);
            }
        }
        $alternative = $conflict['alternatives'][0];
        $this->assertSame('2026-10-25T01:30:00Z', $alternative['start_utc'], 'PHP resolves this repeated Amsterdam hour to the later instant');

        $payload['start_datetime'] = $date . 'T' . $alternative['time'] . ':00';
        $recovered = $this->request('POST', $path . '/bookings', $payload, 201)['booking'];
        $managed = $this->request('GET', $path . '/bookings/' . $recovered['id'])['booking'];
        foreach (['start_utc', 'end_utc'] as $field) {
            $this->assertSame($alternative[$field], $recovered[$field]);
            $this->assertSame($alternative[$field], $managed[$field]);
        }
    }

    private function assertStayDates(array $response, string $checkIn, string $checkOut): void
    {
        $this->assertSame($checkIn, $response['check_in']);
        $this->assertSame($checkOut, $response['check_out']);
        $this->assertSame((int) (new \DateTimeImmutable($checkIn))->diff(new \DateTimeImmutable($checkOut))->days, $response['nights']);
        $this->assertArrayNotHasKey('start_utc', $response);
        $this->assertArrayNotHasKey('end_utc', $response);
    }

    private function assertCanonicalRange(array $response, array $row, string $timezone): void
    {
        foreach (['start', 'end'] as $bound) {
            $this->assertArrayHasKey($bound . '_utc', $response);
            $expected = (new \DateTimeImmutable($row[$bound . '_datetime'], new \DateTimeZone($timezone)))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            $this->assertSame($expected, $response[$bound . '_utc']);
        }
    }

    private function request(string $method, string $path, ?array $payload = null, int $status = 200): array
    {
        $headers=['Content-Type: application/json','Origin: '.$this->baseUrl];
        if(preg_match('~/bookings/([^/?]+)~',$path,$match)&&isset($this->capabilities[$match[1]]))$headers[]='X-Customer-Capability: '.$this->capabilities[$match[1]];
        $curl = curl_init($this->baseUrl . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers]);
        if ($payload !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
        $body = (string) curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $this->assertSame($status, $code, $body);
        $decoded=json_decode($body,true,flags:JSON_THROW_ON_ERROR);
        $booking=$decoded['booking']??$decoded['new_booking']??null;
        if(is_array($booking)&&isset($booking['manage_url'])){parse_str(parse_url($booking['manage_url'],PHP_URL_FRAGMENT)??'',$fragment);$this->capabilities[$booking['id']]=$fragment['access'];}
        return $decoded;
    }
}
