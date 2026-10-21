<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\BookingService;
use App\Engine\Database;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for pattern-aware rescheduling through BookingService.
 *
 * Proves that resource, capacity, and event bookings can be rescheduled
 * end-to-end: fixtures → service call → DB verification.
 *
 * Also covers the resource self-conflict regression (P1): the original
 * booking must not block its own replacement during availability recheck.
 */
final class ReschedulePatternTest extends TestCase
{
    private static bool $dbReady = false;

    // Fixture IDs — deterministic ULIDs for this test class
    private const TENANT_ID   = '01TESTTENANT000000000000'; // shared test tenant
    private const CUSTOMER_ID = '01TESTPATRESCHEDCUST0000';
    private const RESOURCE_ID = '01TESTPATRESCHEDRESRC000';
    private const SLOT_ID     = '01TESTPATRESCHEDSLOT0000';
    private const EVENT_ID    = '01TESTPATRESCHEDEVNT0000';

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/vendor/autoload.php';

        if (file_exists($root . '/.env')) {
            \Tests\Support\TestEnvironment::boot();
        }

        try {
            Database::connect();
            self::$dbReady = true;
            self::seedFixtures();
        } catch (\Throwable) {
            // DB not available — tests will be skipped
        }
    }

    protected function setUp(): void
    {
        if (!self::$dbReady) {
            $this->markTestSkipped('Database not available');
        }

        // Re-ensure critical fixtures exist — other test classes may
        // run between our setUpBeforeClass and individual test methods.
        self::ensureEventFixture();
        self::ensureResourceFixture();
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$dbReady) return;

        // Restore rescheduling_hours_before to default (24) so other test
        // classes sharing this tenant are not affected by our 0 override.
        try {
            Database::execute(
                "UPDATE `tenants` SET `rescheduling_hours_before` = 24 WHERE `id` = ?",
                [self::TENANT_ID]
            );
        } catch (\Throwable) {
            // best-effort
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Resource pattern: successful reschedule
    // ════════════════════════════════════════════════════════════════

    public function test_resource_reschedule_succeeds(): void
    {
        $bookingId = '01TESTPATRESRESBOOK00000';
        $checkIn = date('Y-m-d', strtotime('+10 days'));
        $checkOut = date('Y-m-d', strtotime('+12 days'));
        $newCheckIn = date('Y-m-d', strtotime('+20 days'));
        $newCheckOut = date('Y-m-d', strtotime('+22 days'));

        $this->insertResourceBooking($bookingId, $checkIn, $checkOut);

        $tenant = $this->loadTenant();
        $result = BookingService::rescheduleBooking($bookingId, $tenant, [
            'check_in'  => $newCheckIn,
            'check_out' => $newCheckOut,
        ], 'operator', 'admin');

        // Verify result
        $this->assertNotEmpty($result['new_booking_id']);
        $this->assertSame('resource', $result['pattern']);
        $this->assertSame($newCheckIn, $result['new_date']);

        // Verify DB chain
        $this->assertBookingChain($bookingId, $result['new_booking_id'], 'resource');
    }

    /**
     * Regression test for P1 self-conflict: extending a resource stay
     * that overlaps with the original booking's dates.
     */
    public function test_resource_reschedule_extending_stay_does_not_self_conflict(): void
    {
        $bookingId = '01TESTPATRESEXTEND00000';
        $checkIn = date('Y-m-d', strtotime('+14 days'));
        $checkOut = date('Y-m-d', strtotime('+16 days'));
        // Extend by 1 night — the new range overlaps the original
        $newCheckIn = $checkIn; // same check-in
        $newCheckOut = date('Y-m-d', strtotime('+17 days')); // 1 night longer

        $this->insertResourceBooking($bookingId, $checkIn, $checkOut);

        $tenant = $this->loadTenant();
        $result = BookingService::rescheduleBooking($bookingId, $tenant, [
            'check_in'  => $newCheckIn,
            'check_out' => $newCheckOut,
        ], 'operator', 'admin');

        $this->assertNotEmpty($result['new_booking_id']);
        $this->assertBookingChain($bookingId, $result['new_booking_id'], 'resource');
    }

    // ════════════════════════════════════════════════════════════════
    // Capacity pattern: successful reschedule
    // ════════════════════════════════════════════════════════════════

    public function test_capacity_reschedule_succeeds(): void
    {
        $bookingId = '01TESTPATRESCAPBOOK00000';

        // The fixture slot is for day_of_week matching +10 days
        $origDate = $this->nextWeekdayDate(10);
        $newDate = $this->nextWeekdayDate(17, $origDate);

        $this->insertCapacityBooking($bookingId, $origDate);

        // Ensure the target slot exists for the new day_of_week
        $newDow = ((int) (new \DateTimeImmutable($newDate))->format('N')) - 1;
        $targetSlotId = '01TESTPATRESCHEDSLOT' . str_pad((string) $newDow, 4, '0', STR_PAD_LEFT);
        $this->ensureCapacitySlot($targetSlotId, $newDow);

        $tenant = $this->loadTenant();
        $result = BookingService::rescheduleBooking($bookingId, $tenant, [
            'date'    => $newDate,
            'slot_id' => $targetSlotId,
        ], 'operator', 'admin');

        $this->assertNotEmpty($result['new_booking_id']);
        $this->assertSame('capacity', $result['pattern']);
        $this->assertSame($newDate, $result['new_date']);

        $this->assertBookingChain($bookingId, $result['new_booking_id'], 'capacity');
    }

    // ════════════════════════════════════════════════════════════════
    // Event pattern: successful reschedule
    // ════════════════════════════════════════════════════════════════

    public function test_event_reschedule_succeeds(): void
    {
        $bookingId = '01TESTPATRESEVTBOOK00000';
        $origDate = date('Y-m-d', strtotime('+10 days'));
        $newDate = date('Y-m-d', strtotime('+17 days'));

        $this->insertEventBooking($bookingId, $origDate);

        $tenant = $this->loadTenant();
        $result = BookingService::rescheduleBooking($bookingId, $tenant, [
            'event_id' => self::EVENT_ID,
            'date'     => $newDate,
        ], 'operator', 'admin');

        $this->assertNotEmpty($result['new_booking_id']);
        $this->assertSame('event', $result['pattern']);
        $this->assertSame($newDate, $result['new_date']);

        $this->assertBookingChain($bookingId, $result['new_booking_id'], 'event');
    }

    public function test_event_reschedule_rejects_when_waitlisted(): void
    {
        // Fill the event for the target date
        $targetDate = date('Y-m-d', strtotime('+25 days'));
        $bookingId = '01TESTPATRESEVTWAIT00000';
        $origDate = date('Y-m-d', strtotime('+24 days'));
        $this->insertEventBooking($bookingId, $origDate, 1);

        // Fill the event to capacity on the target date (max_participants=5)
        for ($i = 0; $i < 5; $i++) {
            $fillId = '01TESTPATRESEVTFILL' . str_pad((string) $i, 4, '0', STR_PAD_LEFT) . '0';
            $this->insertEventBooking($fillId, $targetDate, 1, "01TESTPATRESCHEDFILLC{$i}00");
        }

        $tenant = $this->loadTenant();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('event_full');

        BookingService::rescheduleBooking($bookingId, $tenant, [
            'event_id' => self::EVENT_ID,
            'date'     => $targetDate,
        ], 'operator', 'admin');
    }

    // ════════════════════════════════════════════════════════════════
    // Assertions
    // ════════════════════════════════════════════════════════════════

    private function assertBookingChain(string $origId, string $newId, string $expectedPattern): void
    {
        // Original should be rescheduled with link
        $orig = Database::query("SELECT `status`, `rescheduled_to_id` FROM `bookings` WHERE `id` = ?", [$origId]);
        $this->assertSame('rescheduled', $orig[0]['status'] ?? null, 'Original should be rescheduled');
        $this->assertSame($newId, $orig[0]['rescheduled_to_id'] ?? null, 'Should link to new booking');

        // New booking should be confirmed with correct pattern
        $new = Database::query("SELECT `status`, `booking_pattern` FROM `bookings` WHERE `id` = ?", [$newId]);
        $this->assertSame('confirmed', $new[0]['status'] ?? null, 'New booking should be confirmed');
        $this->assertSame($expectedPattern, $new[0]['booking_pattern'] ?? null);

        // Audit log should exist
        $audit = Database::query(
            "SELECT `action`, `details` FROM `audit_log` WHERE `entity_type` = 'booking' AND `entity_id` = ? ORDER BY `created_at` DESC LIMIT 1",
            [$origId]
        );
        $this->assertNotEmpty($audit, 'Audit log should exist');
        $this->assertSame('booking.rescheduled', $audit[0]['action']);
        $details = json_decode($audit[0]['details'] ?? '{}', true);
        $this->assertSame($expectedPattern, $details['pattern'] ?? null, 'Audit should record pattern');
    }

    // ════════════════════════════════════════════════════════════════
    // Fixture helpers
    // ════════════════════════════════════════════════════════════════

    private static function seedFixtures(): void
    {
        $t = self::TENANT_ID;

        // Ensure tenant has rescheduling enabled
        Database::execute(
            "UPDATE `tenants` SET `allow_rescheduling` = 1, `rescheduling_hours_before` = 0 WHERE `id` = ?",
            [$t]
        );

        // Customer
        Database::execute("DELETE FROM `customers` WHERE `id` = ?", [self::CUSTOMER_ID]);
        Database::execute(
            "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`) VALUES (?, ?, 'Pattern Reschedule Customer', 'pat-resched@example.com')",
            [self::CUSTOMER_ID, $t]
        );

        // Resource (simple cabin, no day-of-week restrictions)
        Database::execute("DELETE FROM `resources` WHERE `id` = ?", [self::RESOURCE_ID]);
        Database::execute(
            "INSERT INTO `resources` (`id`, `tenant_id`, `name`, `capacity`, `min_stay_nights`, `max_stay_nights`, `is_active`)
             VALUES (?, ?, 'Test Cabin', 4, 1, 30, 1)",
            [self::RESOURCE_ID, $t]
        );

        // Capacity slot (will be created per-test for matching day_of_week)
        // Seed a default one for the +10 days date
        $origDate = new \DateTimeImmutable('+10 days');
        $dow = ((int) $origDate->format('N')) - 1;
        Database::execute("DELETE FROM `capacity_slots` WHERE `id` = ?", [self::SLOT_ID]);
        Database::execute(
            "INSERT INTO `capacity_slots` (`id`, `tenant_id`, `day_of_week`, `start_time`, `end_time`, `max_capacity`, `min_party_size`, `max_party_size`, `is_active`)
             VALUES (?, ?, ?, '12:00:00', '14:00:00', 20, 1, 8, 1)",
            [self::SLOT_ID, $t, $dow]
        );

        // Event (recurring-style: use a far-future start so instances on any date are valid)
        Database::execute("DELETE FROM `events` WHERE `id` = ?", [self::EVENT_ID]);
        Database::execute(
            "INSERT INTO `events` (`id`, `tenant_id`, `name`, `max_participants`, `start_datetime`, `end_datetime`, `is_recurring`, `rrule`, `is_active`)
             VALUES (?, ?, 'Test Workshop', 5, '2026-01-01 14:00:00', '2026-01-01 16:00:00', 1, 'FREQ=DAILY;COUNT=365', 1)",
            [self::EVENT_ID, $t]
        );

        // Clean prior test bookings
        Database::execute("DELETE FROM `bookings` WHERE `id` LIKE '01TESTPATRES%'");
    }

    /**
     * Idempotent: ensure the event fixture exists (cross-class cleanup resilience).
     */
    private static function ensureEventFixture(): void
    {
        $existing = Database::query("SELECT `id` FROM `events` WHERE `id` = ?", [self::EVENT_ID]);
        if (!empty($existing)) return;

        Database::execute(
            "INSERT INTO `events` (`id`, `tenant_id`, `name`, `max_participants`, `start_datetime`, `end_datetime`, `is_recurring`, `rrule`, `is_active`)
             VALUES (?, ?, 'Test Workshop', 5, '2026-01-01 14:00:00', '2026-01-01 16:00:00', 1, 'FREQ=DAILY;COUNT=365', 1)",
            [self::EVENT_ID, self::TENANT_ID]
        );
    }

    /**
     * Idempotent: ensure the resource fixture exists.
     */
    private static function ensureResourceFixture(): void
    {
        $existing = Database::query("SELECT `id` FROM `resources` WHERE `id` = ?", [self::RESOURCE_ID]);
        if (!empty($existing)) return;

        Database::execute(
            "INSERT INTO `resources` (`id`, `tenant_id`, `name`, `capacity`, `min_stay_nights`, `max_stay_nights`, `is_active`)
             VALUES (?, ?, 'Test Cabin', 4, 1, 30, 1)",
            [self::RESOURCE_ID, self::TENANT_ID]
        );
    }

    private function insertResourceBooking(string $id, string $checkIn, string $checkOut): void
    {
        Database::execute("DELETE FROM `bookings` WHERE `id` = ?", [$id]);
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`, `resource_id`,
              `start_datetime`, `end_datetime`, `status`, `source`, `party_size`)
             VALUES (?, ?, 'resource', ?, ?,
              ?, ?, 'confirmed', 'web', 2)",
            [$id, self::TENANT_ID, self::CUSTOMER_ID, self::RESOURCE_ID,
             "{$checkIn} 00:00:00", "{$checkOut} 00:00:00"]
        );
    }

    private function insertCapacityBooking(string $id, string $date): void
    {
        Database::execute("DELETE FROM `bookings` WHERE `id` = ?", [$id]);
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`,
              `start_datetime`, `end_datetime`, `status`, `source`, `party_size`)
             VALUES (?, ?, 'capacity', ?,
              '{$date} 12:00:00', '{$date} 14:00:00',
              'confirmed', 'web', 2)",
            [$id, self::TENANT_ID, self::CUSTOMER_ID]
        );
    }

    private function insertEventBooking(string $id, string $date, int $spotCount = 1, ?string $customerId = null): void
    {
        $customerId = $customerId ?? self::CUSTOMER_ID;

        // Ensure customer exists for fill bookings
        if ($customerId !== self::CUSTOMER_ID) {
            Database::execute("DELETE FROM `customers` WHERE `id` = ?", [$customerId]);
            Database::execute(
                "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`) VALUES (?, ?, 'Fill Customer', ?)",
                [$customerId, self::TENANT_ID, "fill-{$customerId}@example.com"]
            );
        }

        Database::execute("DELETE FROM `bookings` WHERE `id` = ?", [$id]);
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`, `event_id`,
              `start_datetime`, `end_datetime`, `status`, `source`, `party_size`)
             VALUES (?, ?, 'event', ?, ?,
              '{$date} 14:00:00', '{$date} 16:00:00',
              'confirmed', 'web', ?)",
            [$id, self::TENANT_ID, $customerId, self::EVENT_ID, $spotCount]
        );
    }

    private function ensureCapacitySlot(string $slotId, int $dow): void
    {
        $existing = Database::query("SELECT `id` FROM `capacity_slots` WHERE `id` = ?", [$slotId]);
        if (!empty($existing)) return;

        Database::execute(
            "INSERT INTO `capacity_slots` (`id`, `tenant_id`, `day_of_week`, `start_time`, `end_time`, `max_capacity`, `min_party_size`, `max_party_size`, `is_active`)
             VALUES (?, ?, ?, '12:00:00', '14:00:00', 20, 1, 8, 1)",
            [$slotId, self::TENANT_ID, $dow]
        );
    }

    /**
     * Find the next weekday date N days from now (or from a given base date),
     * skipping weekends.
     */
    private function nextWeekdayDate(int $daysAhead, ?string $from = null): string
    {
        $base = $from ? new \DateTimeImmutable($from) : new \DateTimeImmutable('now');
        $dt = $base->modify("+{$daysAhead} days");
        while ((int) $dt->format('N') >= 6) {
            $dt = $dt->modify('+1 day');
        }
        return $dt->format('Y-m-d');
    }

    private function loadTenant(): array
    {
        $rows = Database::query("SELECT * FROM `tenants` WHERE `id` = ?", [self::TENANT_ID]);
        $this->assertNotEmpty($rows, 'Test tenant must exist');
        return $rows[0];
    }
}
