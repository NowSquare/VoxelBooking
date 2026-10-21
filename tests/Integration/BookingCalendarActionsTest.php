<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Agent\{Executor, Operations, PreviewStore, PublicContinuation, SchemaValidator};
use App\Application\Bookings\CalendarLinks;
use App\Engine\{Auth, CustomerCapability, Database, Ulid};
use PHPUnit\Framework\TestCase;

/**
 * A customer's booking result offers the same add-to-calendar actions as the
 * booking page, and only once the booking is confirmed.
 */
final class BookingCalendarActionsTest extends TestCase
{
    private Executor $executor;
    private string $tenant;
    private string $slug;
    private string $service;
    private string $date;
    private array $session = [];

    protected function setUp(): void
    {
        \Tests\Support\TestEnvironment::boot();
        TestFixtures::provision();
        // Event descriptions are translated text, so load the language files this process reads.
        \App\Engine\Locale::init(dirname(__DIR__, 2));
        Auth::startSession();
        $this->session = $_SESSION;
        $this->executor = new Executor(Operations::registry());
        $this->tenant = Ulid::generate();
        $this->slug = 'calendar-actions-' . strtolower($this->tenant);
        Database::execute(
            "INSERT INTO tenants(id,name,slug,email,booking_pattern,timezone,currency,status,min_advance_hours,max_advance_days,requires_consent) VALUES(?,'Studio, North',?,'actions@example.test','timeslot','Europe/Amsterdam','EUR','active',0,365,0)",
            [$this->tenant, $this->slug]
        );
        $this->date = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $this->service = Ulid::generate();
        Database::execute(
            "INSERT INTO services(id,tenant_id,name,duration_minutes,requires_staff,price) VALUES(?,?,'Cut & colour (long; curly)',45,0,40.00)",
            [$this->service, $this->tenant]
        );
        Database::execute(
            "INSERT INTO availability(id,tenant_id,day_of_week,start_time,end_time) VALUES(?,?,?,'08:00','18:00')",
            [Ulid::generate(), $this->tenant, (int) (new \DateTimeImmutable($this->date))->format('N') - 1]
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->session;
        Database::execute('DELETE FROM tenants WHERE id=?', [$this->tenant]);
    }

    /** The booking page's gcalUrl and downloadIcs output for the same booking, written out by hand. */
    public function testActionsReproduceTheBookingPageFormatAndEscaping(): void
    {
        $tenant = ['name' => 'Studio, North', 'slug' => 'studio-north', 'timezone' => 'Europe/Amsterdam'];
        $now = new \DateTimeImmutable('2026-10-21 08:09:10', new \DateTimeZone('UTC'));
        $timed = CalendarLinks::build([
            'id' => 'BOOKING1', 'status' => 'confirmed', 'booking_pattern' => 'timeslot', 'title' => "Cut & colour (men's; long)",
            'resource' => 'Studio, North', 'staff' => 'Ana, senior', 'start_utc' => '2026-10-25T01:30:00Z', 'end_utc' => '2026-10-25T02:15:00Z',
            'check_in' => null, 'check_out' => null, 'nights' => null,
        ], $tenant, $now);
        self::assertSame(
            "https://calendar.google.com/calendar/render?action=TEMPLATE&text=Cut%20%26%20colour%20(men's%3B%20long)&dates=20261025T013000Z/20261025T021500Z&ctz=Europe%2FAmsterdam",
            $timed['google_url']
        );
        self::assertSame(implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//VoxelBooking//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'BEGIN:VEVENT',
            'UID:BOOKING1@studio-north.voxelbooking', 'DTSTART:20261025T013000Z', 'DTEND:20261025T021500Z',
            "SUMMARY:Cut & colour (men's\\; long)", 'DESCRIPTION:With Ana\\, senior', 'DTSTAMP:20261021T080910Z', 'END:VEVENT', 'END:VCALENDAR',
        ]), $timed['ics']);
        self::assertSame(['booking-BOOKING1.ics', 'text/calendar'], [$timed['ics_filename'], $timed['media_type']]);

        $stay = CalendarLinks::build([
            'id' => 'STAY1', 'status' => 'confirmed', 'booking_pattern' => 'resource', 'title' => 'Studio, North', 'resource' => 'Garden cabin',
            'staff' => null, 'start_utc' => null, 'end_utc' => null, 'check_in' => '2026-11-01', 'check_out' => '2026-11-03', 'nights' => 2,
        ], $tenant, $now);
        self::assertSame('https://calendar.google.com/calendar/render?action=TEMPLATE&text=Garden%20cabin&dates=20261101/20261103&ctz=Europe%2FAmsterdam', $stay['google_url']);
        self::assertStringContainsString("DTSTART;VALUE=DATE:20261101\r\nDTEND;VALUE=DATE:20261103\r\nSUMMARY:Garden cabin\r\nDESCRIPTION:2 night stay\r\n", $stay['ics']);

        foreach (['pending', 'waitlisted', 'cancelled'] as $status) {
            self::assertNull(CalendarLinks::build(['status' => $status] + ['id' => 'X', 'booking_pattern' => 'timeslot'], $tenant), $status);
        }
    }

    public function testConfirmedPublicResultAndCustomerReadOfferCalendarActionsButPendingDoNot(): void
    {
        $confirmed = $this->book('09:00', 'Calendar guest', 'calendar-guest@example.test');
        self::assertSame('confirmed', $confirmed['status']);
        $calendar = $confirmed['calendar'];
        self::assertIsArray($calendar);
        $compact = static fn (string $instant) => str_replace(['-', ':'], '', $instant);
        self::assertStringContainsString('&dates=' . $compact($confirmed['start_utc']) . '/' . $compact($confirmed['end_utc']), $calendar['google_url']);
        self::assertStringContainsString('text=Cut%20%26%20colour%20(long%3B%20curly)', $calendar['google_url']);
        self::assertStringContainsString('UID:' . $confirmed['id'] . '@' . $this->slug . '.voxelbooking', $calendar['ics']);

        // The customer's own read carries the same event; only the generation stamp differs.
        $read = $this->customerRead($confirmed['id']);
        $stamp = static fn (string $ics) => preg_replace('/DTSTAMP:\d{8}T\d{6}Z/', 'DTSTAMP:', $ics);
        self::assertSame($calendar['google_url'], $read['calendar']['google_url']);
        self::assertSame($stamp($calendar['ics']), $stamp($read['calendar']['ics']));

        Database::execute('UPDATE tenants SET booking_requires_approval=1 WHERE id=?', [$this->tenant]);
        $pending = $this->book('11:00', 'Pending guest', 'pending-guest@example.test');
        self::assertSame('pending', $pending['status']);
        self::assertNull($pending['calendar']);
        self::assertNull($this->customerRead($pending['id'])['calendar']);
    }

    /** A prospective customer books through the public operation after approving the review. */
    private function book(string $time, string $name, string $email): array
    {
        $continuation = (new PublicContinuation())->issue($this->tenant);
        $actor = (new PublicContinuation())->resolve($continuation['continuation'], $this->tenant);
        $input = [
            'business_id' => $this->tenant, 'service_id' => $this->service, 'date' => $this->date, 'time' => $time,
            'customer' => ['name' => $name, 'email' => $email],
        ];
        $preview = $this->executor->preview($actor, 'public_booking_create', $input);
        $id = basename((string) parse_url($preview['review_url'], PHP_URL_PATH));
        $store = new PreviewStore();
        $store->exchangeBrowserHandle($id, \Tests\Support\ReviewLink::secret($preview));
        $store->approveCustomerBrowser($id, true);
        $result = $this->executor->execute($actor, 'public_booking_create', ['preview_handle' => $preview['preview_handle']], 'actions-' . Ulid::generate());
        (new SchemaValidator())->validate(Operations::registry()->get('public_booking_create')->resultSchema(), $result, true);

        return $result['data'];
    }

    private function customerRead(string $bookingId): array
    {
        $secret = CustomerCapability::issueManage($this->tenant, $bookingId);
        self::assertTrue(CustomerCapability::remember($secret, CustomerCapability::MANAGE, $this->tenant, $bookingId));
        $grant = CustomerCapability::verify($secret, CustomerCapability::MANAGE, $this->tenant, $bookingId);
        $result = $this->executor->execute(CustomerCapability::actorById($grant['id']), 'customer_booking_get', ['business_id' => $this->tenant]);
        (new SchemaValidator())->validate(Operations::registry()->get('customer_booking_get')->resultSchema(), $result, true);

        return $result['data'];
    }
}
