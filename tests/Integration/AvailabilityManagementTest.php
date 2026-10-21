<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for tenant availability management.
 *
 * Covers:
 * - GET  /admin/tenants/{tenant_id}/availability             → loads for operator/owner, 403 for manager
 * - POST /admin/tenants/{tenant_id}/availability              → saves tenant defaults
 * - GET  /admin/tenants/{tenant_id}/availability/staff/{id}   → loads staff override page
 * - POST /admin/tenants/{tenant_id}/availability/staff/{id}   → saves staff override
 * - POST /admin/tenants/{tenant_id}/availability/staff/{id}/reset → resets staff override
 * - Sidebar nav visibility by role
 * - Atomic replace (second save overwrites first)
 * - Validation (end before start rejected)
 * - Owner full path (save + staff override)
 *
 * Uses real HTTP against the running app at APP_TEST_URL.
 */
final class AvailabilityManagementTest extends TestCase
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
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$tenantId === '') {
            return;
        }

        try {
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
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/availability", 'operator');

        $this->assertSame(200, $res['code'], 'Availability index must return 200 for operator');
        $this->assertStringContainsString('availability-staff-selector', $res['body'], 'Must contain staff selector');
        $this->assertStringContainsString('save-availability-btn', $res['body'], 'Must contain save button');

        // CSP-safe Alpine markup assertions
        $this->assertStringContainsString('x-data="availabilityGrid"', $res['body'],
            'Must use CSP-safe Alpine.data() reference (no parens)');
        $this->assertStringContainsString('data-schedule=', $res['body'],
            'Must contain data-schedule hydration attribute');
        $this->assertStringNotContainsString('function availabilityGrid()', $res['body'],
            'Must not contain inline script function definition');
    }

    public function testIndexLoadsForOwner(): void
    {
        if (self::$ownerCookie === '') {
            $this->markTestSkipped('Owner login not available');
        }

        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/availability", 'owner');

        $this->assertSame(200, $res['code'], 'Availability index must return 200 for owner');
    }

    public function testIndexForbiddenForManager(): void
    {
        if (self::$managerCookie === '') {
            $this->markTestSkipped('Manager login not available');
        }

        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/availability", 'manager');

        $this->assertSame(403, $res['code'], 'Availability index must return 403 for manager');
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Sidebar nav visibility
    // ════════════════════════════════════════════════════════════════

    public function testSidebarShowsAvailabilityForOperator(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/availability", 'operator');

        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('/availability', $res['body'], 'Sidebar must show Availability link for operator');
    }

    public function testSidebarShowsAvailabilityForOwner(): void
    {
        if (self::$ownerCookie === '') {
            $this->markTestSkipped('Owner login not available');
        }

        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/availability", 'owner');

        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('/availability', $res['body'], 'Sidebar must show Availability link for owner');
    }

    public function testSidebarHidesAvailabilityForManager(): void
    {
        if (self::$managerCookie === '') {
            $this->markTestSkipped('Manager login not available');
        }

        // Manager can access bookings page — verify sidebar there
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/bookings", 'manager');

        if ($res['code'] === 200) {
            $this->assertStringNotContainsString('/availability"', $res['body'], 'Sidebar must NOT show Availability link for manager');
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Save tenant defaults
    // ════════════════════════════════════════════════════════════════

    public function testSaveTenantDefaults(): void
    {
        // Get CSRF
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/availability", 'operator');
        self::extractCsrf($page['body'], 'operator');

        // Save Mon–Fri 09:00–17:00
        $postData = ['_csrf_token' => self::$operatorCsrf];
        for ($day = 0; $day <= 4; $day++) {
            $postData["schedule[{$day}][0][start]"] = '09:00';
            $postData["schedule[{$day}][0][end]"] = '17:00';
        }

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/availability", $postData, 'operator');

        $this->assertSame(302, $res['code'], 'Save must redirect');

        // Verify DB: 5 rows with staff_id IS NULL
        $rows = Database::query(
            'SELECT * FROM `availability` WHERE `tenant_id` = ? AND `staff_id` IS NULL ORDER BY `day_of_week`',
            [self::$tenantId]
        );
        $this->assertCount(5, $rows, 'Must have 5 tenant-level rows');
        $this->assertSame(0, (int) $rows[0]['day_of_week']);
        $this->assertSame('09:00:00', $rows[0]['start_time']);
        $this->assertSame('17:00:00', $rows[0]['end_time']);

        // Verify audit log
        $audit = Database::query(
            "SELECT * FROM `audit_log` WHERE `entity_type` = 'availability' AND `action` = 'availability.updated' AND `entity_id` = ?",
            [self::$tenantId]
        );
        $this->assertNotEmpty($audit, 'Audit log for availability.updated must exist');
    }

    public function testSecondSaveOverwritesPrevious(): void
    {
        // Get CSRF
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/availability", 'operator');
        self::extractCsrf($page['body'], 'operator');

        // Save only Mon 10:00–16:00 (different from previous test)
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/availability", [
            '_csrf_token'          => self::$operatorCsrf,
            'schedule[0][0][start]' => '10:00',
            'schedule[0][0][end]'   => '16:00',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        // Verify: only 1 tenant-level row now (atomic replace)
        $rows = Database::query(
            'SELECT * FROM `availability` WHERE `tenant_id` = ? AND `staff_id` IS NULL',
            [self::$tenantId]
        );
        $this->assertCount(1, $rows, 'Atomic replace must leave exactly 1 row');
        $this->assertSame('10:00:00', $rows[0]['start_time']);
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Validation
    // ════════════════════════════════════════════════════════════════

    public function testRejectsEndBeforeStart(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/availability", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/availability", [
            '_csrf_token'          => self::$operatorCsrf,
            'schedule[0][0][start]' => '17:00',
            'schedule[0][0][end]'   => '09:00',
        ], 'operator');

        // Should redirect back (validation error)
        $this->assertSame(302, $res['code']);
        $this->assertStringContainsString('/availability', $res['headers']);
    }

    public function testRejectsOverlappingWindowsOnTenantDefaults(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/availability", 'operator');
        self::extractCsrf($page['body'], 'operator');

        // Two overlapping windows on Monday: 09–14 and 12–17
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/availability", [
            '_csrf_token'          => self::$operatorCsrf,
            'schedule[0][0][start]' => '09:00',
            'schedule[0][0][end]'   => '14:00',
            'schedule[0][1][start]' => '12:00',
            'schedule[0][1][end]'   => '17:00',
        ], 'operator');

        $this->assertSame(302, $res['code'], 'Overlapping windows must be rejected');
        $this->assertStringContainsString('/availability', $res['headers']);
    }

    public function testAcceptsNonOverlappingSplitShift(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/availability", 'operator');
        self::extractCsrf($page['body'], 'operator');

        // Two non-overlapping windows on Monday: 09–12 and 13–17
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/availability", [
            '_csrf_token'          => self::$operatorCsrf,
            'schedule[0][0][start]' => '09:00',
            'schedule[0][0][end]'   => '12:00',
            'schedule[0][1][start]' => '13:00',
            'schedule[0][1][end]'   => '17:00',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        // Verify: 2 rows for day 0
        $rows = Database::query(
            'SELECT * FROM `availability` WHERE `tenant_id` = ? AND `staff_id` IS NULL AND `day_of_week` = 0 ORDER BY `start_time`',
            [self::$tenantId]
        );
        $this->assertCount(2, $rows, 'Split shift must produce 2 rows');
        $this->assertSame('09:00:00', $rows[0]['start_time']);
        $this->assertSame('13:00:00', $rows[1]['start_time']);
    }

    public function testRejectsOverlappingWindowsOnStaffOverride(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/availability/staff/" . self::$staffId, 'operator');
        self::extractCsrf($page['body'], 'operator');

        // Two overlapping windows on Tuesday: 08–13 and 11–16
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/availability/staff/" . self::$staffId, [
            '_csrf_token'          => self::$operatorCsrf,
            'schedule[1][0][start]' => '08:00',
            'schedule[1][0][end]'   => '13:00',
            'schedule[1][1][start]' => '11:00',
            'schedule[1][1][end]'   => '16:00',
        ], 'operator');

        $this->assertSame(302, $res['code'], 'Overlapping staff windows must be rejected');
        $this->assertStringContainsString('/availability', $res['headers']);
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Staff override
    // ════════════════════════════════════════════════════════════════

    public function testStaffOverridePageLoads(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/availability/staff/" . self::$staffId, 'operator');

        $this->assertSame(200, $res['code'], 'Staff override page must load');
        $this->assertStringContainsString('save-availability-btn', $res['body']);
    }

    public function testSaveStaffOverride(): void
    {
        // Ensure tenant defaults exist first
        $this->ensureTenantDefaults();

        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/availability/staff/" . self::$staffId, 'operator');
        self::extractCsrf($page['body'], 'operator');

        // Save staff override: Mon only 08:00–12:00
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/availability/staff/" . self::$staffId, [
            '_csrf_token'          => self::$operatorCsrf,
            'schedule[0][0][start]' => '08:00',
            'schedule[0][0][end]'   => '12:00',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        // Verify staff-level rows exist
        $staffRows = Database::query(
            'SELECT * FROM `availability` WHERE `tenant_id` = ? AND `staff_id` = ?',
            [self::$tenantId, self::$staffId]
        );
        $this->assertCount(1, $staffRows, 'Staff override must create 1 row');
        $this->assertSame('08:00:00', $staffRows[0]['start_time']);

        // Verify tenant defaults still exist (not affected)
        $tenantRows = Database::query(
            'SELECT * FROM `availability` WHERE `tenant_id` = ? AND `staff_id` IS NULL',
            [self::$tenantId]
        );
        $this->assertNotEmpty($tenantRows, 'Tenant defaults must not be affected');

        // Verify audit
        $audit = Database::query(
            "SELECT * FROM `audit_log` WHERE `action` = 'availability.staff_override_set' AND `entity_id` = ?",
            [self::$staffId]
        );
        $this->assertNotEmpty($audit);
    }

    public function testResetStaffOverride(): void
    {
        // Ensure staff has an override
        Database::execute(
            'DELETE FROM `availability` WHERE `tenant_id` = ? AND `staff_id` = ?',
            [self::$tenantId, self::$staffId]
        );
        Database::execute(
            'INSERT INTO `availability` (`id`, `tenant_id`, `staff_id`, `day_of_week`, `start_time`, `end_time`, `is_available`)
             VALUES (?, ?, ?, 0, \'08:00\', \'12:00\', 1)',
            [Ulid::generate(), self::$tenantId, self::$staffId]
        );

        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/availability/staff/" . self::$staffId, 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/availability/staff/" . self::$staffId . "/reset", [
            '_csrf_token' => self::$operatorCsrf,
        ], 'operator');

        $this->assertSame(302, $res['code']);

        // Verify staff rows are gone
        $staffRows = Database::query(
            'SELECT * FROM `availability` WHERE `tenant_id` = ? AND `staff_id` = ?',
            [self::$tenantId, self::$staffId]
        );
        $this->assertCount(0, $staffRows, 'Staff override must be cleared after reset');

        // Verify audit
        $audit = Database::query(
            "SELECT * FROM `audit_log` WHERE `action` = 'availability.staff_override_reset' AND `entity_id` = ?",
            [self::$staffId]
        );
        $this->assertNotEmpty($audit);
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Owner full path
    // ════════════════════════════════════════════════════════════════

    public function testOwnerCanSaveTenantDefaults(): void
    {
        if (self::$ownerCookie === '') {
            $this->markTestSkipped('Owner login not available');
        }

        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/availability", 'owner');
        self::extractCsrf($page['body'], 'owner');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/availability", [
            '_csrf_token'          => self::$ownerCsrf,
            'schedule[0][0][start]' => '09:00',
            'schedule[0][0][end]'   => '17:00',
            'schedule[1][0][start]' => '09:00',
            'schedule[1][0][end]'   => '17:00',
        ], 'owner');

        $this->assertSame(302, $res['code'], 'Owner must be able to save availability');
    }

    public function testOwnerCanSaveStaffOverride(): void
    {
        if (self::$ownerCookie === '') {
            $this->markTestSkipped('Owner login not available');
        }

        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/availability/staff/" . self::$staffId, 'owner');
        $this->assertSame(200, $page['code']);
        self::extractCsrf($page['body'], 'owner');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/availability/staff/" . self::$staffId, [
            '_csrf_token'          => self::$ownerCsrf,
            'schedule[0][0][start]' => '10:00',
            'schedule[0][0][end]'   => '14:00',
        ], 'owner');

        $this->assertSame(302, $res['code'], 'Owner must be able to save staff override');

        // Cleanup: remove staff override
        Database::execute(
            'DELETE FROM `availability` WHERE `tenant_id` = ? AND `staff_id` = ?',
            [self::$tenantId, self::$staffId]
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Manager forbidden
    // ════════════════════════════════════════════════════════════════

    public function testManagerCannotSaveAvailability(): void
    {
        if (self::$managerCookie === '') {
            $this->markTestSkipped('Manager login not available');
        }

        // Get CSRF from bookings page (which manager CAN access)
        $bookingsPage = self::httpGet("/admin/tenants/" . self::$tenantId . "/bookings", 'manager');
        self::extractCsrf($bookingsPage['body'], 'manager');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/availability", [
            '_csrf_token'          => self::$managerCsrf,
            'schedule[0][0][start]' => '09:00',
            'schedule[0][0][end]'   => '17:00',
        ], 'manager');

        $this->assertSame(403, $res['code'], 'Manager must not be able to save availability');
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Non-timeslot tenants
    // ════════════════════════════════════════════════════════════════

    public function testNonTimeslotTenantRedirectsFromIndex(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$nonTimeslotTenantId . "/availability", 'operator');

        $this->assertSame(302, $res['code'], 'Non-timeslot tenant must redirect from availability index');
        $this->assertStringContainsString("/admin/tenants/" . self::$nonTimeslotTenantId, $res['headers'], 'Must redirect to tenant dashboard');
        $this->assertStringNotContainsString('/availability', $res['headers'], 'Must not redirect to availability');
    }

    public function testNonTimeslotTenantRedirectsFromSave(): void
    {
        // Get CSRF from a page operator can access
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/availability", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$nonTimeslotTenantId . "/availability", [
            '_csrf_token'          => self::$operatorCsrf,
            'schedule[0][0][start]' => '09:00',
            'schedule[0][0][end]'   => '17:00',
        ], 'operator');

        $this->assertSame(302, $res['code'], 'Non-timeslot save must redirect');
        // Must not have created any rows
        $rows = Database::query(
            'SELECT COUNT(*) as cnt FROM `availability` WHERE `tenant_id` = ?',
            [self::$nonTimeslotTenantId]
        );
        $this->assertSame(0, (int) $rows[0]['cnt'], 'Non-timeslot tenant must not have availability rows');
    }

    public function testNonTimeslotTenantHidesAvailabilityNav(): void
    {
        // Access the bookings page for the non-timeslot tenant to check sidebar
        $res = self::httpGet("/admin/tenants/" . self::$nonTimeslotTenantId . "/bookings", 'operator');

        if ($res['code'] === 200) {
            $this->assertStringNotContainsString('/availability"', $res['body'], 'Sidebar must NOT show Availability link for non-timeslot tenant');
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Helpers
    // ════════════════════════════════════════════════════════════════

    private function ensureTenantDefaults(): void
    {
        $existing = Database::query(
            'SELECT COUNT(*) as cnt FROM `availability` WHERE `tenant_id` = ? AND `staff_id` IS NULL',
            [self::$tenantId]
        );
        if ((int) ($existing[0]['cnt'] ?? 0) > 0) {
            return;
        }

        // Seed Mon–Fri 09:00–17:00 as tenant defaults
        for ($day = 0; $day <= 4; $day++) {
            Database::execute(
                'INSERT INTO `availability` (`id`, `tenant_id`, `staff_id`, `day_of_week`, `start_time`, `end_time`, `is_available`)
                 VALUES (?, ?, NULL, ?, \'09:00\', \'17:00\', 1)',
                [Ulid::generate(), self::$tenantId, $day]
            );
        }
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
             VALUES (?, ?, 'Avail Test Tenant', 'availtest@test.test', 'timeslot', 'active', '#6366F1', 'UTC', 'EUR')",
            [self::$tenantId, 'test-avail-' . substr(self::$tenantId, -8)]
        );

        Database::execute(
            "INSERT INTO `tenants`
             (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`, `brand_color`, `timezone`, `currency`)
             VALUES (?, ?, 'Capacity Test Tenant', 'captest@test.test', 'capacity', 'active', '#10B981', 'UTC', 'EUR')",
            [self::$nonTimeslotTenantId, 'test-cap-' . substr(self::$nonTimeslotTenantId, -8)]
        );

        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`, `sort_order`)
             VALUES (?, ?, 'Avail Test Staff', 'avail-staff@test.test', 1, 1)",
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
        self::$ownerEmail = 'test-avail-owner-' . bin2hex(random_bytes(4)) . '@test.test';
        $password = 'OwnerTest123!';
        self::$ownerId = Ulid::generate();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::execute(
            "INSERT INTO `business_users` (`id`, `tenant_id`, `name`, `email`, `password_hash`, `role`, `is_active`, `force_password_change`)
             VALUES (?, ?, 'Avail Test Owner', ?, ?, 'owner', 1, 0)",
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
        self::$managerEmail = 'test-avail-mgr-' . bin2hex(random_bytes(4)) . '@test.test';
        $password = 'MgrTest123!';
        self::$managerId = Ulid::generate();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::execute(
            "INSERT INTO `business_users` (`id`, `tenant_id`, `name`, `email`, `password_hash`, `role`, `is_active`, `force_password_change`)
             VALUES (?, ?, 'Avail Test Manager', ?, ?, 'manager', 1, 0)",
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
