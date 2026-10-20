<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the "Who's Working Today" dashboard section.
 *
 * Covers:
 * - Staff-relevant tenant with staff working today
 * - Staff-relevant tenant with staff but none working today
 * - Timeslot tenant without staff-required services has no staff setup warning
 * - Non-staff-relevant pattern does not show the section
 * - Tenant scoping: staff from another tenant never appears
 */
final class DashboardStaffTodayTest extends TestCase
{
    private static bool $appReachable = false;
    private static bool $dbReady = false;
    private string $baseUrl;
    private string $cookieJar;

    // Tenant IDs
    private const TENANT_TIMESLOT_ID  = '01TESTTENANT000000000000'; // from TestFixtures
    private const TENANT_RESOURCE_ID  = '01TESTDASH_RSRC_TENANT00';
    private const TENANT_NONSTAFFING  = '01TESTDASH_NSTAFF_TN000';

    // Staff IDs
    private const STAFF_WORKING_ID    = '01TESTDASH_STAFF_WORK00';
    private const STAFF_OFF_ID        = '01TESTDASH_STAFF_OFF000';
    private const STAFF_RIVAL_ID      = '01TESTDASH_STAFF_RIVAL0';

    // Distinctive names for leak detection
    private const STAFF_WORKING_NAME  = 'DashWork Alice';
    private const STAFF_OFF_NAME      = 'DashOff Bob';
    private const STAFF_RIVAL_NAME    = 'DashRival Carol';

    public static function setUpBeforeClass(): void
    {
        $baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');

        $ch = curl_init($baseUrl . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 5]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

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
        if (!self::$appReachable || !self::$dbReady) {
            $this->markTestSkipped('App/DB not reachable');
        }

        $this->baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'vb_dash_staff_') ?: '/tmp/vb_dash_staff';

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
    // 1. Timeslot tenant with staff working today
    // ════════════════════════════════════════════════════════════════

    public function test_timeslot_dashboard_shows_working_staff(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants/' . self::TENANT_TIMESLOT_ID);
        $this->assertSame(200, $r['code']);

        // Section title must appear
        $this->assertStringContainsString("Who's working today", $r['body'],
            'Section title must render for timeslot tenant');

        // Working staff name
        $this->assertStringContainsString(self::STAFF_WORKING_NAME, $r['body'],
            'Staff member with availability today must appear');

        // Time window badge
        $this->assertStringContainsString('vb-staff-today-window', $r['body'],
            'Time window badge must render');
    }

    // ════════════════════════════════════════════════════════════════
    // 2. Staff exist but none working today
    // ════════════════════════════════════════════════════════════════

    public function test_timeslot_dashboard_shows_none_today_when_off(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants/' . self::TENANT_TIMESLOT_ID);
        $this->assertSame(200, $r['code']);

        // The off-today staff member must NOT appear as a working row
        $this->assertStringNotContainsString(self::STAFF_OFF_NAME, $r['body'],
            'Staff member not scheduled today must not appear in working list');
    }

    // ════════════════════════════════════════════════════════════════
    // 3. Non-timeslot tenant hides the section
    // ════════════════════════════════════════════════════════════════

    public function test_resource_tenant_dashboard_hides_staff_section(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants/' . self::TENANT_RESOURCE_ID);
        $this->assertSame(200, $r['code']);

        $this->assertStringNotContainsString('vb-dash-staff-today-wrap', $r['body'],
            'Resource tenant must not show the staff working today section');
        $this->assertStringNotContainsString("Who's working today", $r['body'],
            'Non-timeslot tenant must not render staff section title');
    }

    // ════════════════════════════════════════════════════════════════
    // 4. Timeslot tenant with no staff → CTA to create
    // ════════════════════════════════════════════════════════════════

    public function test_timeslot_without_staff_required_services_does_not_request_staff(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants/' . self::TENANT_NONSTAFFING);
        $this->assertSame(200, $r['code']);

        $this->assertStringNotContainsString('vb-dash-staff-today-wrap', $r['body'],
            'A business with no staff has no staff schedule section');
        $this->assertStringNotContainsString('staff/create', $r['body'],
            'A timeslot pattern alone does not require staff');
    }

    // ════════════════════════════════════════════════════════════════
    // 5. Cross-tenant isolation: rival staff never appears
    // ════════════════════════════════════════════════════════════════

    public function test_rival_staff_never_appears_on_dashboard(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants/' . self::TENANT_TIMESLOT_ID);
        $this->assertSame(200, $r['code']);

        $this->assertStringNotContainsString(self::STAFF_RIVAL_NAME, $r['body'],
            'Staff from another tenant must never appear on dashboard');
    }

    // ════════════════════════════════════════════════════════════════
    // Fixtures
    // ════════════════════════════════════════════════════════════════

    private static function seedFixtures(): void
    {
        // Today's ISO day of week (0=Mon … 6=Sun)
        $todayDow = ((int) date('N')) - 1;
        // A day NOT today
        $otherDow = ($todayDow + 3) % 7;

        // ── Clean up test rows ──
        Database::execute("DELETE FROM `availability` WHERE `tenant_id` IN (?, ?)", [
            self::TENANT_TIMESLOT_ID, self::TENANT_RESOURCE_ID,
        ]);
        Database::execute("DELETE FROM `staff` WHERE `id` IN (?, ?, ?)", [
            self::STAFF_WORKING_ID, self::STAFF_OFF_ID, self::STAFF_RIVAL_ID,
        ]);
        Database::execute("DELETE FROM `tenants` WHERE `id` IN (?, ?)", [
            self::TENANT_RESOURCE_ID, self::TENANT_NONSTAFFING,
        ]);

        // ── Tenant B: resource pattern (non-timeslot) ──
        Database::execute(
            "INSERT INTO `tenants`
             (`id`, `name`, `slug`, `email`, `booking_pattern`, `timezone`, `currency`, `brand_color`, `status`)
             VALUES (?, 'Dashboard Resource Biz', 'dash-resource-test', 'rsc@example.com', 'resource', 'UTC', 'EUR', '#2563EB', 'active')",
            [self::TENANT_RESOURCE_ID]
        );

        // ── Tenant C: timeslot pattern but NO staff ──
        Database::execute(
            "INSERT INTO `tenants`
             (`id`, `name`, `slug`, `email`, `booking_pattern`, `timezone`, `currency`, `brand_color`, `status`)
             VALUES (?, 'Dashboard Nostaff Biz', 'dash-nostaff-test', 'nostaff@example.com', 'timeslot', 'UTC', 'EUR', '#2563EB', 'active')",
            [self::TENANT_NONSTAFFING]
        );

        // ── Staff for timeslot tenant (A) ──
        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `title`, `is_active`, `sort_order`)
             VALUES (?, ?, ?, 'dashwork@example.com', 'Lead Stylist', 1, 1)",
            [self::STAFF_WORKING_ID, self::TENANT_TIMESLOT_ID, self::STAFF_WORKING_NAME]
        );
        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `title`, `is_active`, `sort_order`)
             VALUES (?, ?, ?, 'dashoff@example.com', 'Junior Stylist', 1, 2)",
            [self::STAFF_OFF_ID, self::TENANT_TIMESLOT_ID, self::STAFF_OFF_NAME]
        );

        // ── Staff for resource tenant (rival — should never leak) ──
        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `title`, `is_active`, `sort_order`)
             VALUES (?, ?, ?, 'dashrival@example.com', 'Receptionist', 1, 1)",
            [self::STAFF_RIVAL_ID, self::TENANT_RESOURCE_ID, self::STAFF_RIVAL_NAME]
        );

        // ── Availability: working staff has override for TODAY ──
        Database::execute(
            "INSERT INTO `availability` (`id`, `tenant_id`, `staff_id`, `day_of_week`, `start_time`, `end_time`, `is_available`)
             VALUES (?, ?, ?, ?, '09:00', '17:00', 1)",
            [Ulid::generate(), self::TENANT_TIMESLOT_ID, self::STAFF_WORKING_ID, $todayDow]
        );

        // ── Availability: off staff has override for a DIFFERENT day ──
        Database::execute(
            "INSERT INTO `availability` (`id`, `tenant_id`, `staff_id`, `day_of_week`, `start_time`, `end_time`, `is_available`)
             VALUES (?, ?, ?, ?, '10:00', '16:00', 1)",
            [Ulid::generate(), self::TENANT_TIMESLOT_ID, self::STAFF_OFF_ID, $otherDow]
        );

        // ── Rival staff gets availability for today on THEIR tenant ──
        Database::execute(
            "INSERT INTO `availability` (`id`, `tenant_id`, `staff_id`, `day_of_week`, `start_time`, `end_time`, `is_available`)
             VALUES (?, ?, ?, ?, '08:00', '20:00', 1)",
            [Ulid::generate(), self::TENANT_RESOURCE_ID, self::STAFF_RIVAL_ID, $todayDow]
        );
    }

    // ════════════════════════════════════════════════════════════════
    // HTTP helpers
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
     * @return array{code: int, body: string, location: string, headers: string}
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
        curl_close($ch);

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
        curl_close($ch);

        $body = substr($response, $headerSize);
        $headers = substr($response, 0, $headerSize);
        $location = '';
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
            $location = trim($m[1]);
        }

        return compact('code', 'body', 'location');
    }
}
