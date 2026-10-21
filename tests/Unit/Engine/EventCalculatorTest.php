<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\EventCalculator;
use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\Ulid;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit/integration tests for EventCalculator.
 *
 * Self-seeds a "test-workshop" event-pattern tenant with one-off events,
 * a weekly recurring event, and sample bookings. Cleaned up in tearDownAfterClass.
 */
class EventCalculatorTest extends TestCase
{
    private static array $tenant;
    private static string $tenantId;
    private static string $eventOneOff;        // One-off woodworking workshop
    private static string $eventRecurring;     // Weekly yoga class
    private static string $eventFull;          // Full event with waitlist
    private static string $eventSpotLimited;   // Event with min_spot_count=2, max_spot_count=3
    private static string $customerId;
    private static bool $seeded = false;

    public static function setUpBeforeClass(): void
    {
        try {
            \Tests\Support\TestEnvironment::boot();
            Database::connect();
            Database::query('SELECT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Database not available');
            return;
        }

        self::seedTestData();
    }

    protected function setUp(): void
    {
        if (!self::$seeded) {
            $this->markTestSkipped('Test fixture not seeded.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$seeded) {
            return;
        }

        try {
            Database::execute('DELETE FROM `bookings` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `events` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `customers` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        } catch (\Throwable) {
            // Best-effort cleanup
        }

        self::$seeded = false;
    }

    private static function seedTestData(): void
    {
        self::$tenantId = Ulid::generate();
        $tid = self::$tenantId;
        $tz = new \DateTimeZone('Europe/Berlin');
        $today = new \DateTimeImmutable('today', $tz);

        // Tenant
        Database::execute(
            "INSERT INTO `tenants` (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`,
             `brand_color`, `timezone`, `locale`, `currency`, `max_advance_days`)
             VALUES (?, 'test-workshop-evt', 'Test Workshop', 'test@workshop-evt.test', 'event', 'active',
             '#8B5CF6', 'Europe/Berlin', 'en', 'EUR', 90)",
            [$tid]
        );

        self::$tenant = Database::query('SELECT * FROM `tenants` WHERE `id` = ?', [$tid])[0];

        // Customer
        self::$customerId = Ulid::generate();
        Database::execute(
            "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`) VALUES (?, ?, 'Test Attendee', 'attendee@test.test')",
            [self::$customerId, $tid]
        );

        // ── Event 1: One-off woodworking workshop — 10 days from now, 12 max ──
        self::$eventOneOff = Ulid::generate();
        $workshopDate = $today->modify('+10 days');
        Database::execute(
            "INSERT INTO `events` (`id`, `tenant_id`, `name`, `description`, `location`, `price`,
             `max_participants`, `start_datetime`, `end_datetime`, `is_recurring`, `allow_waitlist`,
             `waitlist_max`, `is_active`)
             VALUES (?, ?, 'Woodworking 101', 'Build your first cutting board.', 'Workshop Room A', 45.00,
             12, ?, ?, 0, 0, 0, 1)",
            [self::$eventOneOff, $tid,
             $workshopDate->format('Y-m-d') . ' 09:00:00',
             $workshopDate->format('Y-m-d') . ' 13:00:00']
        );

        // Add 4 confirmed bookings (8 spots remaining)
        for ($i = 0; $i < 4; $i++) {
            Database::execute(
                "INSERT INTO `bookings` (`id`, `tenant_id`, `customer_id`, `booking_pattern`, `event_id`,
                 `start_datetime`, `end_datetime`, `party_size`, `status`, `source`)
                 VALUES (?, ?, ?, 'event', ?, ?, ?, 1, 'confirmed', 'web')",
                [Ulid::generate(), $tid, self::$customerId, self::$eventOneOff,
                 $workshopDate->format('Y-m-d') . ' 09:00:00',
                 $workshopDate->format('Y-m-d') . ' 13:00:00']
            );
        }

        // ── Event 2: Weekly recurring yoga — starts next Monday, weekly for 12 weeks ──
        self::$eventRecurring = Ulid::generate();
        $nextMonday = $today->modify('next Monday');
        Database::execute(
            "INSERT INTO `events` (`id`, `tenant_id`, `name`, `description`, `location`, `price`,
             `max_participants`, `start_datetime`, `end_datetime`, `is_recurring`, `rrule`,
             `exception_dates`, `allow_waitlist`, `waitlist_max`, `is_active`)
             VALUES (?, ?, 'Morning Yoga', 'Energizing flow for all levels.', 'Studio B', 15.00,
             20, ?, ?, 1, ?, ?, 1, 5, 1)",
            [self::$eventRecurring, $tid,
             $nextMonday->format('Y-m-d') . ' 08:00:00',
             $nextMonday->format('Y-m-d') . ' 09:00:00',
             'FREQ=WEEKLY;COUNT=12',
             json_encode([$nextMonday->modify('+14 days')->format('Y-m-d')])] // Skip 3rd occurrence
        );

        // ── Event 3: Full event with waitlist ──
        self::$eventFull = Ulid::generate();
        $fullDate = $today->modify('+7 days');
        Database::execute(
            "INSERT INTO `events` (`id`, `tenant_id`, `name`, `description`, `price`,
             `max_participants`, `start_datetime`, `end_datetime`, `is_recurring`,
             `allow_waitlist`, `waitlist_max`, `is_active`)
             VALUES (?, ?, 'Wine Tasting', 'Premier cru selection.', 35.00,
             3, ?, ?, 0, 1, 2, 1)",
            [self::$eventFull, $tid,
             $fullDate->format('Y-m-d') . ' 19:00:00',
             $fullDate->format('Y-m-d') . ' 21:00:00']
        );

        // Fill full event: 3 confirmed bookings (capacity = 3)
        for ($i = 0; $i < 3; $i++) {
            Database::execute(
                "INSERT INTO `bookings` (`id`, `tenant_id`, `customer_id`, `booking_pattern`, `event_id`,
                 `start_datetime`, `end_datetime`, `party_size`, `status`, `source`)
                 VALUES (?, ?, ?, 'event', ?, ?, ?, 1, 'confirmed', 'web')",
                [Ulid::generate(), $tid, self::$customerId, self::$eventFull,
                 $fullDate->format('Y-m-d') . ' 19:00:00',
                 $fullDate->format('Y-m-d') . ' 21:00:00']
            );
        }

        // Add 1 waitlisted booking
        Database::execute(
            "INSERT INTO `bookings` (`id`, `tenant_id`, `customer_id`, `booking_pattern`, `event_id`,
             `start_datetime`, `end_datetime`, `party_size`, `status`, `source`)
             VALUES (?, ?, ?, 'event', ?, ?, ?, 1, 'waitlisted', 'web')",
            [Ulid::generate(), $tid, self::$customerId, self::$eventFull,
             $fullDate->format('Y-m-d') . ' 19:00:00',
             $fullDate->format('Y-m-d') . ' 21:00:00']
        );

        // ── Event 4: Spot-limited event — min 2, max 3 per booking, 10 capacity ──
        self::$eventSpotLimited = Ulid::generate();
        $spotLimitDate = $today->modify('+20 days');
        Database::execute(
            "INSERT INTO `events` (`id`, `tenant_id`, `name`, `description`, `price`,
             `max_participants`, `min_spot_count`, `max_spot_count`,
             `start_datetime`, `end_datetime`, `is_recurring`,
             `allow_waitlist`, `waitlist_max`, `is_active`)
             VALUES (?, ?, 'Pottery Workshop', 'Hands-on pottery class.', 65.00,
             10, 2, 3, ?, ?, 0, 0, 0, 1)",
            [self::$eventSpotLimited, $tid,
             $spotLimitDate->format('Y-m-d') . ' 14:00:00',
             $spotLimitDate->format('Y-m-d') . ' 18:00:00']
        );

        self::$seeded = true;
    }

    // ── getUpcomingEvents tests ──

    #[Test]
    public function upcoming_events_returns_all_active_events(): void
    {
        $result = EventCalculator::getUpcomingEvents(self::$tenant);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('events', $result);
        $this->assertNotEmpty($result['events']);

        $names = array_column($result['events'], 'name');
        $this->assertContains('Woodworking 101', $names);
        $this->assertContains('Wine Tasting', $names);
    }

    #[Test]
    public function bookings_whose_event_was_deleted_count_toward_no_occurrence(): void
    {
        $orphan = Ulid::generate();
        Database::execute(
            "INSERT INTO `bookings` (`id`, `tenant_id`, `customer_id`, `booking_pattern`, `event_id`,
             `start_datetime`, `end_datetime`, `party_size`, `status`, `source`)
             VALUES (?, ?, ?, 'event', NULL, ?, ?, 2, 'confirmed', 'web')",
            [$orphan, self::$tenantId, self::$customerId, date('Y-m-d', strtotime('+10 days')) . ' 09:00:00', date('Y-m-d', strtotime('+10 days')) . ' 13:00:00']
        );
        set_error_handler(static function (int $level, string $message): bool {
            throw new \ErrorException($message, 0, $level);
        }, E_DEPRECATED);

        try {
            $result = EventCalculator::getUpcomingEvents(self::$tenant);
        } finally {
            restore_error_handler();
            Database::execute('DELETE FROM `bookings` WHERE `id` = ?', [$orphan]);
        }

        $workshop = array_values(array_filter($result['events'], fn($e) => $e['name'] === 'Woodworking 101'))[0];
        $this->assertSame(8, $workshop['remaining'], 'An event-less booking must not take a place');
    }

    #[Test]
    public function upcoming_events_expands_recurring(): void
    {
        $result = EventCalculator::getUpcomingEvents(self::$tenant);

        // Multiple Morning Yoga instances should appear
        $yogaInstances = array_filter($result['events'], fn($e) => $e['name'] === 'Morning Yoga');
        $this->assertGreaterThanOrEqual(2, count($yogaInstances), 'Multiple recurring yoga instances should expand');
    }

    #[Test]
    public function upcoming_events_excludes_exception_dates(): void
    {
        $result = EventCalculator::getUpcomingEvents(self::$tenant);
        $tz = new \DateTimeZone('Europe/Berlin');
        $nextMonday = (new \DateTimeImmutable('today', $tz))->modify('next Monday');
        $exceptionDate = $nextMonday->modify('+14 days')->format('Y-m-d');

        $yogaInstances = array_filter($result['events'], fn($e) => $e['name'] === 'Morning Yoga');
        $yogaDates = array_column($yogaInstances, 'date');
        $this->assertNotContains($exceptionDate, $yogaDates, 'Exception date should be excluded');
    }

    #[Test]
    public function upcoming_events_shows_remaining_spots(): void
    {
        $result = EventCalculator::getUpcomingEvents(self::$tenant);

        // Woodworking has 4 confirmed, max 12 → 8 remaining
        $workshop = array_values(array_filter($result['events'], fn($e) => $e['name'] === 'Woodworking 101'));
        $this->assertNotEmpty($workshop);
        $this->assertSame(8, $workshop[0]['remaining']);
        $this->assertFalse($workshop[0]['is_full']);
    }

    #[Test]
    public function upcoming_events_marks_full_with_waitlist(): void
    {
        $result = EventCalculator::getUpcomingEvents(self::$tenant);

        // Wine Tasting: 3 confirmed, max 3, waitlist enabled with 1 waitlisted
        $wine = array_values(array_filter($result['events'], fn($e) => $e['name'] === 'Wine Tasting'));
        $this->assertNotEmpty($wine);
        $this->assertSame(0, $wine[0]['remaining']);
        $this->assertTrue($wine[0]['is_full']);
        $this->assertTrue($wine[0]['can_waitlist']); // 1 of 2 waitlist spots used
        $this->assertSame(1, $wine[0]['waitlist_count']);
    }

    // ── getEventDetail tests ──

    #[Test]
    public function event_detail_returns_correct_data(): void
    {
        $result = EventCalculator::getEventDetail(self::$tenant, self::$eventOneOff);

        $this->assertNotNull($result['event']);
        $this->assertSame('Woodworking 101', $result['event']['name']);
        $this->assertSame(8, $result['event']['remaining']);
        $this->assertSame('Workshop Room A', $result['event']['location']);
    }

    #[Test]
    public function event_detail_returns_null_for_nonexistent(): void
    {
        $result = EventCalculator::getEventDetail(self::$tenant, 'NONEXISTENT_EVENT_ID');

        $this->assertNull($result['event']);
    }

    #[Test]
    public function event_detail_recurring_with_date(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $nextMonday = (new \DateTimeImmutable('today', $tz))->modify('next Monday');

        $result = EventCalculator::getEventDetail(
            self::$tenant, self::$eventRecurring, $nextMonday->format('Y-m-d')
        );

        $this->assertNotNull($result['event']);
        $this->assertSame('Morning Yoga', $result['event']['name']);
        $this->assertSame($nextMonday->format('Y-m-d'), $result['event']['date']);
    }

    #[Test]
    public function event_detail_recurring_exception_returns_null(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $nextMonday = (new \DateTimeImmutable('today', $tz))->modify('next Monday');
        $exceptionDate = $nextMonday->modify('+14 days')->format('Y-m-d');

        $result = EventCalculator::getEventDetail(
            self::$tenant, self::$eventRecurring, $exceptionDate
        );

        $this->assertNull($result['event']);
    }

    // ── checkAvailability tests ──

    #[Test]
    public function check_availability_spots_available(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $workshopDate = (new \DateTimeImmutable('today', $tz))->modify('+10 days')->format('Y-m-d');

        $result = EventCalculator::checkAvailability(
            self::$tenant, self::$eventOneOff, $workshopDate, 2
        );

        $this->assertTrue($result['available']);
        $this->assertFalse($result['waitlisted']);
        $this->assertSame(6, $result['remaining']); // 8 remaining - 2 requested
        $this->assertNull($result['error']);
    }

    #[Test]
    public function check_availability_event_full_no_waitlist(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $workshopDate = (new \DateTimeImmutable('today', $tz))->modify('+10 days')->format('Y-m-d');

        // Request more than remaining (12 max, 4 booked, request 9 → 8 < 9)
        $result = EventCalculator::checkAvailability(
            self::$tenant, self::$eventOneOff, $workshopDate, 9
        );

        $this->assertFalse($result['available']);
        $this->assertSame('event_full', $result['error']);
    }

    #[Test]
    public function check_availability_waitlist_available(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $fullDate = (new \DateTimeImmutable('today', $tz))->modify('+7 days')->format('Y-m-d');

        // Wine Tasting: full but waitlist enabled, 1 of 2 spots used
        $result = EventCalculator::checkAvailability(
            self::$tenant, self::$eventFull, $fullDate, 1
        );

        $this->assertTrue($result['available']);
        $this->assertTrue($result['waitlisted']);
        $this->assertSame(0, $result['remaining']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function check_availability_waitlist_full(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $fullDate = (new \DateTimeImmutable('today', $tz))->modify('+7 days')->format('Y-m-d');

        // Wine Tasting: waitlist_max=2, 1 already waitlisted, request 2 more → exceeds
        $result = EventCalculator::checkAvailability(
            self::$tenant, self::$eventFull, $fullDate, 2
        );

        $this->assertFalse($result['available']);
        $this->assertSame('waitlist_full', $result['error']);
    }

    #[Test]
    public function check_availability_invalid_date_for_one_off(): void
    {
        $result = EventCalculator::checkAvailability(
            self::$tenant, self::$eventOneOff, '2099-12-31', 1
        );

        $this->assertFalse($result['available']);
        $this->assertSame('invalid_date', $result['error']);
    }

    #[Test]
    public function check_availability_nonexistent_event(): void
    {
        $result = EventCalculator::checkAvailability(
            self::$tenant, 'NONEXISTENT_EVENT_ID', '2026-01-01', 1
        );

        $this->assertFalse($result['available']);
        $this->assertSame('event_not_found', $result['error']);
    }

    #[Test]
    public function check_availability_recurring_valid_date(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $nextMonday = (new \DateTimeImmutable('today', $tz))->modify('next Monday');

        $result = EventCalculator::checkAvailability(
            self::$tenant, self::$eventRecurring, $nextMonday->format('Y-m-d'), 1
        );

        $this->assertTrue($result['available']);
        $this->assertFalse($result['waitlisted']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function check_availability_recurring_exception_date(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $nextMonday = (new \DateTimeImmutable('today', $tz))->modify('next Monday');
        $exceptionDate = $nextMonday->modify('+14 days')->format('Y-m-d');

        $result = EventCalculator::checkAvailability(
            self::$tenant, self::$eventRecurring, $exceptionDate, 1
        );

        $this->assertFalse($result['available']);
        $this->assertSame('instance_cancelled', $result['error']);
    }

    // ── Per-booking spot limit tests ──

    #[Test]
    public function check_availability_spot_count_too_few(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $date = (new \DateTimeImmutable('today', $tz))->modify('+20 days')->format('Y-m-d');

        // Pottery Workshop has min_spot_count=2, request 1
        $result = EventCalculator::checkAvailability(
            self::$tenant, self::$eventSpotLimited, $date, 1
        );

        $this->assertFalse($result['available']);
        $this->assertSame('spot_count_too_few', $result['error']);
    }

    #[Test]
    public function check_availability_spot_count_too_many(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $date = (new \DateTimeImmutable('today', $tz))->modify('+20 days')->format('Y-m-d');

        // Pottery Workshop has max_spot_count=3, request 4
        $result = EventCalculator::checkAvailability(
            self::$tenant, self::$eventSpotLimited, $date, 4
        );

        $this->assertFalse($result['available']);
        $this->assertSame('spot_count_too_many', $result['error']);
    }

    #[Test]
    public function check_availability_spot_count_at_min_boundary_accepted(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $date = (new \DateTimeImmutable('today', $tz))->modify('+20 days')->format('Y-m-d');

        // Pottery Workshop has min_spot_count=2, request exactly 2
        $result = EventCalculator::checkAvailability(
            self::$tenant, self::$eventSpotLimited, $date, 2
        );

        $this->assertTrue($result['available']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function check_availability_spot_count_at_max_boundary_accepted(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $date = (new \DateTimeImmutable('today', $tz))->modify('+20 days')->format('Y-m-d');

        // Pottery Workshop has max_spot_count=3, request exactly 3
        $result = EventCalculator::checkAvailability(
            self::$tenant, self::$eventSpotLimited, $date, 3
        );

        $this->assertTrue($result['available']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function event_detail_exposes_spot_limit_fields(): void
    {
        $result = EventCalculator::getEventDetail(self::$tenant, self::$eventSpotLimited);

        $this->assertNotNull($result['event']);
        $this->assertSame('Pottery Workshop', $result['event']['name']);
        $this->assertArrayHasKey('min_spot_count', $result['event']);
        $this->assertArrayHasKey('max_spot_count', $result['event']);
        $this->assertSame(2, $result['event']['min_spot_count']);
        $this->assertSame(3, $result['event']['max_spot_count']);
    }

    #[Test]
    public function upcoming_events_exposes_spot_limit_fields(): void
    {
        $result = EventCalculator::getUpcomingEvents(self::$tenant);

        $pottery = array_values(array_filter(
            $result['events'], fn($e) => $e['name'] === 'Pottery Workshop'
        ));
        $this->assertNotEmpty($pottery);
        $this->assertSame(2, $pottery[0]['min_spot_count']);
        $this->assertSame(3, $pottery[0]['max_spot_count']);
    }

    #[Test]
    public function check_availability_null_max_spot_count_allows_up_to_remaining(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $workshopDate = (new \DateTimeImmutable('today', $tz))->modify('+10 days')->format('Y-m-d');

        // Woodworking 101 has no max_spot_count (NULL), 8 remaining. Request 8.
        $result = EventCalculator::checkAvailability(
            self::$tenant, self::$eventOneOff, $workshopDate, 8
        );

        $this->assertTrue($result['available']);
        $this->assertNull($result['error']);
    }
}
