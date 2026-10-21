<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\Ulid;
use App\Models\Booking;
use PHPUnit\Framework\TestCase;

/**
 * DB-backed unit tests for the Booking model.
 *
 * Seeds test tenant, service, customer, and bookings in setUpBeforeClass().
 * Cleans up in tearDownAfterClass().
 */
final class BookingTest extends TestCase
{
    private static bool $dbReady = false;

    /** Fixed IDs for seeded records */
    private static string $tenantA  = '01BTESTTENANTA000000000A';
    private static string $tenantB  = '01BTESTTENANTB000000000B';
    private static string $custA    = '01BTESTCUSTA0000000000CA';

    /** @var list<string> Booking IDs seeded */
    private static array $bookingIds = [];

    public static function setUpBeforeClass(): void
    {
        try {
            require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
            \Tests\Support\TestEnvironment::boot();
            Database::connect();
            Database::query('SELECT 1');
            self::$dbReady = true;
        } catch (\Throwable) {
            return;
        }

        // Seed two tenants
        Database::execute(
            "INSERT INTO `tenants` (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`)
             VALUES (?, 'test-bm-alpha', 'BM Alpha Test', 'bm-alpha@test.com', 'timeslot', 'active')",
            [self::$tenantA]
        );
        Database::execute(
            "INSERT INTO `tenants` (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`)
             VALUES (?, 'test-bm-beta', 'BM Beta Test', 'bm-beta@test.com', 'timeslot', 'active')",
            [self::$tenantB]
        );

        // Seed a service for tenant A
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`, `price`, `is_active`, `sort_order`)
             VALUES ('01BTESTSRV00000000000000', ?, 'BM Test Svc', 30, 10.00, 1, 1)",
            [self::$tenantA]
        );

        // Seed a customer for tenant A
        Database::execute(
            "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`, `phone`)
             VALUES (?, ?, 'BM Test Customer', 'bm-cust@test.com', '555-0000')",
            [self::$custA, self::$tenantA]
        );

        // Seed bookings
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $lastWeek = date('Y-m-d', strtotime('-3 days'));

        // Tenant A: 2 confirmed, 1 cancelled
        self::seedBooking(self::$tenantA, 'confirmed', "{$tomorrow} 10:00:00", "{$tomorrow} 10:30:00");
        self::seedBooking(self::$tenantA, 'confirmed', "{$tomorrow} 11:00:00", "{$tomorrow} 11:30:00");
        self::seedBooking(self::$tenantA, 'cancelled', "{$lastWeek} 14:00:00", "{$lastWeek} 14:30:00");

        // Tenant B: 1 confirmed
        self::seedBooking(self::$tenantB, 'confirmed', "{$tomorrow} 09:00:00", "{$tomorrow} 09:30:00");
    }

    protected function setUp(): void
    {
        if (!self::$dbReady) {
            $this->markTestSkipped('Database not available');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$dbReady) {
            return;
        }

        try {
            foreach (self::$bookingIds as $id) {
                Database::execute('DELETE FROM `bookings` WHERE `id` = ?', [$id]);
            }
            Database::execute('DELETE FROM `customers` WHERE `id` = ?', [self::$custA]);
            Database::execute("DELETE FROM `services` WHERE `id` = '01BTESTSRV00000000000000'");
            Database::execute('DELETE FROM `tenants` WHERE `id` IN (?, ?)', [self::$tenantA, self::$tenantB]);
        } catch (\Throwable) {
            // best-effort
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Booking::find()
    // ════════════════════════════════════════════════════════════════

    public function test_find_returns_booking_by_id(): void
    {
        $booking = Booking::find(self::$bookingIds[0]);
        $this->assertNotNull($booking);
        $this->assertSame(self::$tenantA, $booking['tenant_id']);
        $this->assertSame('confirmed', $booking['status']);
    }

    public function test_find_returns_null_for_missing(): void
    {
        $this->assertNull(Booking::find('01NONEXISTENT00000000000'));
    }

    // ════════════════════════════════════════════════════════════════
    // Booking::forTenant()
    // ════════════════════════════════════════════════════════════════

    public function test_for_tenant_returns_only_tenant_bookings(): void
    {
        $bookings = Booking::forTenant(self::$tenantA, null, null, null, 50, 0);
        $ids = array_column($bookings, 'id');

        $this->assertContains(self::$bookingIds[0], $ids);
        $this->assertContains(self::$bookingIds[1], $ids);
        $this->assertContains(self::$bookingIds[2], $ids);
        $this->assertNotContains(self::$bookingIds[3], $ids, 'Tenant B booking must not appear');
    }

    public function test_for_tenant_filters_by_status(): void
    {
        $bookings = Booking::forTenant(self::$tenantA, 'cancelled', null, null, 50, 0);
        $this->assertCount(1, $bookings);
        $this->assertSame('cancelled', $bookings[0]['status']);
    }

    // ════════════════════════════════════════════════════════════════
    // Booking::countForTenant()
    // ════════════════════════════════════════════════════════════════

    public function test_count_for_tenant_returns_correct_totals(): void
    {
        $all = Booking::countForTenant(self::$tenantA, null, null, null);
        $this->assertSame(3, $all);

        $confirmed = Booking::countForTenant(self::$tenantA, 'confirmed', null, null);
        $this->assertSame(2, $confirmed);
    }

    // ════════════════════════════════════════════════════════════════
    // Booking::all() (cross-tenant)
    // ════════════════════════════════════════════════════════════════

    public function test_all_returns_cross_tenant_bookings(): void
    {
        $bookings = Booking::all(null, null, null, null, 100, 0);

        // Must contain bookings from both tenants
        $ourIds = array_intersect(array_column($bookings, 'id'), self::$bookingIds);
        $this->assertCount(4, $ourIds);
    }

    public function test_all_filters_by_status(): void
    {
        $cancelled = Booking::all('cancelled', null, null, null, 100, 0);
        $ourIds = array_intersect(array_column($cancelled, 'id'), self::$bookingIds);
        $this->assertCount(1, $ourIds);
    }

    // ════════════════════════════════════════════════════════════════
    // Booking::forTenantDate() — dashboard schedule strip
    // ════════════════════════════════════════════════════════════════

    public function test_for_tenant_date_returns_service_color(): void
    {
        // The seeded service has no color set — we update it for this test
        Database::execute(
            "UPDATE `services` SET `color` = '#FF5733' WHERE `id` = '01BTESTSRV00000000000000'"
        );

        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $results = Booking::forTenantDate(self::$tenantA, $tomorrow);

        $this->assertNotEmpty($results);
        $this->assertArrayHasKey('service_color', $results[0]);
        $this->assertSame('#FF5733', $results[0]['service_color']);

        // Clean up
        Database::execute(
            "UPDATE `services` SET `color` = NULL WHERE `id` = '01BTESTSRV00000000000000'"
        );
    }

    public function test_for_tenant_date_returns_staff_name(): void
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $results = Booking::forTenantDate(self::$tenantA, $tomorrow);

        $this->assertNotEmpty($results);
        // staff_name key must exist (null when booking has no staff)
        $this->assertArrayHasKey('staff_name', $results[0]);
    }

    public function test_for_tenant_date_excludes_cancelled(): void
    {
        $lastWeek = date('Y-m-d', strtotime('-3 days'));
        $results = Booking::forTenantDate(self::$tenantA, $lastWeek);

        // The cancelled booking was seeded as bookingIds[2] on this date.
        // forTenantDate() must exclude it regardless of result set size.
        $returnedIds = array_column($results, 'id');
        $this->assertNotContains(
            self::$bookingIds[2],
            $returnedIds,
            'Cancelled booking must not appear in forTenantDate() results'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Booking::allUpcoming() — operator dashboard
    // ════════════════════════════════════════════════════════════════

    public function test_all_upcoming_returns_cross_tenant_confirmed(): void
    {
        $results = Booking::allUpcoming(date('Y-m-d H:i:s'), 50);

        // Should include bookings from both tenants (seeded confirmed, future bookings)
        $tenantIds = array_unique(array_column($results, 'tenant_id'));
        // At minimum, our two test tenants should both have future confirmed bookings
        $this->assertContains(self::$tenantA, $tenantIds);
        $this->assertContains(self::$tenantB, $tenantIds);

        // All results must be confirmed
        foreach ($results as $row) {
            $this->assertSame('confirmed', $row['status']);
        }
    }

    public function test_all_upcoming_respects_limit(): void
    {
        $results = Booking::allUpcoming(date('Y-m-d H:i:s'), 2);
        $this->assertLessThanOrEqual(2, count($results));
    }

    // ════════════════════════════════════════════════════════════════
    // Booking::all() — sort support
    // ════════════════════════════════════════════════════════════════

    public function test_all_sorts_by_customer_name(): void
    {
        // Default sort is start_datetime DESC; switch to customer ASC
        $resultsAsc = Booking::all(null, null, null, null, 50, 0, 'customer', 'ASC');

        if (count($resultsAsc) < 2) {
            $this->markTestSkipped('Need at least 2 bookings to test sort order');
        }

        $names = array_column($resultsAsc, 'customer_name');
        $sorted = $names;
        sort($sorted, SORT_STRING | SORT_FLAG_CASE);

        $this->assertSame($sorted, $names, 'Results should be sorted by customer name ASC');
    }

    public function test_all_rejects_invalid_sort_column(): void
    {
        // Malicious input must not cause SQL error — falls back to start_datetime
        $results = Booking::all(null, null, null, null, 5, 0, "'; DROP TABLE bookings; --", 'ASC');

        $this->assertIsArray($results, 'Invalid sort column must not cause an error');
    }
    // ════════════════════════════════════════════════════════════════
    // Booking::find() — staff join
    // ════════════════════════════════════════════════════════════════

    public function test_find_returns_staff_name(): void
    {
        // Use any of our seeded booking IDs
        if (empty(self::$bookingIds)) {
            $this->markTestSkipped('No seeded bookings available');
        }

        $booking = Booking::find(self::$bookingIds[0]);
        $this->assertNotNull($booking, 'Seeded booking must be findable');
        $this->assertArrayHasKey('staff_name', $booking, 'find() must include staff_name from LEFT JOIN staff');
    }

    // ════════════════════════════════════════════════════════════════
    // Seed helper
    // ════════════════════════════════════════════════════════════════

    private static function seedBooking(
        string $tenantId,
        string $status,
        string $start,
        string $end,
    ): void {
        $id = Ulid::generate();
        Database::execute(
            "INSERT INTO `bookings` (`id`, `tenant_id`, `customer_id`, `booking_pattern`,
             `start_datetime`, `end_datetime`, `status`, `service_id`)
             VALUES (?, ?, ?, 'timeslot', ?, ?, ?, '01BTESTSRV00000000000000')",
            [$id, $tenantId, self::$custA, $start, $end, $status]
        );
        self::$bookingIds[] = $id;
    }
}

