<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for tenant staff management.
 *
 * Covers:
 * - GET  /admin/tenants/{tenant_id}/staff           → list loads for operator/owner, 403 for manager
 * - GET  /admin/tenants/{tenant_id}/staff/create    → form loads for operator/owner
 * - POST /admin/tenants/{tenant_id}/staff/create    → creates staff + service pivot + audit log
 * - POST /admin/tenants/{tenant_id}/staff/{id}/edit → updates staff + pivot sync
 * - POST /admin/tenants/{tenant_id}/staff/{id}/activate   → flips is_active to 1
 * - POST /admin/tenants/{tenant_id}/staff/{id}/deactivate → flips is_active to 0
 * - Email uniqueness scoped to tenant
 * - Service pivot sync (delete + re-insert)
 * - Sidebar nav visibility by role
 *
 * Uses real HTTP against the running app at APP_TEST_URL.
 */
final class StaffManagementTest extends TestCase
{
    private static string $baseUrl;
    private static bool $appReachable = false;
    private static bool $dbReady = false;
    private static string $setupError = '';

    // Seed data
    private static string $tenantId = '';
    private static string $serviceId1 = '';
    private static string $serviceId2 = '';

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
            self::seedStaffData();
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
            Database::execute('DELETE FROM `service_staff` WHERE `service_id` IN (?, ?)', [self::$serviceId1, self::$serviceId2]);
            Database::execute('DELETE FROM `staff` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `services` WHERE `tenant_id` = ?', [self::$tenantId]);
            if (self::$ownerId !== '') {
                Database::execute('DELETE FROM `business_users` WHERE `id` = ?', [self::$ownerId]);
            }
            if (self::$managerId !== '') {
                Database::execute('DELETE FROM `business_users` WHERE `id` = ?', [self::$managerId]);
            }
            Database::execute('DELETE FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        } catch (\Throwable) {
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Index access by role
    // ════════════════════════════════════════════════════════════════

    public function testIndexLoadsForOperator(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff", 'operator');

        $this->assertSame(200, $res['code'], 'Staff index must return 200 for operator');
        $this->assertTrue(
            str_contains($res['body'], 'staff-table') || str_contains($res['body'], 'vb-empty-state'),
            'Must contain staff table or empty state'
        );
    }

    public function testIndexLoadsForOwner(): void
    {
        if (self::$ownerCookie === '') {
            $this->markTestSkipped('Owner login not available');
        }

        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff", 'owner');

        $this->assertSame(200, $res['code'], 'Staff index must return 200 for owner');
    }

    public function testIndexForbiddenForManager(): void
    {
        if (self::$managerCookie === '') {
            $this->markTestSkipped('Manager login not available');
        }

        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff", 'manager');

        $this->assertSame(403, $res['code'], 'Staff index must return 403 for manager');
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Sidebar nav visibility
    // ════════════════════════════════════════════════════════════════

    public function testSidebarShowsStaffForOperator(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff", 'operator');

        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('/staff', $res['body'], 'Sidebar must show Staff link for operator');
    }

    public function testSidebarHidesStaffForManager(): void
    {
        if (self::$managerCookie === '') {
            $this->markTestSkipped('Manager login not available');
        }

        // Manager can access bookings, so use that page to check sidebar
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/bookings", 'manager');

        // Manager should NOT see the Staff link in the sidebar
        // We already confirmed the Staff nav has canManage gate. On a page the
        // manager CAN access, the sidebar should not contain /staff
        if ($res['code'] === 200) {
            $this->assertStringNotContainsString('/staff"', $res['body'], 'Sidebar must NOT show Staff link for manager');
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Create
    // ════════════════════════════════════════════════════════════════

    public function testCreateFormLoads(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff/create", 'operator');

        $this->assertSame(200, $res['code'], 'Create form must return 200');
        $this->assertStringContainsString('staff_name', $res['body'], 'Form must contain name input');
        $this->assertStringContainsString('staff_email', $res['body'], 'Form must contain email input');
    }

    public function testCreateStaffWithServices(): void
    {
        // Get CSRF from create page
        $createPage = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff/create", 'operator');
        self::extractCsrf($createPage['body'], 'operator');

        $uniqueEmail = 'test-staff-' . bin2hex(random_bytes(4)) . '@test.test';

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/staff/create", [
            '_csrf_token'  => self::$operatorCsrf,
            'name'         => 'Integration Test Staff',
            'email'        => $uniqueEmail,
            'phone'        => '+31612345678',
            'title'        => 'Test Stylist',
            'bio'          => 'Test bio text',
            'service_ids'  => [self::$serviceId1, self::$serviceId2],
        ], 'operator');

        $this->assertSame(302, $res['code'], 'Successful create must redirect. Body: ' . $res['body']);
        $this->assertStringContainsString('/staff', $res['headers'], 'Must redirect to staff index');

        // Verify DB record
        $staff = Database::query(
            'SELECT * FROM `staff` WHERE `tenant_id` = ? AND `email` = ?',
            [self::$tenantId, $uniqueEmail]
        );
        $this->assertNotEmpty($staff, 'Staff record must exist in DB');
        $s = $staff[0];
        $this->assertSame('Integration Test Staff', $s['name']);
        $this->assertSame('Test Stylist', $s['title']);
        $this->assertSame('+31612345678', $s['phone']);
        $this->assertGreaterThanOrEqual(0, (int) $s['sort_order'], 'sort_order must be auto-assigned');
        $this->assertSame(1, (int) $s['is_active']);

        // Verify service pivot
        $pivots = Database::query(
            'SELECT `service_id` FROM `service_staff` WHERE `staff_id` = ? ORDER BY `service_id`',
            [$s['id']]
        );
        $this->assertCount(2, $pivots, 'Both services must be linked');
        $linkedIds = array_column($pivots, 'service_id');
        $this->assertContains(self::$serviceId1, $linkedIds);
        $this->assertContains(self::$serviceId2, $linkedIds);

        // Verify audit log
        $audit = Database::query(
            "SELECT * FROM `audit_log` WHERE `entity_type` = 'staff' AND `entity_id` = ? AND `action` = 'staff.created'",
            [$s['id']]
        );
        $this->assertNotEmpty($audit, 'Audit log entry for staff.created must exist');

        // Cleanup
        Database::execute('DELETE FROM `service_staff` WHERE `staff_id` = ?', [$s['id']]);
        Database::execute('DELETE FROM `staff` WHERE `id` = ?', [$s['id']]);
    }

    public function testCreateRejectsEmptyName(): void
    {
        $createPage = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff/create", 'operator');
        self::extractCsrf($createPage['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/staff/create", [
            '_csrf_token' => self::$operatorCsrf,
            'name'        => '',
            'email'       => 'valid@test.test',
        ], 'operator');

        $this->assertSame(302, $res['code'], 'Validation failure must redirect back');
        $this->assertStringContainsString('/create', $res['headers'], 'Must redirect to create form');
    }

    public function testCreateRejectsDuplicateEmail(): void
    {
        $email = 'dup-test-' . bin2hex(random_bytes(4)) . '@test.test';

        // Seed a staff member with this email
        $firstId = Ulid::generate();
        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`) VALUES (?, ?, 'First', ?, 1)",
            [$firstId, self::$tenantId, $email]
        );

        // Try to create a second with the same email
        $createPage = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff/create", 'operator');
        self::extractCsrf($createPage['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/staff/create", [
            '_csrf_token' => self::$operatorCsrf,
            'name'        => 'Duplicate',
            'email'       => $email,
        ], 'operator');

        $this->assertSame(302, $res['code'], 'Duplicate email must redirect back');
        $this->assertStringContainsString('/create', $res['headers']);

        // Cleanup
        Database::execute('DELETE FROM `staff` WHERE `id` = ?', [$firstId]);
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Edit
    // ════════════════════════════════════════════════════════════════

    public function testEditStaffUpdatesRecord(): void
    {
        // Create a staff member to edit
        $staffId = Ulid::generate();
        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `title`, `is_active`, `sort_order`)
             VALUES (?, ?, 'Before Edit', 'edit-test@test.test', 'Junior', 1, 1)",
            [$staffId, self::$tenantId]
        );
        Database::execute(
            'INSERT INTO `service_staff` (`service_id`, `staff_id`) VALUES (?, ?)',
            [self::$serviceId1, $staffId]
        );

        // Get edit form + CSRF
        $editPage = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff/{$staffId}/edit", 'operator');
        $this->assertSame(200, $editPage['code'], 'Edit form must return 200');
        self::extractCsrf($editPage['body'], 'operator');

        // Submit update: change name, title, and switch from service1 to service2
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/staff/{$staffId}/edit", [
            '_csrf_token' => self::$operatorCsrf,
            'name'        => 'After Edit',
            'email'       => 'edit-test@test.test',
            'title'       => 'Senior',
            'service_ids' => [self::$serviceId2],
        ], 'operator');

        $this->assertSame(302, $res['code'], 'Successful edit must redirect');

        // Verify changes
        $updated = Database::query('SELECT * FROM `staff` WHERE `id` = ?', [$staffId]);
        $this->assertNotEmpty($updated);
        $this->assertSame('After Edit', $updated[0]['name']);
        $this->assertSame('Senior', $updated[0]['title']);
        $this->assertSame(1, (int) $updated[0]['sort_order'], 'sort_order must be preserved (managed by chevrons, not form)');

        // Verify pivot was synced (only service2 now)
        $pivots = Database::query(
            'SELECT `service_id` FROM `service_staff` WHERE `staff_id` = ?',
            [$staffId]
        );
        $this->assertCount(1, $pivots, 'Only one service should remain after edit');
        $this->assertSame(self::$serviceId2, $pivots[0]['service_id']);

        // Verify audit log
        $audit = Database::query(
            "SELECT * FROM `audit_log` WHERE `entity_type` = 'staff' AND `entity_id` = ? AND `action` = 'staff.updated'",
            [$staffId]
        );
        $this->assertNotEmpty($audit, 'Audit log entry for staff.updated must exist');

        // Cleanup
        Database::execute('DELETE FROM `service_staff` WHERE `staff_id` = ?', [$staffId]);
        Database::execute('DELETE FROM `staff` WHERE `id` = ?', [$staffId]);
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Activate / Deactivate
    // ════════════════════════════════════════════════════════════════

    public function testDeactivateAndActivateStaff(): void
    {
        // Create active staff
        $staffId = Ulid::generate();
        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`)
             VALUES (?, ?, 'Toggle Test', 'toggle@test.test', 1)",
            [$staffId, self::$tenantId]
        );

        // Get CSRF from staff index
        $indexPage = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff", 'operator');
        self::extractCsrf($indexPage['body'], 'operator');

        // Deactivate
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/staff/{$staffId}/deactivate", [
            '_csrf_token' => self::$operatorCsrf,
        ], 'operator');

        $this->assertSame(302, $res['code'], 'Deactivate must redirect');

        $row = Database::query('SELECT `is_active` FROM `staff` WHERE `id` = ?', [$staffId]);
        $this->assertSame(0, (int) $row[0]['is_active'], 'Staff must be inactive after deactivate');

        // Verify audit
        $audit = Database::query(
            "SELECT * FROM `audit_log` WHERE `entity_id` = ? AND `action` = 'staff.deactivated'",
            [$staffId]
        );
        $this->assertNotEmpty($audit, 'Audit log for staff.deactivated must exist');

        // Get fresh CSRF
        $indexPage2 = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff", 'operator');
        self::extractCsrf($indexPage2['body'], 'operator');

        // Activate
        $res2 = self::httpPost("/admin/tenants/" . self::$tenantId . "/staff/{$staffId}/activate", [
            '_csrf_token' => self::$operatorCsrf,
        ], 'operator');

        $this->assertSame(302, $res2['code'], 'Activate must redirect');

        $row2 = Database::query('SELECT `is_active` FROM `staff` WHERE `id` = ?', [$staffId]);
        $this->assertSame(1, (int) $row2[0]['is_active'], 'Staff must be active after activate');

        // Verify audit
        $audit2 = Database::query(
            "SELECT * FROM `audit_log` WHERE `entity_id` = ? AND `action` = 'staff.activated'",
            [$staffId]
        );
        $this->assertNotEmpty($audit2, 'Audit log for staff.activated must exist');

        // Cleanup
        Database::execute('DELETE FROM `staff` WHERE `id` = ?', [$staffId]);
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Owner access
    // ════════════════════════════════════════════════════════════════

    public function testOwnerCanCreateStaff(): void
    {
        if (self::$ownerCookie === '') {
            $this->markTestSkipped('Owner login not available');
        }

        $createPage = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff/create", 'owner');
        $this->assertSame(200, $createPage['code'], 'Owner must access create form');
        self::extractCsrf($createPage['body'], 'owner');

        $uniqueEmail = 'owner-staff-' . bin2hex(random_bytes(4)) . '@test.test';

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/staff/create", [
            '_csrf_token' => self::$ownerCsrf,
            'name'        => 'Owner Created Staff',
            'email'       => $uniqueEmail,
        ], 'owner');

        $this->assertSame(302, $res['code'], 'Owner must be able to create staff');

        // Verify
        $staff = Database::query(
            'SELECT * FROM `staff` WHERE `tenant_id` = ? AND `email` = ?',
            [self::$tenantId, $uniqueEmail]
        );
        $this->assertNotEmpty($staff, 'Staff created by owner must exist');

        // Cleanup
        Database::execute('DELETE FROM `staff` WHERE `id` = ?', [$staff[0]['id']]);
    }

    public function testOwnerSeesStaffNavInSidebar(): void
    {
        if (self::$ownerCookie === '') {
            $this->markTestSkipped('Owner login not available');
        }

        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff", 'owner');

        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('/staff', $res['body'], 'Sidebar must show Staff link for owner');
    }

    public function testOwnerCanEditStaff(): void
    {
        if (self::$ownerCookie === '') {
            $this->markTestSkipped('Owner login not available');
        }

        $staffId = Ulid::generate();
        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`)
             VALUES (?, ?, 'Owner Edit Test', 'owner-edit@test.test', 1)",
            [$staffId, self::$tenantId]
        );

        // Get edit form
        $editPage = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff/{$staffId}/edit", 'owner');
        $this->assertSame(200, $editPage['code'], 'Owner must access edit form');
        self::extractCsrf($editPage['body'], 'owner');

        // Submit update
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/staff/{$staffId}/edit", [
            '_csrf_token' => self::$ownerCsrf,
            'name'        => 'Owner Edited Name',
            'email'       => 'owner-edit@test.test',
        ], 'owner');

        $this->assertSame(302, $res['code'], 'Owner must be able to edit staff');

        // Verify
        $updated = Database::query('SELECT `name` FROM `staff` WHERE `id` = ?', [$staffId]);
        $this->assertSame('Owner Edited Name', $updated[0]['name']);

        // Cleanup
        Database::execute('DELETE FROM `staff` WHERE `id` = ?', [$staffId]);
    }

    public function testOwnerCanDeactivateAndActivateStaff(): void
    {
        if (self::$ownerCookie === '') {
            $this->markTestSkipped('Owner login not available');
        }

        $staffId = Ulid::generate();
        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`)
             VALUES (?, ?, 'Owner Toggle Test', 'owner-toggle@test.test', 1)",
            [$staffId, self::$tenantId]
        );

        // Deactivate
        $indexPage = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff", 'owner');
        self::extractCsrf($indexPage['body'], 'owner');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/staff/{$staffId}/deactivate", [
            '_csrf_token' => self::$ownerCsrf,
        ], 'owner');

        $this->assertSame(302, $res['code'], 'Owner must be able to deactivate staff');

        $row = Database::query('SELECT `is_active` FROM `staff` WHERE `id` = ?', [$staffId]);
        $this->assertSame(0, (int) $row[0]['is_active'], 'Staff must be inactive after owner deactivate');

        // Activate
        $indexPage2 = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff", 'owner');
        self::extractCsrf($indexPage2['body'], 'owner');

        $res2 = self::httpPost("/admin/tenants/" . self::$tenantId . "/staff/{$staffId}/activate", [
            '_csrf_token' => self::$ownerCsrf,
        ], 'owner');

        $this->assertSame(302, $res2['code'], 'Owner must be able to activate staff');

        $row2 = Database::query('SELECT `is_active` FROM `staff` WHERE `id` = ?', [$staffId]);
        $this->assertSame(1, (int) $row2[0]['is_active'], 'Staff must be active after owner activate');

        // Cleanup
        Database::execute('DELETE FROM `staff` WHERE `id` = ?', [$staffId]);
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Manager forbidden
    // ════════════════════════════════════════════════════════════════

    public function testManagerCannotCreateStaff(): void
    {
        if (self::$managerCookie === '') {
            $this->markTestSkipped('Manager login not available');
        }

        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff/create", 'manager');
        $this->assertSame(403, $res['code'], 'Manager must not access create form');
    }

    public function testManagerCannotEditStaff(): void
    {
        if (self::$managerCookie === '') {
            $this->markTestSkipped('Manager login not available');
        }

        // Create a staff member to try to edit
        $staffId = Ulid::generate();
        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`)
             VALUES (?, ?, 'Manager Test', 'mgr-test@test.test', 1)",
            [$staffId, self::$tenantId]
        );

        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff/{$staffId}/edit", 'manager');
        $this->assertSame(403, $res['code'], 'Manager must not access edit form');

        // Cleanup
        Database::execute('DELETE FROM `staff` WHERE `id` = ?', [$staffId]);
    }

    public function testManagerCannotDeactivateStaff(): void
    {
        if (self::$managerCookie === '') {
            $this->markTestSkipped('Manager login not available');
        }

        $staffId = Ulid::generate();
        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`)
             VALUES (?, ?, 'Manager Deact Test', 'mgr-deact@test.test', 1)",
            [$staffId, self::$tenantId]
        );

        // Get CSRF from bookings page (which manager CAN access)
        $bookingsPage = self::httpGet("/admin/tenants/" . self::$tenantId . "/bookings", 'manager');
        self::extractCsrf($bookingsPage['body'], 'manager');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/staff/{$staffId}/deactivate", [
            '_csrf_token' => self::$managerCsrf,
        ], 'manager');

        $this->assertSame(403, $res['code'], 'Manager must not be able to deactivate staff');

        // Verify not deactivated
        $row = Database::query('SELECT `is_active` FROM `staff` WHERE `id` = ?', [$staffId]);
        $this->assertSame(1, (int) $row[0]['is_active'], 'Staff must remain active');

        // Cleanup
        Database::execute('DELETE FROM `staff` WHERE `id` = ?', [$staffId]);
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Service pivot sync on edit
    // ════════════════════════════════════════════════════════════════

    public function testServicePivotClearedWhenNoServicesSelected(): void
    {
        $staffId = Ulid::generate();
        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`)
             VALUES (?, ?, 'Pivot Clear Test', 'pivot-clear@test.test', 1)",
            [$staffId, self::$tenantId]
        );
        Database::execute(
            'INSERT INTO `service_staff` (`service_id`, `staff_id`) VALUES (?, ?)',
            [self::$serviceId1, $staffId]
        );

        // Edit with NO service_ids
        $editPage = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff/{$staffId}/edit", 'operator');
        self::extractCsrf($editPage['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/staff/{$staffId}/edit", [
            '_csrf_token' => self::$operatorCsrf,
            'name'        => 'Pivot Clear Test',
            'email'       => 'pivot-clear@test.test',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        // Verify pivot is empty
        $pivots = Database::query(
            'SELECT * FROM `service_staff` WHERE `staff_id` = ?',
            [$staffId]
        );
        $this->assertCount(0, $pivots, 'All pivots must be cleared when no services selected');

        // Cleanup
        Database::execute('DELETE FROM `staff` WHERE `id` = ?', [$staffId]);
    }

    // ════════════════════════════════════════════════════════════════
    // Seed data
    // ════════════════════════════════════════════════════════════════

    private static function seedStaffData(): void
    {
        self::$tenantId = Ulid::generate();
        self::$serviceId1 = Ulid::generate();
        self::$serviceId2 = Ulid::generate();

        // Tenant
        Database::execute(
            "INSERT INTO `tenants`
             (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`, `brand_color`, `timezone`, `currency`)
             VALUES (?, ?, 'Staff Test Tenant', 'stafftest@test.test', 'timeslot', 'active', '#6366F1', 'UTC', 'EUR')",
            [self::$tenantId, 'test-staff-' . substr(self::$tenantId, -8)]
        );

        // Services
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`, `is_active`, `sort_order`)
             VALUES (?, ?, 'Haircut', 30, 1, 1)",
            [self::$serviceId1, self::$tenantId]
        );
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`, `is_active`, `sort_order`)
             VALUES (?, ?, 'Color', 60, 1, 2)",
            [self::$serviceId2, self::$tenantId]
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
        self::$ownerEmail = 'test-staff-owner-' . bin2hex(random_bytes(4)) . '@test.test';
        $password = 'OwnerTest123!';
        self::$ownerId = Ulid::generate();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::execute(
            "INSERT INTO `business_users` (`id`, `tenant_id`, `name`, `email`, `password_hash`, `role`, `is_active`, `force_password_change`)
             VALUES (?, ?, 'Staff Test Owner', ?, ?, 'owner', 1, 0)",
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
        self::$managerEmail = 'test-staff-mgr-' . bin2hex(random_bytes(4)) . '@test.test';
        $password = 'MgrTest123!';
        self::$managerId = Ulid::generate();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::execute(
            "INSERT INTO `business_users` (`id`, `tenant_id`, `name`, `email`, `password_hash`, `role`, `is_active`, `force_password_change`)
             VALUES (?, ?, 'Staff Test Manager', ?, ?, 'manager', 1, 0)",
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

        // Update session cookie for this role
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
