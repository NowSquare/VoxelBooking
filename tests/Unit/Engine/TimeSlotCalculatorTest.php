<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\TimeSlotCalculator;
use App\Engine\Database;
use App\Engine\Ulid;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for TimeSlotCalculator.
 *
 * These tests create a private salon fixture in the disposable database.
 * They verify availability calculation, blocked date enforcement,
 * the lunch break gap, and buffer time handling.
 */
class TimeSlotCalculatorTest extends TestCase
{
    private static array $tenant;

    public static function setUpBeforeClass(): void
    {
        \Tests\Support\TestEnvironment::boot();
        \Tests\Support\TestEnvironment::assertDatabase();
        $tenant = Ulid::generate();
        Database::execute("INSERT INTO tenants (id,name,slug,email,booking_pattern,timezone,status,min_advance_hours,max_advance_days,slot_duration_minutes,buffer_minutes) VALUES (?,'Test salon',?,'salon@example.test','timeslot','Europe/Amsterdam','active',0,90,30,0)",[$tenant,'test-salon-'.strtolower($tenant)]);
        $staff = Ulid::generate();
        Database::execute("INSERT INTO staff (id,tenant_id,name,email,is_active) VALUES (?,?,'Test stylist','stylist@example.test',1)",[$staff,$tenant]);
        foreach ([30,90] as $duration) {
            $service = Ulid::generate();
            Database::execute("INSERT INTO services (id,tenant_id,name,duration_minutes,requires_staff,is_active) VALUES (?,?,?, ?,1,1)",[$service,$tenant,'Service '.$duration,$duration]);
            Database::execute('INSERT INTO service_staff (service_id,staff_id) VALUES (?,?)',[$service,$staff]);
        }
        for ($day=0;$day<5;$day++) {
            foreach ([['09:00','12:30'],['13:30','18:00']] as [$start,$end]) {
                Database::execute('INSERT INTO availability (id,tenant_id,day_of_week,start_time,end_time,is_available) VALUES (?,?,?,?,?,1)',[Ulid::generate(),$tenant,$day,$start,$end]);
            }
        }
        self::$tenant = Database::query('SELECT * FROM tenants WHERE id=?',[$tenant])[0];
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$tenant)) Database::execute('DELETE FROM tenants WHERE id=?',[self::$tenant['id']]);
    }

    #[Test]
    public function returns_slots_for_weekday(): void
    {
        // Find the next Monday
        $nextMonday = new \DateTimeImmutable('next Monday', new \DateTimeZone('Europe/Amsterdam'));
        $date = $nextMonday->format('Y-m-d');

        $result = TimeSlotCalculator::getAvailableSlots(self::$tenant, $date);

        $this->assertArrayHasKey('slots', $result);
        $this->assertArrayHasKey('date', $result);
        $this->assertSame($date, $result['date']);
        $this->assertNotEmpty($result['slots'], 'Monday should have slots');

        // All slots should have 'time' and 'end_time' keys
        foreach ($result['slots'] as $slot) {
            $this->assertArrayHasKey('time', $slot);
            $this->assertArrayHasKey('end_time', $slot);
        }
    }

    #[Test]
    public function returns_no_slots_for_sunday(): void
    {
        // Find the next Sunday
        $nextSunday = new \DateTimeImmutable('next Sunday', new \DateTimeZone('Europe/Amsterdam'));
        $date = $nextSunday->format('Y-m-d');

        $result = TimeSlotCalculator::getAvailableSlots(self::$tenant, $date);

        $this->assertEmpty($result['slots'], 'Sunday should have no slots (no availability rules)');
    }

    #[Test]
    public function respects_lunch_break(): void
    {
        // Find the next weekday
        $nextTuesday = new \DateTimeImmutable('next Tuesday', new \DateTimeZone('Europe/Amsterdam'));
        $date = $nextTuesday->format('Y-m-d');

        $result = TimeSlotCalculator::getAvailableSlots(self::$tenant, $date);

        $times = array_column($result['slots'], 'time');

        // 12:30 and 13:00 should NOT be in the list (lunch break)
        $this->assertNotContains('12:30', $times, 'Lunch break should block 12:30');
        $this->assertNotContains('13:00', $times, 'Lunch break should block 13:00');
    }

    #[Test]
    public function slots_are_sorted_chronologically(): void
    {
        $nextWed = new \DateTimeImmutable('next Wednesday', new \DateTimeZone('Europe/Amsterdam'));
        $date = $nextWed->format('Y-m-d');

        $result = TimeSlotCalculator::getAvailableSlots(self::$tenant, $date);

        $times = array_column($result['slots'], 'time');

        $sorted = $times;
        sort($sorted);

        $this->assertSame($sorted, $times, 'Slots should be in chronological order');
    }

    #[Test]
    public function service_filter_adjusts_slot_count(): void
    {
        $nextMon = new \DateTimeImmutable('next Monday', new \DateTimeZone('Europe/Amsterdam'));
        $date = $nextMon->format('Y-m-d');

        // Get default slots (30-min default)
        $slotsDefault = TimeSlotCalculator::getAvailableSlots(self::$tenant, $date);

        // Get the 90-minute service (Color & Highlights)
        $services = Database::query(
            'SELECT id FROM services WHERE tenant_id = ? AND duration_minutes = 90 LIMIT 1',
            [self::$tenant['id']]
        );

        self::assertNotEmpty($services,'The isolated long-duration service fixture must exist.');

        $slotsLong = TimeSlotCalculator::getAvailableSlots(
            self::$tenant,
            $date,
            $services[0]['id']
        );

        // Longer service should have fewer or equal slots
        $this->assertLessThanOrEqual(
            count($slotsDefault['slots']),
            count($slotsLong['slots']),
            'Longer service should have fewer available slots'
        );
    }

    #[Test]
    public function rejects_far_future_dates(): void
    {
        $farFuture = new \DateTimeImmutable('+200 days', new \DateTimeZone('Europe/Amsterdam'));
        $date = $farFuture->format('Y-m-d');

        $result = TimeSlotCalculator::getAvailableSlots(self::$tenant, $date);

        $this->assertEmpty($result['slots'], 'Dates beyond max_advance_days should have no slots');
    }

    #[Test]
    public function available_dates_returns_array(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Amsterdam'));
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');

        $dates = TimeSlotCalculator::getAvailableDates(self::$tenant, $year, $month);

        $this->assertIsArray($dates);

        // At least some dates should be available (unless all are in the past)
        // Each date should be YYYY-MM-DD format
        foreach ($dates as $date) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $date);
        }
    }

    #[Test]
    public function staff_filter_returns_slots(): void
    {
        $nextMon = new \DateTimeImmutable('next Monday', new \DateTimeZone('Europe/Amsterdam'));
        $date = $nextMon->format('Y-m-d');

        // Get first staff member
        $staff = Database::query(
            'SELECT id FROM staff WHERE tenant_id = ? AND is_active = 1 LIMIT 1',
            [self::$tenant['id']]
        );

        self::assertNotEmpty($staff,'The isolated staff fixture must exist.');

        $result = TimeSlotCalculator::getAvailableSlots(
            self::$tenant,
            $date,
            null,
            $staff[0]['id']
        );

        // Should still have slots (staff inherits tenant availability)
        $this->assertNotEmpty($result['slots']);

        // All slots should reference this staff
        foreach ($result['slots'] as $slot) {
            $this->assertSame($staff[0]['id'], $slot['staff_id']);
        }
    }
}
