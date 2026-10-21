<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for service management.
 *
 * Covers:
 * - Index/create/edit by role (operator, owner, manager)
 * - CRUD lifecycle
 * - Validation (empty name, zero duration)
 * - Staff pivot sync (create + update)
 * - Activate / deactivate
 * - Non-timeslot tenant redirect
 * - Sidebar nav visibility
 */
final class ServiceManagementTest extends TestCase
{
    private static string $baseUrl;
    private static bool $appReachable = false;
    private static bool $dbReady = false;
    private static string $setupError = '';

    // Seed data
    private static string $tenantId = '';
    private static string $staffId = '';
    private static string $staffId2 = '';
    private static string $nonTimeslotTenantId = '';

    // Auth
    private static string $operatorCookie = '';
    private static string $operatorCsrf = '';
    private static string $ownerCookie = '';
    private static string $ownerCsrf = '';
    private static string $ownerId = '';
    private static string $managerCookie = '';
    private static string $managerCsrf = '';
    private static string $managerId = '';

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

        // Clean services between tests
        try {
            Database::execute('DELETE FROM `service_staff` WHERE `service_id` IN (SELECT `id` FROM `services` WHERE `tenant_id` = ?)', [self::$tenantId]);
            Database::execute('DELETE FROM `services` WHERE `tenant_id` = ?', [self::$tenantId]);
        } catch (\Throwable) {
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$tenantId === '') {
            return;
        }

        try {
            Database::execute('DELETE FROM `service_staff` WHERE `service_id` IN (SELECT `id` FROM `services` WHERE `tenant_id` = ?)', [self::$tenantId]);
            Database::execute('DELETE FROM `services` WHERE `tenant_id` = ?', [self::$tenantId]);
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
    // Index access
    // ════════════════════════════════════════════════════════════════

    public function testIndexLoadsForOperator(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/services", 'operator');

        $this->assertSame(200, $res['code'], 'Services index must return 200 for operator');
        $this->assertStringContainsString('new-service-btn', $res['body'], 'Must show New Service button');
    }

    public function testIndexLoadsForOwner(): void
    {
        if (self::$ownerCookie === '') {
            $this->markTestSkipped('Owner login not available');
        }

        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/services", 'owner');

        $this->assertSame(200, $res['code'], 'Services index must return 200 for owner');
    }

    public function testIndexForbiddenForManager(): void
    {
        if (self::$managerCookie === '') {
            $this->markTestSkipped('Manager login not available');
        }

        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/services", 'manager');

        $this->assertSame(403, $res['code'], 'Services must return 403 for manager');
    }

    // ════════════════════════════════════════════════════════════════
    // Sidebar nav
    // ════════════════════════════════════════════════════════════════

    public function testSidebarShowsServicesForOperator(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/services", 'operator');

        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('/services"', $res['body'], 'Sidebar must contain Services link');
    }

    public function testSidebarHidesServicesForManager(): void
    {
        if (self::$managerCookie === '') {
            $this->markTestSkipped('Manager login not available');
        }

        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/bookings", 'manager');

        if ($res['code'] === 200) {
            $this->assertStringNotContainsString('/services"', $res['body'], 'Sidebar must hide Services for manager');
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Create
    // ════════════════════════════════════════════════════════════════

    public function testCreateFormLoads(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/services/create", 'operator');

        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('svc-name', $res['body'], 'Create form must contain name field');
    }

    public function testCreateServiceWithStaff(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/services/create", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/services", [
            '_csrf_token'      => self::$operatorCsrf,
            'name'             => 'Test Haircut',
            'description'      => 'A test service',
            'duration_minutes' => '30',
            'price'            => '25.00',
            'category'         => 'Haircuts',
            'color'            => '#6366F1',
            'sort_order'       => '1',
            'staff_ids'        => [self::$staffId, self::$staffId2],
        ], 'operator');

        $this->assertSame(302, $res['code'], 'Create must redirect');

        $rows = Database::query(
            'SELECT * FROM `services` WHERE `tenant_id` = ? AND `name` = ?',
            [self::$tenantId, 'Test Haircut']
        );
        $this->assertCount(1, $rows, 'Must create exactly 1 service');
        $this->assertSame(30, (int) $rows[0]['duration_minutes']);
        $this->assertSame('25.00', $rows[0]['price']);
        $this->assertSame('Haircuts', $rows[0]['category']);
        $this->assertSame('#6366F1', $rows[0]['color']);

        // Check pivot
        $pivots = Database::query(
            'SELECT `staff_id` FROM `service_staff` WHERE `service_id` = ? ORDER BY `staff_id`',
            [$rows[0]['id']]
        );
        $this->assertCount(2, $pivots, 'Must link 2 staff members');

        // Check audit
        $audit = Database::query(
            "SELECT * FROM `audit_log` WHERE `action` = 'service.created' AND `entity_id` = ?",
            [$rows[0]['id']]
        );
        $this->assertNotEmpty($audit, 'Audit log must exist');
    }

    public function testCreateRejectsEmptyName(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/services/create", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/services", [
            '_csrf_token'      => self::$operatorCsrf,
            'name'             => '',
            'duration_minutes' => '30',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $cnt = Database::query('SELECT COUNT(*) as cnt FROM `services` WHERE `tenant_id` = ?', [self::$tenantId]);
        $this->assertSame(0, (int) $cnt[0]['cnt'], 'Must not create service with empty name');
    }

    public function testCreateRejectsZeroDuration(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/services/create", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/services", [
            '_csrf_token'      => self::$operatorCsrf,
            'name'             => 'Bad Duration Service',
            'duration_minutes' => '0',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $cnt = Database::query('SELECT COUNT(*) as cnt FROM `services` WHERE `tenant_id` = ?', [self::$tenantId]);
        $this->assertSame(0, (int) $cnt[0]['cnt'], 'Must not create service with zero duration');
    }

    public function testCreateRejectsUnknownStaffId(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/services/create", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $fakeStaffId = Ulid::generate();

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/services", [
            '_csrf_token'      => self::$operatorCsrf,
            'name'             => 'Tampered Staff Service',
            'duration_minutes' => '30',
            'staff_ids'        => [self::$staffId, $fakeStaffId],
        ], 'operator');

        $this->assertSame(302, $res['code']);

        // Must not create any service
        $cnt = Database::query('SELECT COUNT(*) as cnt FROM `services` WHERE `tenant_id` = ?', [self::$tenantId]);
        $this->assertSame(0, (int) $cnt[0]['cnt'], 'Unknown staff_id must reject entire submission');

        // Must not create any pivot rows
        $pivots = Database::query(
            'SELECT COUNT(*) as cnt FROM `service_staff` WHERE `service_id` IN (SELECT `id` FROM `services` WHERE `tenant_id` = ?)',
            [self::$tenantId]
        );
        $this->assertSame(0, (int) $pivots[0]['cnt'], 'No pivot rows must exist');
    }

    public function testCreateRejectsCrossTenantStaffId(): void
    {
        // Create a staff member on the non-timeslot tenant
        $otherStaffId = Ulid::generate();
        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`, `sort_order`)
             VALUES (?, ?, 'Cross Tenant Staff', 'cross-svc@test.test', 1, 1)",
            [$otherStaffId, self::$nonTimeslotTenantId]
        );

        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/services/create", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/services", [
            '_csrf_token'      => self::$operatorCsrf,
            'name'             => 'Cross Tenant Staff Service',
            'duration_minutes' => '30',
            'staff_ids'        => [$otherStaffId],
        ], 'operator');

        $this->assertSame(302, $res['code']);

        // Must not create any service
        $cnt = Database::query('SELECT COUNT(*) as cnt FROM `services` WHERE `tenant_id` = ?', [self::$tenantId]);
        $this->assertSame(0, (int) $cnt[0]['cnt'], 'Cross-tenant staff_id must reject entire submission');

        // Cleanup
        Database::execute('DELETE FROM `staff` WHERE `id` = ?', [$otherStaffId]);
    }

    public function testNonTimeslotTenantCannotCreate(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/services/create", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$nonTimeslotTenantId . "/services", [
            '_csrf_token'      => self::$operatorCsrf,
            'name'             => 'Non Timeslot Service',
            'duration_minutes' => '30',
        ], 'operator');

        $this->assertSame(302, $res['code'], 'Non-timeslot must redirect on create');

        $cnt = Database::query('SELECT COUNT(*) as cnt FROM `services` WHERE `tenant_id` = ?', [self::$nonTimeslotTenantId]);
        $this->assertSame(0, (int) $cnt[0]['cnt'], 'Must not create service for non-timeslot tenant');
    }

    public function testCreatePreservesStaffCheckboxesOnValidationRedirect(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/services/create", 'operator');
        self::extractCsrf($page['body'], 'operator');

        // Submit invalid data (empty name) with staff_ids selected
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/services", [
            '_csrf_token'      => self::$operatorCsrf,
            'name'             => '',
            'duration_minutes' => '30',
            'staff_ids'        => [self::$staffId, self::$staffId2],
        ], 'operator');

        $this->assertSame(302, $res['code'], 'Invalid create must redirect');

        // Follow the redirect to the create form
        $form = self::httpGet("/admin/tenants/" . self::$tenantId . "/services/create", 'operator');
        $this->assertSame(200, $form['code']);

        // Assert both staff checkboxes are checked via old input
        $body = $form['body'];
        $staffAChecked = preg_match(
            '/value="' . preg_quote(self::$staffId, '/') . '"\s+checked/',
            $body
        );
        $staffBChecked = preg_match(
            '/value="' . preg_quote(self::$staffId2, '/') . '"\s+checked/',
            $body
        );

        $this->assertSame(1, $staffAChecked, 'Staff A checkbox must be checked after validation redirect');
        $this->assertSame(1, $staffBChecked, 'Staff B checkbox must be checked after validation redirect');
    }

    // ════════════════════════════════════════════════════════════════
    // Edit / Update
    // ════════════════════════════════════════════════════════════════

    public function testEditServiceUpdatesRecord(): void
    {
        // Seed a service
        $svcId = Ulid::generate();
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`, `price`, `sort_order`)
             VALUES (?, ?, 'Original Service', 30, 20.00, 0)",
            [$svcId, self::$tenantId]
        );

        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/services/{$svcId}/edit", 'operator');
        $this->assertSame(200, $page['code'], 'Edit form must load');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/services/{$svcId}", [
            '_csrf_token'      => self::$operatorCsrf,
            'name'             => 'Updated Service',
            'duration_minutes' => '45',
            'price'            => '35.00',
            'price_label'      => 'From €35',
            'category'         => 'Premium',
            'color'            => '#10B981',
            'sort_order'       => '5',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $row = Database::query('SELECT * FROM `services` WHERE `id` = ?', [$svcId]);
        $this->assertSame('Updated Service', $row[0]['name']);
        $this->assertSame(45, (int) $row[0]['duration_minutes']);
        $this->assertSame('35.00', $row[0]['price']);
        $this->assertSame('From €35', $row[0]['price_label']);
        $this->assertSame('Premium', $row[0]['category']);
        $this->assertSame('#10B981', $row[0]['color']);
    }

    public function testEditUpdatesStaffPivot(): void
    {
        $svcId = Ulid::generate();
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`) VALUES (?, ?, 'Pivot Test', 30)",
            [$svcId, self::$tenantId]
        );
        // Link staff 1
        Database::execute('INSERT INTO `service_staff` (`service_id`, `staff_id`) VALUES (?, ?)', [$svcId, self::$staffId]);

        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/services/{$svcId}/edit", 'operator');
        self::extractCsrf($page['body'], 'operator');

        // Update to only staff 2
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/services/{$svcId}", [
            '_csrf_token'      => self::$operatorCsrf,
            'name'             => 'Pivot Test',
            'duration_minutes' => '30',
            'staff_ids'        => [self::$staffId2],
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $pivots = Database::query('SELECT `staff_id` FROM `service_staff` WHERE `service_id` = ?', [$svcId]);
        $this->assertCount(1, $pivots, 'Must have exactly 1 staff link after update');
        $this->assertSame(self::$staffId2, $pivots[0]['staff_id']);
    }

    public function testUpdateRejectsInvalidStaffIds(): void
    {
        // Seed a service with staff A linked
        $svcId = Ulid::generate();
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`, `price`) VALUES (?, ?, 'Stable Service', 30, 20.00)",
            [$svcId, self::$tenantId]
        );
        Database::execute(
            'INSERT INTO `service_staff` (`service_id`, `staff_id`) VALUES (?, ?)',
            [$svcId, self::$staffId]
        );

        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/services/{$svcId}/edit", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $fakeStaffId = Ulid::generate();

        // Attempt update with a tampered staff ID
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/services/{$svcId}", [
            '_csrf_token'      => self::$operatorCsrf,
            'name'             => 'Tampered Update',
            'duration_minutes' => '60',
            'staff_ids'        => [self::$staffId, $fakeStaffId],
        ], 'operator');

        $this->assertSame(302, $res['code']);

        // Service record must remain unchanged
        $row = Database::query('SELECT `name`, `duration_minutes` FROM `services` WHERE `id` = ?', [$svcId]);
        $this->assertSame('Stable Service', $row[0]['name'], 'Service name must not change on invalid staff');
        $this->assertSame(30, (int) $row[0]['duration_minutes'], 'Duration must not change on invalid staff');

        // Pivot must remain unchanged (still only staff A)
        $pivots = Database::query('SELECT `staff_id` FROM `service_staff` WHERE `service_id` = ?', [$svcId]);
        $this->assertCount(1, $pivots, 'Pivot must not change on invalid staff update');
        $this->assertSame(self::$staffId, $pivots[0]['staff_id'], 'Original staff link must be preserved');
    }

    // ════════════════════════════════════════════════════════════════
    // Activate / Deactivate
    // ════════════════════════════════════════════════════════════════

    public function testDeactivateAndActivateService(): void
    {
        $svcId = Ulid::generate();
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`, `is_active`) VALUES (?, ?, 'Toggle Svc', 30, 1)",
            [$svcId, self::$tenantId]
        );

        // Deactivate
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/services", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/services/{$svcId}/deactivate", [
            '_csrf_token' => self::$operatorCsrf,
        ], 'operator');

        $this->assertSame(302, $res['code']);
        $row = Database::query('SELECT `is_active` FROM `services` WHERE `id` = ?', [$svcId]);
        $this->assertSame(0, (int) $row[0]['is_active'], 'Service must be deactivated');

        // Re-activate
        $page2 = self::httpGet("/admin/tenants/" . self::$tenantId . "/services", 'operator');
        self::extractCsrf($page2['body'], 'operator');

        $res2 = self::httpPost("/admin/tenants/" . self::$tenantId . "/services/{$svcId}/activate", [
            '_csrf_token' => self::$operatorCsrf,
        ], 'operator');

        $this->assertSame(302, $res2['code']);
        $row2 = Database::query('SELECT `is_active` FROM `services` WHERE `id` = ?', [$svcId]);
        $this->assertSame(1, (int) $row2[0]['is_active'], 'Service must be re-activated');
    }

    // ════════════════════════════════════════════════════════════════
    // Non-timeslot tenant
    // ════════════════════════════════════════════════════════════════

    public function testNonTimeslotTenantRedirects(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$nonTimeslotTenantId . "/services", 'operator');

        $this->assertSame(302, $res['code'], 'Non-timeslot tenant must redirect');
    }

    // ════════════════════════════════════════════════════════════════
    // Owner / Manager permissions
    // ════════════════════════════════════════════════════════════════

    public function testOwnerCanCreateService(): void
    {
        if (self::$ownerCookie === '') {
            $this->markTestSkipped('Owner login not available');
        }

        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/services/create", 'owner');
        self::extractCsrf($page['body'], 'owner');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/services", [
            '_csrf_token'      => self::$ownerCsrf,
            'name'             => 'Owner Service',
            'duration_minutes' => '15',
        ], 'owner');

        $this->assertSame(302, $res['code']);

        $rows = Database::query(
            "SELECT * FROM `services` WHERE `tenant_id` = ? AND `name` = 'Owner Service'",
            [self::$tenantId]
        );
        $this->assertCount(1, $rows, 'Owner must be able to create services');
    }

    public function testManagerCannotCreateService(): void
    {
        if (self::$managerCookie === '') {
            $this->markTestSkipped('Manager login not available');
        }

        $bookings = self::httpGet("/admin/tenants/" . self::$tenantId . "/bookings", 'manager');
        self::extractCsrf($bookings['body'], 'manager');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/services", [
            '_csrf_token'      => self::$managerCsrf,
            'name'             => 'Manager Service',
            'duration_minutes' => '15',
        ], 'manager');

        $this->assertSame(403, $res['code'], 'Manager must not be able to create services');
    }

    public function testOwnerCanEditService(): void
    {
        if (self::$ownerCookie === '') {
            $this->markTestSkipped('Owner login not available');
        }

        $svcId = Ulid::generate();
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`) VALUES (?, ?, 'Owner Edit Test', 30)",
            [$svcId, self::$tenantId]
        );

        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/services/{$svcId}/edit", 'owner');
        $this->assertSame(200, $page['code']);
        self::extractCsrf($page['body'], 'owner');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/services/{$svcId}", [
            '_csrf_token'      => self::$ownerCsrf,
            'name'             => 'Owner Edited',
            'duration_minutes' => '45',
        ], 'owner');

        $this->assertSame(302, $res['code']);
        $row = Database::query('SELECT `name` FROM `services` WHERE `id` = ?', [$svcId]);
        $this->assertSame('Owner Edited', $row[0]['name']);
    }

    public function testManagerCannotEditService(): void
    {
        if (self::$managerCookie === '') {
            $this->markTestSkipped('Manager login not available');
        }

        $svcId = Ulid::generate();
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`) VALUES (?, ?, 'Mgr Edit Test', 30)",
            [$svcId, self::$tenantId]
        );

        $bookings = self::httpGet("/admin/tenants/" . self::$tenantId . "/bookings", 'manager');
        self::extractCsrf($bookings['body'], 'manager');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/services/{$svcId}", [
            '_csrf_token'      => self::$managerCsrf,
            'name'             => 'Manager Edited',
            'duration_minutes' => '45',
        ], 'manager');

        $this->assertSame(403, $res['code'], 'Manager must not be able to edit services');
    }

    // ════════════════════════════════════════════════════════════════
    // Seed data
    // ════════════════════════════════════════════════════════════════

    private static function seedData(): void
    {
        self::$tenantId = Ulid::generate();
        self::$staffId = Ulid::generate();
        self::$staffId2 = Ulid::generate();
        self::$nonTimeslotTenantId = Ulid::generate();

        Database::execute(
            "INSERT INTO `tenants`
             (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`, `brand_color`, `timezone`, `currency`)
             VALUES (?, ?, 'Svc Test Tenant', 'svctest@test.test', 'timeslot', 'active', '#6366F1', 'UTC', 'EUR')",
            [self::$tenantId, 'test-svc-' . substr(self::$tenantId, -8)]
        );

        Database::execute(
            "INSERT INTO `tenants`
             (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`, `brand_color`, `timezone`, `currency`)
             VALUES (?, ?, 'Svc Cap Tenant', 'svccap@test.test', 'capacity', 'active', '#10B981', 'UTC', 'EUR')",
            [self::$nonTimeslotTenantId, 'test-svccap-' . substr(self::$nonTimeslotTenantId, -8)]
        );

        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`, `sort_order`)
             VALUES (?, ?, 'Svc Staff A', 'svc-staffa@test.test', 1, 1)",
            [self::$staffId, self::$tenantId]
        );

        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`, `sort_order`)
             VALUES (?, ?, 'Svc Staff B', 'svc-staffb@test.test', 1, 2)",
            [self::$staffId2, self::$tenantId]
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Auth helpers
    // ════════════════════════════════════════════════════════════════

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
        $email = 'test-svc-owner-' . bin2hex(random_bytes(4)) . '@test.test';
        $password = 'OwnerTest123!';
        self::$ownerId = Ulid::generate();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::execute(
            "INSERT INTO `business_users` (`id`, `tenant_id`, `name`, `email`, `password_hash`, `role`, `is_active`, `force_password_change`)
             VALUES (?, ?, 'Svc Test Owner', ?, ?, 'owner', 1, 0)",
            [self::$ownerId, self::$tenantId, $email, $hash]
        );

        $loginPage = self::httpGet('/admin/login', 'owner');
        self::extractCsrf($loginPage['body'], 'owner');

        $res = self::httpPost('/admin/login', [
            '_csrf_token' => self::$ownerCsrf,
            'email'       => $email,
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
        $email = 'test-svc-mgr-' . bin2hex(random_bytes(4)) . '@test.test';
        $password = 'MgrTest123!';
        self::$managerId = Ulid::generate();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::execute(
            "INSERT INTO `business_users` (`id`, `tenant_id`, `name`, `email`, `password_hash`, `role`, `is_active`, `force_password_change`)
             VALUES (?, ?, 'Svc Test Manager', ?, ?, 'manager', 1, 0)",
            [self::$managerId, self::$tenantId, $email, $hash]
        );

        $loginPage = self::httpGet('/admin/login', 'manager');
        self::extractCsrf($loginPage['body'], 'manager');

        $res = self::httpPost('/admin/login', [
            '_csrf_token' => self::$managerCsrf,
            'email'       => $email,
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
    // HTTP
    // ════════════════════════════════════════════════════════════════

    /** @return array{code: int, headers: string, body: string} */
    private static function httpGet(string $path, string $role = 'operator'): array
    {
        return self::http('GET', $path, [], $role);
    }

    /** @return array{code: int, headers: string, body: string} */
    private static function httpPost(string $path, array $data, string $role = 'operator'): array
    {
        return self::http('POST', $path, $data, $role);
    }

    /** @return array{code: int, headers: string, body: string} */
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
