<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for blocked dates management.
 *
 * Covers:
 * - GET  /admin/tenants/{tenant_id}/blocked-dates           → loads for operator/owner, 403 for manager
 * - POST /admin/tenants/{tenant_id}/blocked-dates           → creates blocked date
 * - POST /admin/tenants/{tenant_id}/blocked-dates/{id}/delete → deletes blocked date
 * - Non-timeslot tenant redirect
 * - Validation (end before start, overlap)
 * - Staff-level blocked dates
 * - Sidebar nav visibility
 * - Slot engine integration (blocked date prevents slots)
 */
final class BlockedDatesManagementTest extends TestCase
{
    private static string $baseUrl;
    private static bool $appReachable = false;
    private static bool $dbReady = false;
    private static string $setupError = '';

    // Seed data
    private static string $tenantId = '';
    private static string $staffId = '';
    private static string $nonTimeslotTenantId = '';

    // Auth state
    private static string $operatorCookie = '';
    private static string $operatorCsrf = '';
    private static string $ownerCookie = '';
    private static string $ownerCsrf = '';
    private static string $ownerId = '';
    private static string $ownerEmail = '';
    private static string $managerCookie = '';
    private static string $managerCsrf = '';
    private static string $managerId = '';
    private static string $managerEmail = '';

    public static function setUpBeforeClass(): void
    {
        self::$baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');

        $ch = curl_init(self::$baseUrl . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $result = curl_exec($ch);
        $code = ($result !== false) ? curl_getinfo($ch, CURLINFO_HTTP_CODE) : 0;
        unset($ch);

        if ($code === 0) {
            self::$setupError = 'App not reachable at ' . self::$baseUrl;
            return;
        }

        self::$appReachable = true;

        try {
            require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
            \Tests\Support\TestEnvironment::boot();
            Database::connect();
            Database::query('SELECT 1');
            self::$dbReady = true;
        } catch (\Throwable $e) {
            self::$setupError = 'DB init failed: ' . $e->getMessage();
            return;
        }

        try {
            Database::execute('TRUNCATE TABLE `rate_limits`');
        } catch (\Throwable) {
        }

        try {
            TestFixtures::provision();
            self::seedData();
            self::loginOperator();
            self::createAndLoginOwner();
            self::createAndLoginManager();
        } catch (\Throwable $e) {
            self::$setupError = 'Provisioning failed: ' . $e->getMessage();
            return;
        }
    }

    protected function setUp(): void
    {
        if (!self::$appReachable) {
            $this->markTestSkipped(self::$setupError ?: 'App not reachable');
        }
        if (!self::$dbReady || self::$tenantId === '') {
            $this->markTestSkipped(self::$setupError ?: 'DB or tenant not ready');
        }

        // Clean blocked dates between tests
        try {
            Database::execute('DELETE FROM `blocked_dates` WHERE `tenant_id` = ?', [self::$tenantId]);
        } catch (\Throwable) {
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$tenantId === '') {
            return;
        }

        try {
            Database::execute('DELETE FROM `blocked_dates` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `availability` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `staff` WHERE `tenant_id` = ?', [self::$tenantId]);
            if (self::$ownerId !== '') {
                Database::execute('DELETE FROM `business_users` WHERE `id` = ?', [self::$ownerId]);
            }
            if (self::$managerId !== '') {
                Database::execute('DELETE FROM `business_users` WHERE `id` = ?', [self::$managerId]);
            }
            Database::execute('DELETE FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
            if (self::$nonTimeslotTenantId !== '') {
                Database::execute('DELETE FROM `tenants` WHERE `id` = ?', [self::$nonTimeslotTenantId]);
            }
        } catch (\Throwable) {
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Index access by role
    // ════════════════════════════════════════════════════════════════

    public function testIndexLoadsForOperator(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/blocked-dates", 'operator');

        $this->assertSame(200, $res['code'], 'Blocked dates index must return 200 for operator');
        $this->assertStringContainsString('add-blocked-date-btn', $res['body'], 'Must contain add button');

        // CSP-safe Alpine migration assertion
        $this->assertStringContainsString('x-data="blockedDateScope"', $res['body'], 'Must use CSP-safe Alpine.data() reference');
    }

    public function testIndexLoadsForOwner(): void
    {
        if (self::$ownerCookie === '') {
            $this->markTestSkipped('Owner login not available');
        }

        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/blocked-dates", 'owner');

        $this->assertSame(200, $res['code'], 'Blocked dates index must return 200 for owner');
    }

    public function testIndexForbiddenForManager(): void
    {
        if (self::$managerCookie === '') {
            $this->markTestSkipped('Manager login not available');
        }

        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/blocked-dates", 'manager');

        $this->assertSame(403, $res['code'], 'Blocked dates must return 403 for manager');
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Sidebar nav
    // ════════════════════════════════════════════════════════════════

    public function testSidebarShowsBlockedDatesForOperator(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/blocked-dates", 'operator');

        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('/blocked-dates', $res['body'], 'Sidebar must show Blocked Dates link');
    }

    public function testSidebarHidesBlockedDatesForManager(): void
    {
        if (self::$managerCookie === '') {
            $this->markTestSkipped('Manager login not available');
        }

        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/bookings", 'manager');

        if ($res['code'] === 200) {
            $this->assertStringNotContainsString('/blocked-dates"', $res['body'], 'Sidebar must hide Blocked Dates for manager');
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Create blocked date
    // ════════════════════════════════════════════════════════════════

    public function testCreateTenantLevelBlockedDate(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/blocked-dates", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $futureDate = date('Y-m-d', strtotime('+30 days'));

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/blocked-dates", [
            '_csrf_token' => self::$operatorCsrf,
            'start_date'  => $futureDate,
            'end_date'    => $futureDate,
            'reason'      => 'Public holiday test',
        ], 'operator');

        $this->assertSame(302, $res['code'], 'Create must redirect');

        $rows = Database::query(
            'SELECT * FROM `blocked_dates` WHERE `tenant_id` = ? AND `staff_id` IS NULL',
            [self::$tenantId]
        );
        $this->assertCount(1, $rows, 'Must create 1 tenant-level blocked date');
        $this->assertSame($futureDate, $rows[0]['start_date']);
        $this->assertSame('Public holiday test', $rows[0]['reason']);

        // Verify audit
        $audit = Database::query(
            "SELECT * FROM `audit_log` WHERE `action` = 'blocked_date.created' AND `entity_id` = ?",
            [$rows[0]['id']]
        );
        $this->assertNotEmpty($audit, 'Audit log must exist');
    }

    public function testCreateStaffLevelBlockedDate(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/blocked-dates", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $start = date('Y-m-d', strtotime('+40 days'));
        $end   = date('Y-m-d', strtotime('+42 days'));

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/blocked-dates", [
            '_csrf_token' => self::$operatorCsrf,
            'start_date'  => $start,
            'end_date'    => $end,
            'reason'      => 'Staff vacation',
            'staff_id'    => self::$staffId,
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $rows = Database::query(
            'SELECT * FROM `blocked_dates` WHERE `tenant_id` = ? AND `staff_id` = ?',
            [self::$tenantId, self::$staffId]
        );
        $this->assertCount(1, $rows, 'Must create 1 staff-level blocked date');
        $this->assertSame($start, $rows[0]['start_date']);
        $this->assertSame($end, $rows[0]['end_date']);
    }

    public function testCreateMultiDayBlockedDate(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/blocked-dates", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $start = date('Y-m-d', strtotime('+50 days'));
        $end   = date('Y-m-d', strtotime('+55 days'));

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/blocked-dates", [
            '_csrf_token' => self::$operatorCsrf,
            'start_date'  => $start,
            'end_date'    => $end,
            'reason'      => 'Annual closure',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $rows = Database::query(
            'SELECT * FROM `blocked_dates` WHERE `tenant_id` = ? AND `reason` = ?',
            [self::$tenantId, 'Annual closure']
        );
        $this->assertCount(1, $rows);
        $this->assertSame($start, $rows[0]['start_date']);
        $this->assertSame($end, $rows[0]['end_date']);
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Validation
    // ════════════════════════════════════════════════════════════════

    public function testRejectsEndBeforeStart(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/blocked-dates", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/blocked-dates", [
            '_csrf_token' => self::$operatorCsrf,
            'start_date'  => '2026-06-15',
            'end_date'    => '2026-06-10',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $rows = Database::query(
            'SELECT COUNT(*) as cnt FROM `blocked_dates` WHERE `tenant_id` = ?',
            [self::$tenantId]
        );
        $this->assertSame(0, (int) $rows[0]['cnt'], 'Must not create blocked date with inverted range');
    }

    public function testRejectsInvalidDate(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/blocked-dates", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/blocked-dates", [
            '_csrf_token' => self::$operatorCsrf,
            'start_date'  => 'not-a-date',
            'end_date'    => '2026-06-15',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $rows = Database::query(
            'SELECT COUNT(*) as cnt FROM `blocked_dates` WHERE `tenant_id` = ?',
            [self::$tenantId]
        );
        $this->assertSame(0, (int) $rows[0]['cnt'], 'Must not create with invalid date');
    }

    public function testRejectsPastStartDate(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/blocked-dates", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/blocked-dates", [
            '_csrf_token' => self::$operatorCsrf,
            'start_date'  => '2020-01-15',
            'end_date'    => '2020-01-20',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $rows = Database::query(
            'SELECT COUNT(*) as cnt FROM `blocked_dates` WHERE `tenant_id` = ?',
            [self::$tenantId]
        );
        $this->assertSame(0, (int) $rows[0]['cnt'], 'Must not create blocked date in the past');
    }

    public function testRejectsUnknownStaffId(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/blocked-dates", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $futureDate = date('Y-m-d', strtotime('+35 days'));
        $fakeStaffId = Ulid::generate(); // Does not exist

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/blocked-dates", [
            '_csrf_token' => self::$operatorCsrf,
            'start_date'  => $futureDate,
            'end_date'    => $futureDate,
            'staff_id'    => $fakeStaffId,
        ], 'operator');

        $this->assertSame(302, $res['code']);

        // Must not create any row — neither tenant-level nor staff-level
        $rows = Database::query(
            'SELECT COUNT(*) as cnt FROM `blocked_dates` WHERE `tenant_id` = ?',
            [self::$tenantId]
        );
        $this->assertSame(0, (int) $rows[0]['cnt'], 'Unknown staff_id must not create any blocked date');
    }

    public function testRejectsCrossTenantStaffId(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/blocked-dates", 'operator');
        self::extractCsrf($page['body'], 'operator');

        // Create a staff member on the OTHER (non-timeslot) tenant
        $otherStaffId = Ulid::generate();
        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`, `sort_order`)
             VALUES (?, ?, 'Cross Tenant Staff', 'cross@test.test', 1, 1)",
            [$otherStaffId, self::$nonTimeslotTenantId]
        );

        $futureDate = date('Y-m-d', strtotime('+36 days'));

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/blocked-dates", [
            '_csrf_token' => self::$operatorCsrf,
            'start_date'  => $futureDate,
            'end_date'    => $futureDate,
            'staff_id'    => $otherStaffId,
        ], 'operator');

        $this->assertSame(302, $res['code']);

        // Must not create any row
        $rows = Database::query(
            'SELECT COUNT(*) as cnt FROM `blocked_dates` WHERE `tenant_id` = ?',
            [self::$tenantId]
        );
        $this->assertSame(0, (int) $rows[0]['cnt'], 'Cross-tenant staff_id must not create any blocked date');

        // Cleanup
        Database::execute('DELETE FROM `staff` WHERE `id` = ?', [$otherStaffId]);
    }
    // ════════════════════════════════════════════════════════════════
    // Tests: Delete
    // ════════════════════════════════════════════════════════════════

    public function testDeleteBlockedDate(): void
    {
        // Seed a blocked date directly
        $bdId = Ulid::generate();
        Database::execute(
            "INSERT INTO `blocked_dates` (`id`, `tenant_id`, `start_date`, `end_date`, `reason`)
             VALUES (?, ?, '2026-12-25', '2026-12-25', 'Christmas')",
            [$bdId, self::$tenantId]
        );

        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/blocked-dates", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/blocked-dates/{$bdId}/delete", [
            '_csrf_token' => self::$operatorCsrf,
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $rows = Database::query(
            'SELECT * FROM `blocked_dates` WHERE `id` = ?',
            [$bdId]
        );
        $this->assertCount(0, $rows, 'Blocked date must be deleted');

        // Verify audit
        $audit = Database::query(
            "SELECT * FROM `audit_log` WHERE `action` = 'blocked_date.deleted' AND `entity_id` = ?",
            [$bdId]
        );
        $this->assertNotEmpty($audit);
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Non-timeslot tenant
    // ════════════════════════════════════════════════════════════════

    public function testCapacityTenantCanManageGlobalBlockedDates(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$nonTimeslotTenantId . "/blocked-dates", 'operator');

        $this->assertSame(200, $res['code'], 'Capacity calendars consume global blocked dates and must expose their management');
    }

    public function testCapacityTenantCanCreateGlobalBlockedDate(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/blocked-dates", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$nonTimeslotTenantId . "/blocked-dates", [
            '_csrf_token' => self::$operatorCsrf,
            'start_date'  => '2026-12-25',
            'end_date'    => '2026-12-25',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $rows = Database::query(
            'SELECT COUNT(*) as cnt FROM `blocked_dates` WHERE `tenant_id` = ?',
            [self::$nonTimeslotTenantId]
        );
        $this->assertSame(1, (int) $rows[0]['cnt']);
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Owner access
    // ════════════════════════════════════════════════════════════════

    public function testOwnerCanCreateBlockedDate(): void
    {
        if (self::$ownerCookie === '') {
            $this->markTestSkipped('Owner login not available');
        }

        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/blocked-dates", 'owner');
        self::extractCsrf($page['body'], 'owner');

        $futureDate = date('Y-m-d', strtotime('+60 days'));

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/blocked-dates", [
            '_csrf_token' => self::$ownerCsrf,
            'start_date'  => $futureDate,
            'end_date'    => $futureDate,
            'reason'      => 'Owner test',
        ], 'owner');

        $this->assertSame(302, $res['code'], 'Owner must be able to create blocked dates');
    }

    public function testManagerCannotCreateBlockedDate(): void
    {
        if (self::$managerCookie === '') {
            $this->markTestSkipped('Manager login not available');
        }

        $bookingsPage = self::httpGet("/admin/tenants/" . self::$tenantId . "/bookings", 'manager');
        self::extractCsrf($bookingsPage['body'], 'manager');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/blocked-dates", [
            '_csrf_token' => self::$managerCsrf,
            'start_date'  => '2026-12-25',
            'end_date'    => '2026-12-25',
        ], 'manager');

        $this->assertSame(403, $res['code'], 'Manager must not be able to create blocked dates');
    }

    // ════════════════════════════════════════════════════════════════
    // Seed data
    // ════════════════════════════════════════════════════════════════

    private static function seedData(): void
    {
        self::$tenantId = Ulid::generate();
        self::$staffId = Ulid::generate();
        self::$nonTimeslotTenantId = Ulid::generate();

        Database::execute(
            "INSERT INTO `tenants`
             (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`, `brand_color`, `timezone`, `currency`)
             VALUES (?, ?, 'BD Test Tenant', 'bdtest@test.test', 'timeslot', 'active', '#6366F1', 'UTC', 'EUR')",
            [self::$tenantId, 'test-bd-' . substr(self::$tenantId, -8)]
        );

        Database::execute(
            "INSERT INTO `tenants`
             (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`, `brand_color`, `timezone`, `currency`)
             VALUES (?, ?, 'BD Cap Tenant', 'bdcap@test.test', 'capacity', 'active', '#10B981', 'UTC', 'EUR')",
            [self::$nonTimeslotTenantId, 'test-bdcap-' . substr(self::$nonTimeslotTenantId, -8)]
        );

        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`, `sort_order`)
             VALUES (?, ?, 'BD Test Staff', 'bd-staff@test.test', 1, 1)",
            [self::$staffId, self::$tenantId]
        );
    }

    private static function loginOperator(): void
    {
        $loginPage = self::httpGet('/admin/login', 'operator');
        self::extractCsrf($loginPage['body'], 'operator');

        $res = self::httpPost('/admin/login', [
            '_csrf_token' => self::$operatorCsrf,
            'email'       => TestFixtures::OPERATOR_EMAIL,
            'password'    => TestFixtures::OPERATOR_PASSWORD,
        ], 'operator');

        if (preg_match('/vb_session=([^;]+)/', $res['headers'], $m)) {
            self::$operatorCookie = 'vb_session=' . $m[1];
        }

        $dashboard = self::httpGet('/admin', 'operator');
        self::extractCsrf($dashboard['body'], 'operator');
    }

    private static function createAndLoginOwner(): void
    {
        self::$ownerEmail = 'test-bd-owner-' . bin2hex(random_bytes(4)) . '@test.test';
        $password = 'OwnerTest123!';
        self::$ownerId = Ulid::generate();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::execute(
            "INSERT INTO `business_users` (`id`, `tenant_id`, `name`, `email`, `password_hash`, `role`, `is_active`, `force_password_change`)
             VALUES (?, ?, 'BD Test Owner', ?, ?, 'owner', 1, 0)",
            [self::$ownerId, self::$tenantId, self::$ownerEmail, $hash]
        );

        $loginPage = self::httpGet('/admin/login', 'owner');
        self::extractCsrf($loginPage['body'], 'owner');

        $res = self::httpPost('/admin/login', [
            '_csrf_token' => self::$ownerCsrf,
            'email'       => self::$ownerEmail,
            'password'    => $password,
        ], 'owner');

        if (preg_match('/vb_session=([^;]+)/', $res['headers'], $m)) {
            self::$ownerCookie = 'vb_session=' . $m[1];
        }

        $dashboard = self::httpGet('/admin', 'owner');
        self::extractCsrf($dashboard['body'], 'owner');
    }

    private static function createAndLoginManager(): void
    {
        self::$managerEmail = 'test-bd-mgr-' . bin2hex(random_bytes(4)) . '@test.test';
        $password = 'MgrTest123!';
        self::$managerId = Ulid::generate();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::execute(
            "INSERT INTO `business_users` (`id`, `tenant_id`, `name`, `email`, `password_hash`, `role`, `is_active`, `force_password_change`)
             VALUES (?, ?, 'BD Test Manager', ?, ?, 'manager', 1, 0)",
            [self::$managerId, self::$tenantId, self::$managerEmail, $hash]
        );

        $loginPage = self::httpGet('/admin/login', 'manager');
        self::extractCsrf($loginPage['body'], 'manager');

        $res = self::httpPost('/admin/login', [
            '_csrf_token' => self::$managerCsrf,
            'email'       => self::$managerEmail,
            'password'    => $password,
        ], 'manager');

        if (preg_match('/vb_session=([^;]+)/', $res['headers'], $m)) {
            self::$managerCookie = 'vb_session=' . $m[1];
        }

        $dashboard = self::httpGet('/admin', 'manager');
        self::extractCsrf($dashboard['body'], 'manager');
    }

    private static function extractCsrf(string $html, string $role): void
    {
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $html, $m)) {
            match ($role) {
                'operator' => self::$operatorCsrf = $m[1],
                'owner'    => self::$ownerCsrf = $m[1],
                'manager'  => self::$managerCsrf = $m[1],
                default    => null,
            };
        }
    }

    // ════════════════════════════════════════════════════════════════
    // HTTP helpers
    // ════════════════════════════════════════════════════════════════

    /**
     * @return array{code: int, headers: string, body: string}
     */
    private static function httpGet(string $path, string $role = 'operator'): array
    {
        return self::http('GET', $path, [], $role);
    }

    /**
     * @return array{code: int, headers: string, body: string}
     */
    private static function httpPost(string $path, array $data, string $role = 'operator'): array
    {
        return self::http('POST', $path, $data, $role);
    }

    /**
     * @return array{code: int, headers: string, body: string}
     */
    private static function http(string $method, string $path, array $data = [], string $role = 'operator'): array
    {
        $cookie = match ($role) {
            'owner'   => self::$ownerCookie,
            'manager' => self::$managerCookie,
            default   => self::$operatorCookie,
        };

        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
        ]);

        $headers = [];
        if ($cookie) {
            $headers[] = 'Cookie: ' . $cookie;
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $data = TestFixtures::withCatalogVersion($path, $data, $cookie);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);

        $responseHeaders = substr($response, 0, $headerSize);

        if (preg_match('/vb_session=([^;]+)/', $responseHeaders, $m)) {
            match ($role) {
                'owner'   => self::$ownerCookie = 'vb_session=' . $m[1],
                'manager' => self::$managerCookie = 'vb_session=' . $m[1],
                default   => self::$operatorCookie = 'vb_session=' . $m[1],
            };
        }

        return [
            'code'    => $code,
            'headers' => $responseHeaders,
            'body'    => substr($response, $headerSize),
        ];
    }
}
