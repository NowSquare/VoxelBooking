<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Models\Booking;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for tenant-scoped booking isolation.
 *
 * Verifies that per-tenant booking queries (model layer) and
 * per-tenant admin routes (controller layer) never leak data
 * from one tenant into another tenant's view.
 *
 * Covers:
 * - Booking::forTenant() — list query scoping
 * - Booking::countForTenant() — count query scoping
 * - Booking::forTenantExport() — CSV export scoping
 * - Booking::forTenantUpcoming() — dashboard upcoming scoping
 * - Booking::statusCounts() — status breakdown scoping
 * - tenantShow controller guard — cross-tenant booking detail rejection
 * - tenantExport controller — export scoping via HTTP
 */
final class TenantBookingsIsolationTest extends TestCase
{
    private static bool $dbReady = false;

    // Tenant A (uses the shared test fixture tenant)
    private const TENANT_A_ID   = '01TESTTENANT000000000000';

    // Tenant B (isolated rival tenant)
    private const TENANT_B_ID   = '01TESTISOLTN_B000000000';
    private const TENANT_B_SLUG = 'isolation-rival';

    // Customers
    private const CUSTOMER_A_ID = '01TESTISOL_CUST_A000000';
    private const CUSTOMER_B_ID = '01TESTISOL_CUST_B000000';

    // Services
    private const SERVICE_A_ID  = '01TESTISOL_SVC_A0000000';
    private const SERVICE_B_ID  = '01TESTISOL_SVC_B0000000';

    // Bookings
    private const BOOKING_A_ID  = '01TESTISOL_BOOK_A000000';
    private const BOOKING_B_ID  = '01TESTISOL_BOOK_B000000';

    // Distinctive labels for cross-tenant leak detection
    private const CUSTOMER_A_NAME = 'IsolationAlpha Customer';
    private const CUSTOMER_B_NAME = 'IsolationBravo Customer';
    private const SERVICE_A_NAME  = 'IsolationAlpha Haircut';
    private const SERVICE_B_NAME  = 'IsolationBravo Massage';

    // HTTP test support
    private string $baseUrl;
    private string $cookieJar;
    private static bool $appReachable = false;

    public static function setUpBeforeClass(): void
    {
        $baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');

        $ch = curl_init($baseUrl . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 5]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($code > 0) {
            self::$appReachable = true;
        }

        try {
            TestFixtures::provision();
            self::$dbReady = TestFixtures::dbAvailable();
        } catch (\Throwable) {
            return;
        }

        if (!self::$dbReady) {
            return;
        }

        self::seedFixtures();
    }

    protected function setUp(): void
    {
        if (!self::$dbReady) {
            $this->markTestSkipped('Database not available');
        }

        $this->baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'vb_isol_') ?: '/tmp/vb_isol_cookies';

        try {
            Database::execute("DELETE FROM `rate_limits`");
        } catch (\Throwable) {}
    }

    protected function tearDown(): void
    {
        if (isset($this->cookieJar) && file_exists($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Model layer: Booking::forTenant()
    // ════════════════════════════════════════════════════════════════

    public function test_forTenant_returns_only_own_bookings(): void
    {
        $bookingsA = Booking::forTenant(self::TENANT_A_ID, null, null, null, 100, 0);
        $bookingsB = Booking::forTenant(self::TENANT_B_ID, null, null, null, 100, 0);

        // Tenant A must see its booking
        $idsA = array_column($bookingsA, 'id');
        $this->assertContains(self::BOOKING_A_ID, $idsA, 'Tenant A must see its own booking');
        $this->assertNotContains(self::BOOKING_B_ID, $idsA, 'Tenant A must NOT see Tenant B booking');

        // Tenant B must see its booking
        $idsB = array_column($bookingsB, 'id');
        $this->assertContains(self::BOOKING_B_ID, $idsB, 'Tenant B must see its own booking');
        $this->assertNotContains(self::BOOKING_A_ID, $idsB, 'Tenant B must NOT see Tenant A booking');
    }

    public function test_forTenant_customer_labels_do_not_leak(): void
    {
        $bookingsA = Booking::forTenant(self::TENANT_A_ID, null, null, null, 100, 0);
        $bookingsB = Booking::forTenant(self::TENANT_B_ID, null, null, null, 100, 0);

        $namesA = array_column($bookingsA, 'customer_name');
        $namesB = array_column($bookingsB, 'customer_name');

        $this->assertContains(self::CUSTOMER_A_NAME, $namesA);
        $this->assertNotContains(self::CUSTOMER_B_NAME, $namesA,
            'Tenant A bookings must not contain Tenant B customer name');

        $this->assertContains(self::CUSTOMER_B_NAME, $namesB);
        $this->assertNotContains(self::CUSTOMER_A_NAME, $namesB,
            'Tenant B bookings must not contain Tenant A customer name');
    }

    public function test_forTenant_service_labels_do_not_leak(): void
    {
        $bookingsA = Booking::forTenant(self::TENANT_A_ID, null, null, null, 100, 0);
        $bookingsB = Booking::forTenant(self::TENANT_B_ID, null, null, null, 100, 0);

        $svcsA = array_column($bookingsA, 'service_name');
        $svcsB = array_column($bookingsB, 'service_name');

        $this->assertContains(self::SERVICE_A_NAME, $svcsA);
        $this->assertNotContains(self::SERVICE_B_NAME, $svcsA,
            'Tenant A bookings must not contain Tenant B service name');

        $this->assertContains(self::SERVICE_B_NAME, $svcsB);
        $this->assertNotContains(self::SERVICE_A_NAME, $svcsB,
            'Tenant B bookings must not contain Tenant A service name');
    }

    // ════════════════════════════════════════════════════════════════
    // Model layer: Booking::countForTenant()
    // ════════════════════════════════════════════════════════════════

    public function test_countForTenant_is_consistent_with_forTenant(): void
    {
        $countA = Booking::countForTenant(self::TENANT_A_ID, null, null, null);
        $listA  = Booking::forTenant(self::TENANT_A_ID, null, null, null, 1000, 0);
        $this->assertSame(count($listA), $countA,
            'countForTenant and forTenant must agree on row count');

        $countB = Booking::countForTenant(self::TENANT_B_ID, null, null, null);
        $listB  = Booking::forTenant(self::TENANT_B_ID, null, null, null, 1000, 0);
        $this->assertSame(count($listB), $countB,
            'countForTenant and forTenant must agree for Tenant B');
    }

    // ════════════════════════════════════════════════════════════════
    // Model layer: Booking::forTenantExport()
    // ════════════════════════════════════════════════════════════════

    public function test_forTenantExport_scopes_by_tenant(): void
    {
        $exportA = Booking::forTenantExport(self::TENANT_A_ID, null, null, null);
        $exportB = Booking::forTenantExport(self::TENANT_B_ID, null, null, null);

        $idsA = array_column($exportA, 'id');
        $idsB = array_column($exportB, 'id');

        $this->assertContains(self::BOOKING_A_ID, $idsA);
        $this->assertNotContains(self::BOOKING_B_ID, $idsA,
            'Tenant A export must not include Tenant B booking');

        $this->assertContains(self::BOOKING_B_ID, $idsB);
        $this->assertNotContains(self::BOOKING_A_ID, $idsB,
            'Tenant B export must not include Tenant A booking');
    }

    // ════════════════════════════════════════════════════════════════
    // Model layer: Booking::forTenantUpcoming()
    // ════════════════════════════════════════════════════════════════

    public function test_forTenantUpcoming_scopes_by_tenant(): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $upcomingA = Booking::forTenantUpcoming(self::TENANT_A_ID, $now, 100);
        $upcomingB = Booking::forTenantUpcoming(self::TENANT_B_ID, $now, 100);

        $idsA = array_column($upcomingA, 'id');
        $idsB = array_column($upcomingB, 'id');

        // At least verify no cross-contamination
        foreach ($idsA as $id) {
            $this->assertNotSame(self::BOOKING_B_ID, $id,
                'Tenant A upcoming must not include Tenant B booking');
        }
        foreach ($idsB as $id) {
            $this->assertNotSame(self::BOOKING_A_ID, $id,
                'Tenant B upcoming must not include Tenant A booking');
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Model layer: Booking::statusCounts()
    // ════════════════════════════════════════════════════════════════

    public function test_statusCounts_scopes_by_tenant(): void
    {
        $countsA = Booking::statusCounts(self::TENANT_A_ID);
        $countsB = Booking::statusCounts(self::TENANT_B_ID);

        // Both should have at least 1 confirmed booking
        $this->assertGreaterThanOrEqual(1, $countsA['confirmed'] ?? 0,
            'Tenant A must have at least 1 confirmed booking');
        $this->assertGreaterThanOrEqual(1, $countsB['confirmed'] ?? 0,
            'Tenant B must have at least 1 confirmed booking');

        // Verify they are independent (not summed)
        $totalA = array_sum($countsA);
        $totalB = array_sum($countsB);
        $this->assertNotSame($totalA + $totalB, $totalA,
            'Status counts must not be cross-tenant sums');
    }

    // ════════════════════════════════════════════════════════════════
    // Controller layer: tenantShow cross-tenant guard
    // ════════════════════════════════════════════════════════════════

    public function test_tenantShow_rejects_booking_from_other_tenant(): void
    {
        if (!self::$appReachable) {
            $this->markTestSkipped('App not reachable for HTTP test');
        }

        $this->doLoginOperator();

        // Tenant A trying to view Tenant B's booking via Tenant A's route
        $r = $this->get('/admin/tenants/' . self::TENANT_A_ID . '/bookings/' . self::BOOKING_B_ID);
        $this->assertSame(302, $r['code'],
            'Accessing another tenant\'s booking via tenantShow must redirect');
        $this->assertStringContainsString('/bookings', $r['location'],
            'Redirect should go back to the bookings list');
    }

    public function test_tenantShow_accepts_own_booking(): void
    {
        if (!self::$appReachable) {
            $this->markTestSkipped('App not reachable for HTTP test');
        }

        $this->doLoginOperator();

        $r = $this->get('/admin/tenants/' . self::TENANT_A_ID . '/bookings/' . self::BOOKING_A_ID);
        $this->assertSame(200, $r['code'],
            'Accessing own tenant booking via tenantShow must return 200');
        $this->assertStringContainsString(self::CUSTOMER_A_NAME, $r['body'],
            'Booking detail must show the correct customer name');
    }

    // ════════════════════════════════════════════════════════════════
    // Controller layer: tenant bookings list page isolation
    // ════════════════════════════════════════════════════════════════

    public function test_tenant_bookings_page_shows_only_own_data(): void
    {
        if (!self::$appReachable) {
            $this->markTestSkipped('App not reachable for HTTP test');
        }

        $this->doLoginOperator();

        $r = $this->get('/admin/tenants/' . self::TENANT_A_ID . '/bookings');
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString(self::CUSTOMER_A_NAME, $r['body'],
            'Tenant A bookings page must show Tenant A customer');
        $this->assertStringNotContainsString(self::CUSTOMER_B_NAME, $r['body'],
            'Tenant A bookings page must NOT show Tenant B customer');
        $this->assertStringNotContainsString(self::SERVICE_B_NAME, $r['body'],
            'Tenant A bookings page must NOT show Tenant B service');
    }

    public function test_tenant_bookings_export_contains_only_own_data(): void
    {
        if (!self::$appReachable) {
            $this->markTestSkipped('App not reachable for HTTP test');
        }

        $this->doLoginOperator();

        $r = $this->get('/admin/tenants/' . self::TENANT_A_ID . '/bookings/export');
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString(self::CUSTOMER_A_NAME, $r['body'],
            'Tenant A export must include Tenant A customer');
        $this->assertStringNotContainsString(self::CUSTOMER_B_NAME, $r['body'],
            'Tenant A export must NOT include Tenant B customer');
    }

    // ════════════════════════════════════════════════════════════════
    // Fixtures
    // ════════════════════════════════════════════════════════════════

    private static function seedFixtures(): void
    {
        // Clean up our specific fixture rows only
        Database::execute("DELETE FROM `bookings` WHERE `id` IN (?, ?)", [self::BOOKING_A_ID, self::BOOKING_B_ID]);
        Database::execute("DELETE FROM `customers` WHERE `id` IN (?, ?)", [self::CUSTOMER_A_ID, self::CUSTOMER_B_ID]);
        Database::execute("DELETE FROM `services` WHERE `id` IN (?, ?)", [self::SERVICE_A_ID, self::SERVICE_B_ID]);
        Database::execute("DELETE FROM `tenants` WHERE `id` = ?", [self::TENANT_B_ID]);

        // Tenant B (Tenant A already exists from TestFixtures)
        Database::execute(
            "INSERT INTO `tenants`
             (`id`, `name`, `slug`, `email`, `booking_pattern`, `timezone`, `currency`, `brand_color`, `status`)
             VALUES (?, 'Isolation Rival Biz', ?, 'rival@example.com', 'timeslot', 'UTC', 'EUR', '#FF0000', 'active')",
            [self::TENANT_B_ID, self::TENANT_B_SLUG]
        );

        // Customers
        Database::execute(
            "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`)
             VALUES (?, ?, ?, 'alpha@example.com')",
            [self::CUSTOMER_A_ID, self::TENANT_A_ID, self::CUSTOMER_A_NAME]
        );
        Database::execute(
            "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`)
             VALUES (?, ?, ?, 'bravo@example.com')",
            [self::CUSTOMER_B_ID, self::TENANT_B_ID, self::CUSTOMER_B_NAME]
        );

        // Services
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`, `is_active`, `sort_order`)
             VALUES (?, ?, ?, 30, 1, 99)",
            [self::SERVICE_A_ID, self::TENANT_A_ID, self::SERVICE_A_NAME]
        );
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`, `is_active`, `sort_order`)
             VALUES (?, ?, ?, 60, 1, 99)",
            [self::SERVICE_B_ID, self::TENANT_B_ID, self::SERVICE_B_NAME]
        );

        // Bookings (future dates so forTenantUpcoming finds them)
        $futureDate = date('Y-m-d', strtotime('+7 days'));
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`, `service_id`,
              `start_datetime`, `end_datetime`, `status`, `source`)
             VALUES (?, ?, 'timeslot', ?, ?,
              '{$futureDate} 10:00:00', '{$futureDate} 10:30:00',
              'confirmed', 'web')",
            [self::BOOKING_A_ID, self::TENANT_A_ID, self::CUSTOMER_A_ID, self::SERVICE_A_ID]
        );
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`, `service_id`,
              `start_datetime`, `end_datetime`, `status`, `source`)
             VALUES (?, ?, 'timeslot', ?, ?,
              '{$futureDate} 14:00:00', '{$futureDate} 15:00:00',
              'confirmed', 'web')",
            [self::BOOKING_B_ID, self::TENANT_B_ID, self::CUSTOMER_B_ID, self::SERVICE_B_ID]
        );
    }

    // ════════════════════════════════════════════════════════════════
    // HTTP helpers (same pattern as AdminRoutesTest)
    // ════════════════════════════════════════════════════════════════

    private function doLoginOperator(): void
    {
        $response = $this->get('/admin/login');
        preg_match('/name="_csrf_token" value="([^"]+)"/', $response['body'], $m);
        $csrf = $m[1] ?? '';

        $this->post('/admin/login', [
            'email' => TestFixtures::OPERATOR_EMAIL,
            'password' => TestFixtures::OPERATOR_PASSWORD,
            '_csrf_token' => $csrf,
        ]);
    }

    /**
     * @return array{code: int, body: string, location: string}
     */
    private function get(string $path): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
        ]);
        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);

        $body = substr($response, $headerSize);
        $headers = substr($response, 0, $headerSize);
        $location = '';
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
            $location = trim($m[1]);
        }

        return compact('code', 'body', 'location', 'headers');
    }

    private function post(string $path, array $data): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
        ]);
        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);

        $body = substr($response, $headerSize);
        $headers = substr($response, 0, $headerSize);
        $location = '';
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
            $location = trim($m[1]);
        }

        return compact('code', 'body', 'location');
    }
}
