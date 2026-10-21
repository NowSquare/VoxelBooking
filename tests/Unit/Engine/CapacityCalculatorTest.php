<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\CapacityCalculator;
use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\Ulid;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for CapacityCalculator.
 *
 * Self-seeds a "test-trattoria" capacity-pattern tenant with
 * capacity slots and sample bookings. Cleaned up in tearDownAfterClass.
 */
class CapacityCalculatorTest extends TestCase
{
    private static array $tenant;
    private static string $tenantId;
    private static string $slotMondayEarly;     // Monday 18:00-19:30, cap 20, min_party 1
    private static string $slotMondayMain;      // Monday 19:30-21:00, cap 20, min_party 1
    private static string $slotTuesdayEarly;    // Tuesday 18:00-19:30, cap 10, min_party 1
    private static string $slotMondayLate;      // Monday 21:00-22:30, cap 15, min_party 3
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
            Database::execute('DELETE FROM `capacity_slots` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `blocked_dates` WHERE `tenant_id` = ?', [self::$tenantId]);
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

        // Tenant
        Database::execute(
            "INSERT INTO `tenants` (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`,
             `brand_color`, `timezone`, `locale`, `currency`, `max_advance_days`)
             VALUES (?, 'test-trattoria-cap', 'Test Trattoria', 'test@trattoria-cap.test', 'capacity', 'active',
             '#D32F2F', 'Europe/Rome', 'en', 'EUR', 90)",
            [$tid]
        );

        self::$tenant = Database::query('SELECT * FROM `tenants` WHERE `id` = ?', [$tid])[0];

        // Capacity slots
        self::$slotMondayEarly  = Ulid::generate();
        self::$slotMondayMain   = Ulid::generate();
        self::$slotTuesdayEarly = Ulid::generate();
        self::$slotMondayLate   = Ulid::generate();

        $slotStmt = Database::connect()->prepare(
            "INSERT INTO `capacity_slots` (`id`, `tenant_id`, `day_of_week`, `start_time`, `end_time`, `max_capacity`, `min_party_size`, `max_party_size`, `label`, `is_active`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        // Monday (dow=0) slots
        $slotStmt->execute([self::$slotMondayEarly, $tid, 0, '18:00:00', '19:30:00', 20, 1, 8, 'Early Dinner', 1]);
        $slotStmt->execute([self::$slotMondayMain, $tid, 0, '19:30:00', '21:00:00', 20, 1, 8, 'Main Dinner', 1]);
        $slotStmt->execute([self::$slotMondayLate, $tid, 0, '21:00:00', '22:30:00', 15, 3, 6, 'Late Dinner', 1]);

        // Tuesday (dow=1) slot with smaller capacity
        $slotStmt->execute([self::$slotTuesdayEarly, $tid, 1, '18:00:00', '19:30:00', 10, 1, 4, 'Early Dinner', 1]);

        // Customer for test bookings
        $customerId = Ulid::generate();
        Database::execute(
            "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`) VALUES (?, ?, 'Test Guest', 'guest@test.test')",
            [$customerId, $tid]
        );

        // Find the next Monday from today
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome'));
        $nextMonday = $today->modify('next Monday');
        $mondayDate = $nextMonday->format('Y-m-d');

        // Add a booking of party_size=4 for the Monday early slot
        Database::execute(
            "INSERT INTO `bookings` (`id`, `tenant_id`, `customer_id`, `booking_pattern`, `start_datetime`, `end_datetime`, `party_size`, `status`, `source`)
             VALUES (?, ?, ?, 'capacity', ?, ?, 4, 'confirmed', 'web')",
            [Ulid::generate(), $tid, $customerId, "{$mondayDate} 18:00:00", "{$mondayDate} 19:30:00"]
        );

        // Add another booking of party_size=6 for the Monday early slot (total=10 booked)
        Database::execute(
            "INSERT INTO `bookings` (`id`, `tenant_id`, `customer_id`, `booking_pattern`, `start_datetime`, `end_datetime`, `party_size`, `status`, `source`)
             VALUES (?, ?, ?, 'capacity', ?, ?, 6, 'confirmed', 'web')",
            [Ulid::generate(), $tid, $customerId, "{$mondayDate} 18:00:00", "{$mondayDate} 19:30:00"]
        );

        // Block next Tuesday
        $nextTuesday = $today->modify('next Tuesday');
        Database::execute(
            "INSERT INTO `blocked_dates` (`id`, `tenant_id`, `start_date`, `end_date`, `reason`) VALUES (?, ?, ?, ?, 'Holiday')",
            [Ulid::generate(), $tid, $nextTuesday->format('Y-m-d'), $nextTuesday->format('Y-m-d')]
        );

        self::$seeded = true;
    }

    // ── getAvailableSlots tests ──

    #[Test]
    public function available_slots_returns_slots_for_correct_day(): void
    {
        $nextMonday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Monday');
        $result = CapacityCalculator::getAvailableSlots(self::$tenant, $nextMonday->format('Y-m-d'));

        // Should have Monday slots (early may be filtered if capacity is low)
        $this->assertIsArray($result);
        $this->assertArrayHasKey('slots', $result);
        // At least the main dinner slot should have full capacity (no bookings)
        $mainSlots = array_filter($result['slots'], fn($s) => $s['label'] === 'Main Dinner');
        $this->assertNotEmpty($mainSlots, 'Main Dinner slot should be available');
        $mainSlot = reset($mainSlots);
        $this->assertEquals(20, $mainSlot['remaining']);
    }

    #[Test]
    public function available_slots_reflects_booked_capacity(): void
    {
        $nextMonday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Monday');
        $result = CapacityCalculator::getAvailableSlots(self::$tenant, $nextMonday->format('Y-m-d'));

        $earlySlots = array_filter($result['slots'], fn($s) => $s['label'] === 'Early Dinner');
        $this->assertNotEmpty($earlySlots, 'Early Dinner slot should exist');
        $earlySlot = reset($earlySlots);
        // 10 booked out of 20, so 10 remaining
        $this->assertEquals(10, $earlySlot['remaining']);
    }

    #[Test]
    public function available_slots_filters_by_party_size(): void
    {
        $nextMonday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Monday');

        // Request party of 11 — early dinner has 10 remaining, so it should be filtered out
        $result = CapacityCalculator::getAvailableSlots(self::$tenant, $nextMonday->format('Y-m-d'), 11);

        $earlySlots = array_filter($result['slots'], fn($s) => $s['label'] === 'Early Dinner');
        $this->assertEmpty($earlySlots, 'Early Dinner should be filtered out for party of 11');
    }

    #[Test]
    public function available_slots_filters_by_max_party_size(): void
    {
        $nextMonday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Monday');

        // Request party of 9 — max_party_size is 8, should be filtered
        $result = CapacityCalculator::getAvailableSlots(self::$tenant, $nextMonday->format('Y-m-d'), 9);

        $this->assertEmpty($result['slots'], 'All slots should be filtered out for party of 9 (max_party_size=8)');
    }

    #[Test]
    public function available_slots_returns_empty_for_wrong_day(): void
    {
        // Wednesday has no slots configured
        $nextWednesday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Wednesday');
        $result = CapacityCalculator::getAvailableSlots(self::$tenant, $nextWednesday->format('Y-m-d'));

        $this->assertEmpty($result['slots']);
    }

    #[Test]
    public function available_slots_returns_empty_for_blocked_date(): void
    {
        $nextTuesday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Tuesday');
        $result = CapacityCalculator::getAvailableSlots(self::$tenant, $nextTuesday->format('Y-m-d'));

        $this->assertEmpty($result['slots'], 'Blocked date should have no available slots');
    }

    // ── getAvailableDates tests ──

    #[Test]
    public function available_dates_includes_days_with_slots(): void
    {
        $nextMonday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Monday');
        $month = $nextMonday->format('Y-m');
        $result = CapacityCalculator::getAvailableDates(self::$tenant, $month);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('dates', $result);
        $this->assertNotEmpty($result['dates']);

        // Next Monday should be in the available dates (it has capacity remaining)
        $this->assertContains($nextMonday->format('Y-m-d'), $result['dates']);
    }

    #[Test]
    public function available_dates_excludes_blocked_dates(): void
    {
        $nextTuesday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Tuesday');
        $month = $nextTuesday->format('Y-m');
        $result = CapacityCalculator::getAvailableDates(self::$tenant, $month);

        $this->assertNotContains($nextTuesday->format('Y-m-d'), $result['dates'],
            'Blocked Tuesday should not appear in available dates');
    }

    #[Test]
    public function available_dates_excludes_days_without_slots(): void
    {
        $nextWednesday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Wednesday');
        $month = $nextWednesday->format('Y-m');
        $result = CapacityCalculator::getAvailableDates(self::$tenant, $month);

        $this->assertNotContains($nextWednesday->format('Y-m-d'), $result['dates'],
            'Wednesday (no configured slots) should not appear');
    }

    // ── checkSlotAvailability tests ──

    #[Test]
    public function check_slot_available_with_capacity(): void
    {
        $nextMonday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Monday');

        // Main dinner has no bookings, request party of 4
        $result = CapacityCalculator::checkSlotAvailability(
            self::$tenant, self::$slotMondayMain, $nextMonday->format('Y-m-d'), 4
        );

        $this->assertTrue($result['available']);
        $this->assertEquals(16, $result['remaining']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function check_slot_available_with_partial_bookings(): void
    {
        $nextMonday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Monday');

        // Early dinner has 10 booked, request party of 5
        $result = CapacityCalculator::checkSlotAvailability(
            self::$tenant, self::$slotMondayEarly, $nextMonday->format('Y-m-d'), 5
        );

        $this->assertTrue($result['available']);
        $this->assertEquals(5, $result['remaining']); // 10 remaining - 5 requested = 5
        $this->assertNull($result['error']);
    }

    #[Test]
    public function check_slot_exceeds_capacity(): void
    {
        $nextMonday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Monday');

        // Early dinner has 10 booked (cap 20), request a valid party_size (<=8) that exceeds remaining
        // We need party_size > remaining (10). But max_party_size is 8.
        // So let's check with the Tuesday slot (cap 10, max_party_size 4) on a non-blocked future Tuesday.
        // Instead test with Monday early: 10 booked + request 8 = 18, which fits (remaining=10, 8<=10).
        // We need a scenario where remaining < party_size. Let's use party_size=8 but add enough bookings.
        // Actually the simplest fix: early has 10 remaining, max_party_size=8, so request 8 fits.
        // For a true capacity_exceeded, we need more bookings. Just test that requesting more than remaining fails.
        // Use Tuesday slot (cap=10, max_party=4) on a non-blocked date, with no bookings, party_size=4 should work.
        // For the actual capacity test, add another Monday early booking to push it over.

        // Add temp booking of 8 more for Monday early (total booked = 18, remaining = 2)
        $customerId = Database::query(
            'SELECT `id` FROM `customers` WHERE `tenant_id` = ?', [self::$tenantId]
        )[0]['id'];

        $mondayDate = $nextMonday->format('Y-m-d');
        $tempBookingId = Ulid::generate();
        Database::execute(
            "INSERT INTO `bookings` (`id`, `tenant_id`, `customer_id`, `booking_pattern`, `start_datetime`, `end_datetime`, `party_size`, `status`, `source`)
             VALUES (?, ?, ?, 'capacity', ?, ?, 8, 'confirmed', 'web')",
            [$tempBookingId, self::$tenantId, $customerId, "{$mondayDate} 18:00:00", "{$mondayDate} 19:30:00"]
        );

        // Now 18 booked out of 20, remaining = 2. Request party of 4 (within max_party_size=8).
        $result = CapacityCalculator::checkSlotAvailability(
            self::$tenant, self::$slotMondayEarly, $nextMonday->format('Y-m-d'), 4
        );

        $this->assertFalse($result['available']);
        $this->assertEquals('capacity_exceeded', $result['error']);

        // Cleanup temp booking
        Database::execute('DELETE FROM `bookings` WHERE `id` = ?', [$tempBookingId]);
    }

    #[Test]
    public function check_slot_party_too_large(): void
    {
        $nextMonday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Monday');

        // max_party_size = 8, request party of 9
        $result = CapacityCalculator::checkSlotAvailability(
            self::$tenant, self::$slotMondayMain, $nextMonday->format('Y-m-d'), 9
        );

        $this->assertFalse($result['available']);
        $this->assertEquals('party_too_large', $result['error']);
    }

    #[Test]
    public function check_slot_blocked_date(): void
    {
        $nextTuesday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Tuesday');

        $result = CapacityCalculator::checkSlotAvailability(
            self::$tenant, self::$slotTuesdayEarly, $nextTuesday->format('Y-m-d'), 2
        );

        $this->assertFalse($result['available']);
        $this->assertEquals('date_blocked', $result['error']);
    }

    #[Test]
    public function check_slot_wrong_day_of_week(): void
    {
        // Monday slot on a Tuesday date
        $nextTuesday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Tuesday');

        // Use a non-blocked future Tuesday (two weeks out)
        $futureTuesday = $nextTuesday->modify('+7 days');

        $result = CapacityCalculator::checkSlotAvailability(
            self::$tenant, self::$slotMondayEarly, $futureTuesday->format('Y-m-d'), 2
        );

        $this->assertFalse($result['available']);
        $this->assertEquals('slot_not_on_day', $result['error']);
    }

    #[Test]
    public function check_slot_not_found(): void
    {
        $nextMonday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Monday');

        $result = CapacityCalculator::checkSlotAvailability(
            self::$tenant, 'NONEXISTENT_SLOT_ID', $nextMonday->format('Y-m-d'), 2
        );

        $this->assertFalse($result['available']);
        $this->assertEquals('slot_not_found', $result['error']);
    }

    // ── min_party_size enforcement tests ──

    #[Test]
    public function check_slot_party_too_small(): void
    {
        $nextMonday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Monday');

        // Late Dinner has min_party_size=3, request party of 2
        $result = CapacityCalculator::checkSlotAvailability(
            self::$tenant, self::$slotMondayLate, $nextMonday->format('Y-m-d'), 2
        );

        $this->assertFalse($result['available']);
        $this->assertEquals('party_too_small', $result['error']);
    }

    #[Test]
    public function check_slot_party_at_minimum_is_accepted(): void
    {
        $nextMonday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Monday');

        // Late Dinner has min_party_size=3, request exactly 3
        $result = CapacityCalculator::checkSlotAvailability(
            self::$tenant, self::$slotMondayLate, $nextMonday->format('Y-m-d'), 3
        );

        $this->assertTrue($result['available']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function available_slots_filters_below_min_party_size(): void
    {
        $nextMonday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Monday');

        // Request party of 2: Late Dinner (min=3) should be excluded, Early/Main (min=1) included
        $result = CapacityCalculator::getAvailableSlots(self::$tenant, $nextMonday->format('Y-m-d'), 2);

        $lateSlots = array_filter($result['slots'], fn($s) => $s['label'] === 'Late Dinner');
        $this->assertEmpty($lateSlots, 'Late Dinner (min_party_size=3) should be filtered for party of 2');

        $earlySlots = array_filter($result['slots'], fn($s) => $s['label'] === 'Early Dinner');
        $this->assertNotEmpty($earlySlots, 'Early Dinner (min_party_size=1) should remain for party of 2');
    }

    #[Test]
    public function available_slots_includes_slot_at_min_party_boundary(): void
    {
        $nextMonday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Monday');

        // Request party of 3: Late Dinner (min=3) should be included
        $result = CapacityCalculator::getAvailableSlots(self::$tenant, $nextMonday->format('Y-m-d'), 3);

        $lateSlots = array_filter($result['slots'], fn($s) => $s['label'] === 'Late Dinner');
        $this->assertNotEmpty($lateSlots, 'Late Dinner (min_party_size=3) should be included for party of 3');
    }

    #[Test]
    public function available_slots_returns_min_party_size_field(): void
    {
        $nextMonday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Monday');
        $result = CapacityCalculator::getAvailableSlots(self::$tenant, $nextMonday->format('Y-m-d'), 3);

        $lateSlots = array_values(array_filter($result['slots'], fn($s) => $s['label'] === 'Late Dinner'));
        $this->assertNotEmpty($lateSlots);
        $this->assertArrayHasKey('min_party_size', $lateSlots[0]);
        $this->assertSame(3, $lateSlots[0]['min_party_size']);
    }
}
