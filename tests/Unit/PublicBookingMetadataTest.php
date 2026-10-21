<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Booking\BookingApiController;
use App\Engine\Database;
use App\Engine\Locale;
use App\Engine\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercise real controller queries and decoded JSON with an isolated database. */
final class PublicBookingMetadataTest extends TestCase
{
    private \ReflectionProperty $connection;
    private mixed $originalConnection;
    private array $originalLocaleState;

    protected function setUp(): void
    {
        $this->connection = new \ReflectionProperty(Database::class, 'pdo');
        $this->originalConnection = $this->connection->getValue();
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->connection->setValue(null, $pdo);
        foreach ([
            'tenants (id TEXT, slug TEXT, status TEXT, timezone TEXT, locale TEXT, booking_pattern TEXT, currency TEXT)',
            'services (id TEXT, tenant_id TEXT, name TEXT, description TEXT, duration_minutes INT, price TEXT, price_label TEXT, category TEXT, preparation_text TEXT, cover_image_path TEXT, color TEXT, is_virtual TEXT, requires_staff TEXT, is_active INT, sort_order INT)',
            'bookings (id TEXT, tenant_id TEXT, status TEXT, booking_pattern TEXT, start_datetime TEXT, end_datetime TEXT, party_size INT, service_id TEXT, staff_id TEXT, resource_id TEXT, event_id TEXT, customer_id TEXT, rescheduled_to_id TEXT, customer_timezone TEXT, customer_name_snapshot TEXT, customer_email_snapshot TEXT)',
            'staff (id TEXT, tenant_id TEXT, name TEXT)',
            'resources (id TEXT, tenant_id TEXT, name TEXT)',
            'events (id TEXT, tenant_id TEXT, name TEXT, location TEXT)',
            'customers (id TEXT, tenant_id TEXT, name TEXT, email TEXT, is_anonymized INT)',
        ] as $table) {
            $pdo->exec('CREATE TABLE ' . $table);
        }
        \Tests\Support\CustomerAccessTest::createSqliteCapabilities($pdo);
        Database::execute("INSERT INTO customers VALUES ('customer','ours','Test Customer','customer@example.com',0)");
        Database::execute("INSERT INTO tenants VALUES ('ours', 'ours', 'active', 'Europe/Amsterdam', 'en', 'timeslot', 'EUR')");
        Database::execute("INSERT INTO tenants VALUES ('theirs', 'theirs', 'active', 'Europe/Amsterdam', 'en', 'timeslot', 'EUR')");
        $this->originalLocaleState = [];
        foreach ((new \ReflectionClass(Locale::class))->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            $this->originalLocaleState[$property->getName()] = $property->getValue();
        }
        Locale::reset();
        Locale::init(dirname(__DIR__, 2));
        Locale::setSystemDefaults([]);
    }

    protected function tearDown(): void
    {
        $this->connection->setValue(null, $this->originalConnection);
        foreach ($this->originalLocaleState as $name => $value) {
            (new \ReflectionProperty(Locale::class, $name))->setValue(null, $value);
        }
    }

    public static function serviceFlags(): array
    {
        return [
            'physical without staff' => ['0', '0', false, false],
            'physical with staff' => ['0', '1', false, true],
            'virtual without staff' => ['1', '0', true, false],
            'virtual with staff' => ['1', '1', true, true],
        ];
    }

    #[DataProvider('serviceFlags')]
    public function test_service_catalog_returns_boolean_booking_flags(string $virtual, string $staff, bool $expectedVirtual, bool $expectedStaff): void
    {
        $this->seedService('service', $virtual, $staff);

        $response = (new BookingApiController())->services($this->request());
        $this->assertSame(200, $response->getStatusCode());
        $service = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR)['services'][0];
        $this->assertArrayHasKey('is_virtual', $service);
        $this->assertArrayHasKey('requires_staff', $service);
        $this->assertSame($expectedVirtual, $service['is_virtual']);
        $this->assertSame($expectedStaff, $service['requires_staff']);
        $this->assertSame('service', $service['id']);
        $this->assertSame('Bring a reference', $service['preparation_text']);
        $this->assertSame('/uploads/service.jpg', $service['cover_image_path']);
    }

    public function test_service_catalog_keeps_active_and_tenant_filters(): void
    {
        $this->seedService('visible', '0', '1');
        $this->seedService('inactive', '1', '0', active: 0);
        $this->seedService('rival', '1', '0', tenant: 'theirs');

        $response = (new BookingApiController())->services($this->request());
        $services = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR)['services'];
        $this->assertSame(['visible'], array_column($services, 'id'));
    }

    public static function managedVirtualFlags(): array
    {
        return [
            'physical service' => ['0', 1, false],
            'virtual service' => ['1', 1, true],
            'inactive virtual service' => ['1', 0, true],
        ];
    }

    #[DataProvider('managedVirtualFlags')]
    public function test_managed_booking_includes_boolean_service_virtuality(string $virtual, int $active, bool $expected): void
    {
        $this->seedService('service', $virtual, '1', active: $active);
        $this->seedBooking('service');

        $response = (new BookingApiController())->bookingDetail($this->request('booking'));
        $this->assertSame(200, $response->getStatusCode());
        $booking = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR)['booking'];
        $this->assertArrayHasKey('is_virtual', $booking);
        $this->assertSame($expected, $booking['is_virtual']);
        $this->assertSame('service', $booking['service_id']);
        $this->assertSame('2035-10-20', $booking['date']);
        $this->assertSame('10:00', $booking['time']);
    }

    public static function bookingsWithoutService(): array
    {
        return [
            'no service' => [null, 'timeslot'],
            'removed service' => ['missing', 'timeslot'],
            'resource booking' => [null, 'resource'],
            'capacity booking' => [null, 'capacity'],
            'event booking' => [null, 'event'],
        ];
    }

    #[DataProvider('bookingsWithoutService')]
    public function test_managed_booking_without_service_defaults_to_venue_time(?string $service, string $pattern): void
    {
        $this->seedBooking($service, $pattern);

        $response = (new BookingApiController())->bookingDetail($this->request('booking'));
        $this->assertSame(200, $response->getStatusCode());
        $booking = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR)['booking'];
        $this->assertArrayHasKey('is_virtual', $booking);
        $this->assertFalse($booking['is_virtual']);
        $this->assertNull($booking['service_name'], 'Related names must also stay within the booking business.');
    }

    public function test_managed_virtuality_cannot_come_from_another_tenant_service(): void
    {
        $this->seedService('rival', '1', '0', tenant: 'theirs');
        $this->seedBooking('rival');

        $response = (new BookingApiController())->bookingDetail($this->request('booking'));
        $booking = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR)['booking'];
        $this->assertArrayHasKey('is_virtual', $booking);
        $this->assertFalse($booking['is_virtual']);
        $this->assertNull($booking['service_name'], 'Related names must also stay within the booking business.');
    }

    public function test_managed_booking_still_rejects_another_tenant_booking(): void
    {
        $this->seedBooking(null);

        $response = (new BookingApiController())->bookingDetail($this->request('booking', 'theirs'));
        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('verification_required', $data['error']);
        $this->assertArrayNotHasKey('booking', $data);
    }

    public static function savedTimezones(): array
    {
        return [
            'explicit customer choice' => ['America/New_York'],
            'legacy absent choice' => [null],
            'legacy empty choice' => [''],
            'legacy unrecognized choice' => ['Old/Unrecognized_Zone'],
        ];
    }

    public static function venueWallRanges(): array
    {
        return [
            'Amsterdam overlap' => ['Europe/Amsterdam', '2026-10-25 02:30:00', '2026-10-25 03:00:00', '2026-10-25T01:30:00Z'],
            'Sydney overlap' => ['Australia/Sydney', '2027-04-04 02:30:00', '2027-04-04 03:00:00', '2027-04-03T16:30:00Z'],
            'New York overlap' => ['America/New_York', '2026-11-01 01:30:00', '2026-11-01 02:00:00', '2026-11-01T05:30:00Z'],
            'normal date' => ['Europe/Amsterdam', '2026-12-12 10:30:00', '2026-12-12 11:00:00', '2026-12-12T09:30:00Z'],
            'overnight event' => ['Europe/Amsterdam', '2026-10-24 23:30:00', '2026-10-25 02:30:00', '2026-10-24T21:30:00Z'],
        ];
    }

    #[DataProvider('venueWallRanges')]
    public function test_managed_booking_utc_metadata_matches_php_interpretation_of_full_stored_datetimes(string $timezone, string $start, string $end, string $expectedStart): void
    {
        $this->seedBooking(null);
        Database::execute('UPDATE tenants SET timezone = ? WHERE id = ?', [$timezone, 'ours']);
        Database::execute('UPDATE bookings SET start_datetime = ?, end_datetime = ? WHERE id = ?', [$start, $end, 'booking']);

        $response = (new BookingApiController())->bookingDetail($this->request('booking'));
        $this->assertSame(200, $response->getStatusCode());
        $booking = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR)['booking'];
        $this->assertArrayHasKey('start_utc', $booking);
        $this->assertArrayHasKey('end_utc', $booking);
        $this->assertSame($expectedStart, $booking['start_utc']);
        $this->assertSame((new \DateTimeImmutable($end, new \DateTimeZone($timezone)))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), $booking['end_utc']);
        $this->assertSame($start, $booking['start_datetime']);
        $this->assertSame($end, $booking['end_datetime']);
    }

    #[DataProvider('savedTimezones')]
    public function test_managed_booking_preserves_its_saved_timezone_metadata(?string $timezone): void
    {
        $this->seedBooking(null);
        Database::execute('UPDATE bookings SET customer_timezone = ? WHERE id = ?', [$timezone, 'booking']);

        $response = (new BookingApiController())->bookingDetail($this->request('booking'));
        $this->assertSame(200, $response->getStatusCode());
        $booking = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR)['booking'];
        $this->assertArrayHasKey('customer_timezone', $booking);
        $this->assertSame($timezone, $booking['customer_timezone']);
    }

    public static function replacementLinks(): array
    {
        return [
            'same customer and tenant' => ['replacement', 'ours', 'customer', 'customer', 'replacement'],
            'missing link' => [null, 'ours', 'customer', 'customer', null],
            'empty link' => ['', 'ours', 'customer', 'customer', null],
            'missing replacement' => ['missing', 'ours', 'customer', 'customer', null],
            'foreign tenant' => ['replacement', 'theirs', 'customer', 'customer', null],
            'different customer' => ['replacement', 'ours', 'other-customer', 'customer', null],
            'missing original customer' => ['replacement', 'ours', null, null, null],
            'self link' => ['booking', 'ours', 'customer', 'customer', null],
        ];
    }

    #[DataProvider('replacementLinks')]
    public function test_managed_booking_exposes_only_a_verified_replacement_link(?string $link, string $replacementTenant, ?string $replacementCustomer, ?string $originalCustomer, ?string $expected): void
    {
        $this->seedBooking(null);
        Database::execute('UPDATE bookings SET status = ?, customer_id = ?, rescheduled_to_id = ? WHERE id = ?', ['rescheduled', $originalCustomer, $link, 'booking']);
        Database::execute(
            "INSERT INTO bookings (id, tenant_id, customer_id, status, booking_pattern, start_datetime, end_datetime, party_size)
             VALUES ('replacement', ?, ?, 'confirmed', 'timeslot', '2035-10-21 10:00:00', '2035-10-21 10:30:00', 1)",
            [$replacementTenant, $replacementCustomer]
        );

        $response = (new BookingApiController())->bookingDetail($this->request('booking'));
        if ($originalCustomer === null) {
            $this->assertSame(403, $response->getStatusCode(), 'A booking with no current customer cannot confer authority.');
            return;
        }
        $this->assertSame(200, $response->getStatusCode());
        $booking = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR)['booking'];
        $this->assertArrayHasKey('rescheduled_to_id', $booking);
        $this->assertSame($expected, $booking['rescheduled_to_id']);
    }

    private function seedService(string $id, string $virtual, string $staff, string $tenant = 'ours', int $active = 1): void
    {
        Database::execute(
            "INSERT INTO services (id, tenant_id, name, duration_minutes, preparation_text, cover_image_path, is_virtual, requires_staff, is_active, sort_order)
             VALUES (?, ?, ?, 30, 'Bring a reference', 'uploads/service.jpg', ?, ?, ?, 0)",
            [$id, $tenant, $id, $virtual, $staff, $active]
        );
    }

    private function seedBooking(?string $service, string $pattern = 'timeslot'): void
    {
        Database::execute(
            "INSERT INTO bookings (id, tenant_id, status, booking_pattern, start_datetime, end_datetime, party_size, service_id, customer_id)
             VALUES ('booking', 'ours', 'confirmed', ?, '2035-10-20 10:00:00', '2035-10-20 10:30:00', 1, ?, 'customer')",
            [$pattern, $service]
        );
    }

    private function request(?string $bookingId = null, string $slug = 'ours'): Request
    {
        $before = $_SERVER;
        $_SERVER['HTTP_X_CUSTOMER_CAPABILITY'] = '';
        if ($bookingId !== null && Database::query('SELECT id FROM bookings WHERE id = ? AND tenant_id = ? AND customer_id = ?', [$bookingId, 'ours', 'customer'])) {
            $_SERVER['HTTP_X_CUSTOMER_CAPABILITY'] = \App\Engine\CustomerCapability::issueManage('ours', $bookingId);
        }
        $request = new Request();
        $_SERVER = $before;
        $request->setAttribute('slug', $slug);
        if ($bookingId !== null) $request->setAttribute('id', $bookingId);
        return $request;
    }
}
