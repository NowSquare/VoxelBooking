<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\ResourceCalculator;
use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\Ulid;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for ResourceCalculator.
 *
 * Self-seeds a "test-hotel-marina" tenant with resources, seasonal pricing,
 * and a blocked date. Cleaned up in tearDownAfterClass.
 */
class ResourceCalculatorTest extends TestCase
{
    private static array $tenant;
    private static array $resources;
    private static string $tenantId;
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
            Database::execute('DELETE FROM `seasonal_pricing` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `blocked_dates` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `bookings` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `resources` WHERE `tenant_id` = ?', [self::$tenantId]);
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
             `brand_color`, `timezone`, `locale`, `currency`, `requires_consent`, `consent_text`,
             `max_advance_days`)
             VALUES (?, 'test-hotel-marina', 'Test Hotel Marina', 'test@hotel-marina.test', 'resource', 'active',
             '#0EA5E9', 'Europe/Rome', 'en', 'EUR', 1,
             'I consent to the processing of my personal data.', 90)",
            [$tid]
        );

        self::$tenant = Database::query('SELECT * FROM `tenants` WHERE `id` = ? LIMIT 1', [$tid])[0];

        // Resources
        $r1 = Ulid::generate();
        $r2 = Ulid::generate();
        $r3 = Ulid::generate();

        Database::execute(
            "INSERT INTO `resources` (`id`, `tenant_id`, `name`, `description`, `capacity`,
             `price_per_night`, `min_stay_nights`, `max_stay_nights`, `amenities`, `sort_order`, `is_active`)
             VALUES (?, ?, 'Sea View Suite', 'Test suite', 2, 185.00, 2, 14, ?, 1, 1)",
            [$r1, $tid, json_encode(['Wi-Fi', 'Sea view'])]
        );
        Database::execute(
            "INSERT INTO `resources` (`id`, `tenant_id`, `name`, `description`, `capacity`,
             `price_per_night`, `min_stay_nights`, `max_stay_nights`, `amenities`, `sort_order`, `is_active`)
             VALUES (?, ?, 'Garden Room', 'Test room', 2, 120.00, 1, 30, ?, 2, 1)",
            [$r2, $tid, json_encode(['Wi-Fi', 'Garden view'])]
        );
        Database::execute(
            "INSERT INTO `resources` (`id`, `tenant_id`, `name`, `description`, `capacity`,
             `price_per_night`, `min_stay_nights`, `max_stay_nights`, `amenities`, `sort_order`, `is_active`)
             VALUES (?, ?, 'Family Apartment', 'Test apartment', 5, 250.00, 3, 21, ?, 3, 1)",
            [$r3, $tid, json_encode(['Wi-Fi', 'Kitchen'])]
        );

        self::$resources = Database::query(
            'SELECT * FROM `resources` WHERE `tenant_id` = ? AND `is_active` = 1 ORDER BY `sort_order` ASC',
            [$tid]
        );

        // Seasonal pricing for Sea View Suite (high season)
        Database::execute(
            "INSERT INTO `seasonal_pricing` (`id`, `resource_id`, `tenant_id`, `start_date`, `end_date`, `price_per_night`, `label`)
             VALUES (?, ?, ?, ?, ?, 249.00, 'High Season')",
            [Ulid::generate(), $r1, $tid, date('Y') . '-07-01', date('Y') . '-08-31']
        );

        // Blocked date for Garden Room (2 months out)
        $blockStart = date('Y-m', strtotime('+2 months')) . '-10';
        $blockEnd   = date('Y-m', strtotime('+2 months')) . '-15';
        Database::execute(
            "INSERT INTO `blocked_dates` (`id`, `tenant_id`, `resource_id`, `start_date`, `end_date`, `reason`)
             VALUES (?, ?, ?, ?, ?, 'Test maintenance')",
            [Ulid::generate(), $tid, $r2, $blockStart, $blockEnd]
        );

        self::$seeded = true;
    }

    // ── getAvailableDates ──

    #[Test]
    public function available_dates_returns_array_for_current_month(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Rome'));
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');

        $result = ResourceCalculator::getAvailableDates(
            self::$tenant,
            self::$resources[0]['id'],
            $year,
            $month,
        );

        $this->assertArrayHasKey('dates', $result);
        $this->assertArrayHasKey('year', $result);
        $this->assertArrayHasKey('month', $result);
        $this->assertSame($year, $result['year']);
        $this->assertSame($month, $result['month']);

        // Each date should be YYYY-MM-DD format
        foreach ($result['dates'] as $date) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $date);
        }
    }

    #[Test]
    public function available_dates_excludes_past_dates(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Rome'));
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');
        $today = $now->format('Y-m-d');

        $result = ResourceCalculator::getAvailableDates(
            self::$tenant,
            self::$resources[0]['id'],
            $year,
            $month,
        );

        foreach ($result['dates'] as $date) {
            $this->assertGreaterThanOrEqual($today, $date, 'Available dates must not be in the past');
        }
    }

    #[Test]
    public function available_dates_returns_empty_for_invalid_resource(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Rome'));

        $result = ResourceCalculator::getAvailableDates(
            self::$tenant,
            'NONEXISTENT_RESOURCE_ID',
            (int) $now->format('Y'),
            (int) $now->format('n'),
        );

        $this->assertEmpty($result['dates']);
    }

    #[Test]
    public function available_dates_far_future_returns_empty(): void
    {
        $year = (int) date('Y') + 2;

        $result = ResourceCalculator::getAvailableDates(
            self::$tenant,
            self::$resources[0]['id'],
            $year,
            6,
        );

        $this->assertEmpty($result['dates'], 'Dates beyond max_advance_days should not be available');
    }

    // ── checkAvailability ──

    #[Test]
    public function check_availability_valid_range(): void
    {
        // Book a valid future range (far enough out to avoid conflicts)
        $checkIn = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $checkOut = (new \DateTimeImmutable('+33 days'))->format('Y-m-d');

        $result = ResourceCalculator::checkAvailability(
            self::$tenant,
            self::$resources[0]['id'],
            $checkIn,
            $checkOut,
            1,
        );

        $this->assertTrue($result['available']);
        $this->assertNotNull($result['resource']);
        $this->assertSame(3, $result['nights']);
        $this->assertNull($result['error']);
        $this->assertIsArray($result['pricing']);
        $this->assertCount(3, $result['pricing']); // 3 nights = 3 pricing entries
        $this->assertGreaterThan(0, $result['total']);
    }

    #[Test]
    public function check_availability_invalid_resource(): void
    {
        $result = ResourceCalculator::checkAvailability(
            self::$tenant,
            'FAKE_RESOURCE',
            date('Y-m-d', strtotime('+30 days')),
            date('Y-m-d', strtotime('+32 days')),
        );

        $this->assertFalse($result['available']);
        $this->assertSame('resource_not_found', $result['error']);
    }

    #[Test]
    public function check_availability_checkout_before_checkin(): void
    {
        $result = ResourceCalculator::checkAvailability(
            self::$tenant,
            self::$resources[0]['id'],
            date('Y-m-d', strtotime('+32 days')),
            date('Y-m-d', strtotime('+30 days')),
        );

        $this->assertFalse($result['available']);
        $this->assertSame('invalid_date_range', $result['error']);
    }

    #[Test]
    public function check_availability_min_stay_violation(): void
    {
        // Sea View Suite has min_stay_nights = 2
        $checkIn = date('Y-m-d', strtotime('+30 days'));
        $checkOut = date('Y-m-d', strtotime('+31 days')); // 1 night only

        $result = ResourceCalculator::checkAvailability(
            self::$tenant,
            self::$resources[0]['id'], // Sea View Suite (min 2 nights)
            $checkIn,
            $checkOut,
        );

        $this->assertFalse($result['available']);
        $this->assertSame('min_stay_violation', $result['error']);
    }

    #[Test]
    public function check_availability_max_stay_violation(): void
    {
        // Sea View Suite has max_stay_nights = 14
        $checkIn = date('Y-m-d', strtotime('+30 days'));
        $checkOut = date('Y-m-d', strtotime('+50 days')); // 20 nights

        $result = ResourceCalculator::checkAvailability(
            self::$tenant,
            self::$resources[0]['id'], // Sea View Suite (max 14 nights)
            $checkIn,
            $checkOut,
        );

        $this->assertFalse($result['available']);
        $this->assertSame('max_stay_violation', $result['error']);
    }

    #[Test]
    public function check_availability_capacity_exceeded(): void
    {
        // Sea View Suite capacity = 2
        $result = ResourceCalculator::checkAvailability(
            self::$tenant,
            self::$resources[0]['id'],
            date('Y-m-d', strtotime('+30 days')),
            date('Y-m-d', strtotime('+33 days')),
            5, // 5 guests, capacity is 2
        );

        $this->assertFalse($result['available']);
        $this->assertSame('capacity_exceeded', $result['error']);
    }

    #[Test]
    public function check_availability_too_far_in_future(): void
    {
        $result = ResourceCalculator::checkAvailability(
            self::$tenant,
            self::$resources[0]['id'],
            date('Y-m-d', strtotime('+200 days')),
            date('Y-m-d', strtotime('+203 days')),
        );

        $this->assertFalse($result['available']);
        $this->assertSame('too_far', $result['error']);
    }

    #[Test]
    public function check_availability_pricing_has_per_night_breakdown(): void
    {
        $checkIn = date('Y-m-d', strtotime('+40 days'));
        $checkOut = date('Y-m-d', strtotime('+43 days')); // 3 nights

        $result = ResourceCalculator::checkAvailability(
            self::$tenant,
            self::$resources[0]['id'],
            $checkIn,
            $checkOut,
        );

        if (!$result['available']) {
            $this->markTestSkipped('Resource not available for the test range.');
        }

        $this->assertCount(3, $result['pricing']);

        foreach ($result['pricing'] as $entry) {
            $this->assertArrayHasKey('date', $entry);
            $this->assertArrayHasKey('price', $entry);
            $this->assertArrayHasKey('label', $entry);
            $this->assertGreaterThan(0, $entry['price']);
        }

        // Total should equal sum of per-night prices
        $expectedTotal = array_sum(array_column($result['pricing'], 'price'));
        $this->assertSame($expectedTotal, $result['total']);
    }

    #[Test]
    public function garden_room_allows_single_night(): void
    {
        // Garden Room has min_stay_nights = 1
        $checkIn = date('Y-m-d', strtotime('+40 days'));
        $checkOut = date('Y-m-d', strtotime('+41 days')); // 1 night

        $result = ResourceCalculator::checkAvailability(
            self::$tenant,
            self::$resources[1]['id'], // Garden Room (min 1 night)
            $checkIn,
            $checkOut,
        );

        $this->assertTrue($result['available']);
        $this->assertSame(1, $result['nights']);
    }

    #[Test]
    public function family_apartment_allows_more_guests(): void
    {
        // Family Apartment capacity = 5
        $result = ResourceCalculator::checkAvailability(
            self::$tenant,
            self::$resources[2]['id'], // Family Apartment
            date('Y-m-d', strtotime('+40 days')),
            date('Y-m-d', strtotime('+44 days')),
            4, // 4 guests, capacity is 5
        );

        $this->assertTrue($result['available']);
        $this->assertSame(4, $result['nights']);
    }
}
