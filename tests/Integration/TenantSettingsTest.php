<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for tenant settings.
 *
 * Covers 5 tabs: General, Branding, Booking Rules (timeslot + resource), Privacy, Notifications.
 *
 * - Tab access: operator on all tabs, owner on all tabs, manager 403 on GET + POST
 * - Save: general, branding, booking (timeslot + resource), privacy, notifications
 * - Validation: empty name, invalid email, invalid notif email
 * - Old-input: general/notifications validation failure preserves user input
 * - Audit: explicit save-then-check, not inherited from prior test
 * - Sidebar: settings visible for operator, hidden for manager
 * - Slug: read-only enforcement
 * - Pattern gate: booking rules accessible for timeslot + resource, forbidden for capacity/event
 */
final class TenantSettingsTest extends TestCase
{
    private static string $baseUrl;
    private static bool $appReachable = false;
    private static bool $dbReady = false;
    private static string $setupError = '';

    private static string $tenantId = '';
    private static string $resourceTenantId = '';
    private static string $capacityTenantId = '';
    private static string $eventTenantId = '';
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
        } catch (\Throwable) {}

        try {
            TestFixtures::provision();
            self::seedData();
            self::loginOperator();
            self::createAndLoginOwner();
            self::createAndLoginManager();
        } catch (\Throwable $e) {
            self::$setupError = 'Provisioning failed: ' . $e->getMessage();
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

        // Flush rate-limit hits before each test to prevent 429 cascades.
        // The admin group allows 120 req/min and this test class makes ~97
        // HTTP calls total — previous test classes and early test methods
        // in this class accumulate hits that push later methods over the limit.
        try {
            Database::execute('TRUNCATE TABLE `rate_limits`');
        } catch (\Throwable) {}
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$tenantId === '') return;
        try {
            if (self::$ownerId !== '') Database::execute('DELETE FROM `business_users` WHERE `id` = ?', [self::$ownerId]);
            if (self::$managerId !== '') Database::execute('DELETE FROM `business_users` WHERE `id` = ?', [self::$managerId]);
            Database::execute('DELETE FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
            if (self::$resourceTenantId !== '') Database::execute('DELETE FROM `tenants` WHERE `id` = ?', [self::$resourceTenantId]);
            if (self::$capacityTenantId !== '') Database::execute('DELETE FROM `tenants` WHERE `id` = ?', [self::$capacityTenantId]);
            if (self::$eventTenantId !== '') Database::execute('DELETE FROM `tenants` WHERE `id` = ?', [self::$eventTenantId]);
        } catch (\Throwable) {}
    }

    // ════════════════════════════════════════════════════════════════
    // Tab access — operator (all 4 tabs)
    // ════════════════════════════════════════════════════════════════

    public function testGeneralTabLoadsForOperator(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings", 'operator');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('ts-name', $res['body']);
    }

    public function testBrandingTabLoadsForOperator(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/branding", 'operator');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('ts-brand-color', $res['body']);
    }

    public function testPrivacyTabLoadsForOperator(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/privacy", 'operator');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('ts-privacy-url', $res['body']);
    }

    public function testNotificationsTabLoadsForOperator(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/notifications", 'operator');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('ts-notif-email', $res['body']);
    }

    // ════════════════════════════════════════════════════════════════
    // Tab access — owner (all 4 tabs)
    // ════════════════════════════════════════════════════════════════

    public function testGeneralTabLoadsForOwner(): void
    {
        if (self::$ownerCookie === '') $this->markTestSkipped('Owner login not available');
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings", 'owner');
        $this->assertSame(200, $res['code']);
    }

    public function testBrandingTabLoadsForOwner(): void
    {
        if (self::$ownerCookie === '') $this->markTestSkipped('Owner login not available');
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/branding", 'owner');
        $this->assertSame(200, $res['code']);
    }

    public function testPrivacyTabLoadsForOwner(): void
    {
        if (self::$ownerCookie === '') $this->markTestSkipped('Owner login not available');
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/privacy", 'owner');
        $this->assertSame(200, $res['code']);
    }

    public function testNotificationsTabLoadsForOwner(): void
    {
        if (self::$ownerCookie === '') $this->markTestSkipped('Owner login not available');
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/notifications", 'owner');
        $this->assertSame(200, $res['code']);
    }

    // ════════════════════════════════════════════════════════════════
    // Tab access — manager (forbidden GET + POST)
    // ════════════════════════════════════════════════════════════════

    public function testManagerForbiddenOnGeneralGet(): void
    {
        if (self::$managerCookie === '') $this->markTestSkipped('Manager login not available');
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings", 'manager');
        $this->assertSame(403, $res['code']);
    }

    public function testManagerForbiddenOnGeneralPost(): void
    {
        if (self::$managerCookie === '') $this->markTestSkipped('Manager login not available');

        $bookings = self::httpGet("/admin/tenants/" . self::$tenantId . "/bookings", 'manager');
        self::extractCsrf($bookings['body'], 'manager');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings", [
            '_csrf_token' => self::$managerCsrf,
            'name'        => 'Hacked Name',
            'email'       => 'hacked@test.test',
        ], 'manager');
        $this->assertSame(403, $res['code']);

        // Verify no write
        $row = Database::query('SELECT `name` FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        $this->assertSame('Settings Test Tenant', $row[0]['name']);
    }

    // ════════════════════════════════════════════════════════════════
    // Save general
    // ════════════════════════════════════════════════════════════════

    public function testSaveGeneralUpdatesFields(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings", [
            '_csrf_token' => self::$operatorCsrf,
            'name'        => 'Updated Settings Name',
            'email'       => 'updated-settings@test.test',
            'timezone'    => 'Europe/Amsterdam',
            'locale'      => 'nl',
            'currency'    => 'USD',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $row = Database::query('SELECT `name`, `email`, `timezone`, `locale`, `currency` FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        $this->assertSame('Updated Settings Name', $row[0]['name']);
        $this->assertSame('updated-settings@test.test', $row[0]['email']);
        $this->assertSame('Europe/Amsterdam', $row[0]['timezone']);
        $this->assertSame('nl', $row[0]['locale']);
        $this->assertSame('USD', $row[0]['currency']);

        // Restore
        Database::execute('UPDATE `tenants` SET `name` = ?, `email` = ?, `timezone` = ?, `locale` = ?, `currency` = ? WHERE `id` = ?', [
            'Settings Test Tenant', 'settings-test@test.test', 'UTC', 'en', 'EUR', self::$tenantId
        ]);
    }

    public function testSaveGeneralRejectsEmptyName(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings", [
            '_csrf_token' => self::$operatorCsrf,
            'name'        => '',
            'email'       => 'settings-test@test.test',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $row = Database::query('SELECT `name` FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        $this->assertSame('Settings Test Tenant', $row[0]['name']);
    }

    public function testSaveGeneralRejectsInvalidEmail(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings", [
            '_csrf_token' => self::$operatorCsrf,
            'name'        => 'Settings Test Tenant',
            'email'       => 'not-an-email',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $row = Database::query('SELECT `email` FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        $this->assertSame('settings-test@test.test', $row[0]['email']);
    }

    // ════════════════════════════════════════════════════════════════
    // Old-input preservation on validation failure
    // ════════════════════════════════════════════════════════════════

    public function testGeneralOldInputPreservedOnValidationFailure(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings", 'operator');
        self::extractCsrf($page['body'], 'operator');

        // Submit invalid (empty name) but with custom phone/timezone
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings", [
            '_csrf_token' => self::$operatorCsrf,
            'name'        => '',
            'email'       => 'valid@test.test',
            'phone'       => '+31612345678',
            'timezone'    => 'Europe/Berlin',
            'locale'      => 'de',
            'currency'    => 'GBP',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        // Follow redirect — form must show the old input, not the persisted values
        $form = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings", 'operator');
        $this->assertSame(200, $form['code']);

        $body = $form['body'];
        $this->assertStringContainsString('+31612345678', $body, 'Phone old input must be preserved');
        $this->assertStringContainsString('Europe/Berlin', $body, 'Timezone old input must be preserved');
        $this->assertStringContainsString('value="de"', $body, 'Locale old input must be preserved');
        $this->assertStringContainsString('value="GBP"', $body, 'Currency old input must be preserved');
    }

    public function testNotificationsOldInputPreservedOnValidationFailure(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/notifications", 'operator');
        self::extractCsrf($page['body'], 'operator');

        // Submit invalid email
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings/notifications", [
            '_csrf_token'            => self::$operatorCsrf,
            'notification_email'     => 'not-valid',
            'notify_on_booking'      => '1',
            'notify_on_cancellation' => '0',
            'send_reminders'         => '1',
            'reminder_hours_before'  => '8',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        // Follow redirect
        $form = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/notifications", 'operator');
        $this->assertSame(200, $form['code']);

        $body = $form['body'];
        $this->assertStringContainsString('not-valid', $body, 'Invalid email must be preserved in form');
        $this->assertStringContainsString('value="8"', $body, 'Reminder hours old input must be preserved');
    }

    // ════════════════════════════════════════════════════════════════
    // Save branding
    // ════════════════════════════════════════════════════════════════

    public function testSaveBrandingUpdatesFields(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/branding", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings/branding", [
            '_csrf_token'              => self::$operatorCsrf,
            'brand_color'              => '#10B981',
            'booking_page_heading'     => 'Welcome!',
            'booking_page_description' => 'Book your appointment.',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $row = Database::query('SELECT `brand_color`, `brand_color_text`, `booking_page_heading`, `booking_page_description` FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        $this->assertSame('#10B981', $row[0]['brand_color']);
        // White fails AA on this green; the derived dark foreground passes.
        $this->assertSame('#111827', $row[0]['brand_color_text']);
        $this->assertSame('Welcome!', $row[0]['booking_page_heading']);
        $this->assertSame('Book your appointment.', $row[0]['booking_page_description']);
    }

    public function testSaveBrandingDisablesPoweredBy(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/branding", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings/branding", [
            '_csrf_token'      => self::$operatorCsrf,
            'brand_color'      => '#2563EB',
            'show_powered_by'  => '0',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $row = Database::query('SELECT `show_powered_by` FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        $this->assertSame(0, (int) $row[0]['show_powered_by'], 'show_powered_by must be 0 after disabling');
    }

    public function testBookingPageHidesFooterWhenPoweredByDisabled(): void
    {
        // Ensure powered_by is off
        Database::execute('UPDATE `tenants` SET `show_powered_by` = 0 WHERE `id` = ?', [self::$tenantId]);

        $slug = Database::query('SELECT `slug` FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        $res = self::httpGetPublic("/book/" . $slug[0]['slug']);

        $this->assertSame(200, $res['code']);
        $this->assertStringNotContainsString('vb-book-footer', $res['body'], 'Footer must not render when show_powered_by=0');
    }

    public function testBookingPageShowsFooterWhenPoweredByEnabled(): void
    {
        // Ensure powered_by is on
        Database::execute('UPDATE `tenants` SET `show_powered_by` = 1 WHERE `id` = ?', [self::$tenantId]);

        $slug = Database::query('SELECT `slug` FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        $res = self::httpGetPublic("/book/" . $slug[0]['slug']);

        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('vb-book-footer', $res['body'], 'Footer must render when show_powered_by=1');
    }

    public function testBrandingFormShowsPoweredByToggle(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/branding", 'operator');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('ts-show-powered-by', $res['body'], 'Powered-by toggle must appear on branding tab');
    }

    // ════════════════════════════════════════════════════════════════
    // Save privacy
    // ════════════════════════════════════════════════════════════════

    public function testSavePrivacyUpdatesFields(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/privacy", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings/privacy", [
            '_csrf_token'        => self::$operatorCsrf,
            'requires_consent'   => '1',
            'privacy_policy_url' => 'https://example.com/privacy',
            'consent_text'       => 'I agree to the terms.',
            'data_retention_months' => '12',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $row = Database::query('SELECT `requires_consent`, `privacy_policy_url`, `consent_text`, `data_retention_months` FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        $this->assertSame(1, (int) $row[0]['requires_consent']);
        $this->assertSame('https://example.com/privacy', $row[0]['privacy_policy_url']);
        $this->assertSame('I agree to the terms.', $row[0]['consent_text']);
        $this->assertSame(12, (int) $row[0]['data_retention_months']);
    }

    // ════════════════════════════════════════════════════════════════
    // Save notifications
    // ════════════════════════════════════════════════════════════════

    public function testSaveNotificationsUpdatesFields(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/notifications", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings/notifications", [
            '_csrf_token'            => self::$operatorCsrf,
            'notification_email'     => 'alerts@test.test',
            'notify_on_booking'      => '1',
            'notify_on_cancellation' => '0',
            'send_reminders'         => '1',
            'reminder_hours_before'  => '4',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $row = Database::query('SELECT `notification_email`, `notify_on_booking`, `notify_on_cancellation`, `send_reminders`, `reminder_hours_before` FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        $this->assertSame('alerts@test.test', $row[0]['notification_email']);
        $this->assertSame(1, (int) $row[0]['notify_on_booking']);
        $this->assertSame(0, (int) $row[0]['notify_on_cancellation']);
        $this->assertSame(1, (int) $row[0]['send_reminders']);
        $this->assertSame(4, (int) $row[0]['reminder_hours_before']);
    }

    public function testSaveNotificationsRejectsInvalidEmail(): void
    {
        // Start from a known saved address rather than whatever an earlier test left.
        Database::execute('UPDATE `tenants` SET `notification_email` = ? WHERE `id` = ?', ['alerts@test.test', self::$tenantId]);
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/notifications", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings/notifications", [
            '_csrf_token'        => self::$operatorCsrf,
            'notification_email' => 'bad-email',
            'notify_on_booking'  => '1',
            'notify_on_cancellation' => '1',
            'send_reminders'     => '1',
            'reminder_hours_before' => '24',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $row = Database::query('SELECT `notification_email` FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        $this->assertSame('alerts@test.test', $row[0]['notification_email'], 'Must be unchanged on invalid');
    }

    // ════════════════════════════════════════════════════════════════
    // Audit logging (self-contained)
    // ════════════════════════════════════════════════════════════════

    public function testAuditLogCreatedOnPrivacySave(): void
    {
        // Clean audit for this tenant
        Database::execute(
            "DELETE FROM `audit_log` WHERE `action` = 'tenant.settings_updated' AND `entity_id` = ?",
            [self::$tenantId]
        );

        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/privacy", 'operator');
        self::extractCsrf($page['body'], 'operator');

        // Trigger a real change
        $current = Database::query('SELECT `data_retention_months` FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        $newVal = ((int) $current[0]['data_retention_months']) === 12 ? '36' : '12';

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings/privacy", [
            '_csrf_token'           => self::$operatorCsrf,
            'requires_consent'      => '1',
            'data_retention_months' => $newVal,
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $audit = Database::query(
            "SELECT * FROM `audit_log` WHERE `action` = 'tenant.settings_updated' AND `entity_id` = ? ORDER BY `created_at` DESC LIMIT 1",
            [self::$tenantId]
        );
        $this->assertNotEmpty($audit, 'Audit log for settings_updated must be created');
        $details = json_decode($audit[0]['details'], true);
        $this->assertSame('privacy', $details['tab'] ?? null, 'Audit must record the tab');
    }

    // ════════════════════════════════════════════════════════════════
    // Sidebar visibility
    // ════════════════════════════════════════════════════════════════

    public function testSidebarShowsSettingsForOperator(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings", 'operator');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('/settings"', $res['body']);
    }

    public function testSidebarHidesSettingsForManager(): void
    {
        if (self::$managerCookie === '') $this->markTestSkipped('Manager login not available');
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/bookings", 'manager');
        if ($res['code'] === 200) {
            $this->assertStringNotContainsString('/settings"', $res['body']);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Slug read-only
    // ════════════════════════════════════════════════════════════════

    public function testSlugNotChangedByGeneralSave(): void
    {
        $before = Database::query('SELECT `slug` FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        $originalSlug = $before[0]['slug'];

        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings", [
            '_csrf_token' => self::$operatorCsrf,
            'name'        => 'Settings Test Tenant',
            'email'       => 'settings-test@test.test',
        ], 'operator');

        $after = Database::query('SELECT `slug` FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        $this->assertSame($originalSlug, $after[0]['slug']);
    }

    // ════════════════════════════════════════════════════════════════
    // Booking Rules (timeslot + resource — Phase R)
    // ════════════════════════════════════════════════════════════════

    public function testBookingTabLoadsForTimeslotOperator(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/booking", 'operator');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('ts-slot-duration', $res['body']);
    }

    public function testBookingTabLoadsForTimeslotOwner(): void
    {
        if (self::$ownerCookie === '') $this->markTestSkipped('Owner login not available');
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/booking", 'owner');
        $this->assertSame(200, $res['code']);
    }

    public function testBookingTabForbiddenForManager(): void
    {
        if (self::$managerCookie === '') $this->markTestSkipped('Manager login not available');
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/booking", 'manager');
        $this->assertSame(403, $res['code']);
    }

    public function testBookingTabAccessibleForResourceTenant(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$resourceTenantId . "/settings/booking", 'operator');
        $this->assertSame(200, $res['code'], 'Booking rules tab must be accessible for resource tenants');
        // Shared constraint fields must appear
        $this->assertStringContainsString('ts-min-advance', $res['body'], 'min_advance field must appear');
        $this->assertStringContainsString('ts-max-advance', $res['body'], 'max_advance field must appear');
        // Timeslot-specific fields must NOT appear
        $this->assertStringNotContainsString('ts-slot-duration', $res['body'], 'slot_duration must not appear for resource tenant');
        $this->assertStringNotContainsString('ts-buffer', $res['body'], 'buffer must not appear for resource tenant');
    }

    public function testBookingPostForbiddenForManager(): void
    {
        if (self::$managerCookie === '') $this->markTestSkipped('Manager login not available');

        $before = Database::query(
            'SELECT `slot_duration_minutes`, `buffer_minutes`, `min_advance_hours`, `max_advance_days` FROM `tenants` WHERE `id` = ?',
            [self::$tenantId]
        );

        // Grab a CSRF token from a page the manager can access
        $bookings = self::httpGet("/admin/tenants/" . self::$tenantId . "/bookings", 'manager');
        self::extractCsrf($bookings['body'], 'manager');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings/booking", [
            '_csrf_token'           => self::$managerCsrf,
            'slot_duration_minutes' => '99',
            'buffer_minutes'        => '99',
            'min_advance_hours'     => '99',
            'max_advance_days'      => '999',
        ], 'manager');

        $this->assertSame(403, $res['code']);

        $after = Database::query(
            'SELECT `slot_duration_minutes`, `buffer_minutes`, `min_advance_hours`, `max_advance_days` FROM `tenants` WHERE `id` = ?',
            [self::$tenantId]
        );
        $this->assertSame($before[0], $after[0], 'Booking fields must be unchanged after manager POST');
    }

    public function testBookingPostRejectsTimeslotFieldsForResourceTenant(): void
    {
        $before = Database::query(
            'SELECT `slot_duration_minutes`, `buffer_minutes`, `min_advance_hours`, `max_advance_days` FROM `tenants` WHERE `id` = ?',
            [self::$resourceTenantId]
        );

        $page = self::httpGet("/admin/tenants/" . self::$resourceTenantId . "/settings/booking", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$resourceTenantId . "/settings/booking", [
            '_csrf_token'                      => self::$operatorCsrf,
            'slot_duration_minutes'             => '99',
            'buffer_minutes'                    => '99',
            'min_advance_hours'                 => '4',
            'max_advance_days'                  => '120',
            'max_bookings_per_customer_per_day' => '5',
        ], 'operator');

        $this->assertSame(403, $res['code']);

        $after = Database::query(
            'SELECT `slot_duration_minutes`, `buffer_minutes`, `min_advance_hours`, `max_advance_days`, `max_bookings_per_customer_per_day` FROM `tenants` WHERE `id` = ?',
            [self::$resourceTenantId]
        );

        // Typed operations reject fields that this pattern does not support.
        $this->assertSame($before[0]['min_advance_hours'], $after[0]['min_advance_hours']);
        $this->assertSame($before[0]['max_advance_days'], $after[0]['max_advance_days']);

        // Pattern-incompatible input cannot partially update the business.
        $this->assertSame($before[0]['slot_duration_minutes'], $after[0]['slot_duration_minutes'], 'slot_duration must be unchanged for resource tenant');
        $this->assertSame($before[0]['buffer_minutes'], $after[0]['buffer_minutes'], 'buffer_minutes must be unchanged for resource tenant');

        // Restore
        Database::execute(
            'UPDATE `tenants` SET `min_advance_hours` = ?, `max_advance_days` = ?, `max_bookings_per_customer_per_day` = ? WHERE `id` = ?',
            [$before[0]['min_advance_hours'] ?? 1, $before[0]['max_advance_days'] ?? 90, 3, self::$resourceTenantId]
        );
    }

    public function testSaveBookingUpdatesAllFields(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/booking", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings/booking", [
            '_csrf_token'           => self::$operatorCsrf,
            'slot_duration_minutes' => '45',
            'buffer_minutes'        => '10',
            'min_advance_hours'     => '2',
            'max_advance_days'      => '60',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $row = Database::query('SELECT `slot_duration_minutes`, `buffer_minutes`, `min_advance_hours`, `max_advance_days` FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        $this->assertSame(45, (int) $row[0]['slot_duration_minutes']);
        $this->assertSame(10, (int) $row[0]['buffer_minutes']);
        $this->assertSame(2, (int) $row[0]['min_advance_hours']);
        $this->assertSame(60, (int) $row[0]['max_advance_days']);
    }

    public function testInvalidSlotDurationIsRejectedWithoutSilentClamping(): void
    {
        $before=Database::query('SELECT slot_duration_minutes,max_advance_days FROM tenants WHERE id=?',[self::$tenantId])[0];
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/booking", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings/booking", [
            '_csrf_token'           => self::$operatorCsrf,
            'slot_duration_minutes' => '0',
            'buffer_minutes'        => '0',
            'min_advance_hours'     => '0',
            'max_advance_days'      => '0',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $row = Database::query('SELECT `slot_duration_minutes`, `max_advance_days` FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        $this->assertSame($before['slot_duration_minutes'], $row[0]['slot_duration_minutes']);
        $this->assertSame($before['max_advance_days'], $row[0]['max_advance_days']);
    }

    public function testBookingAuditLogCreated(): void
    {
        Database::execute(
            "DELETE FROM `audit_log` WHERE `action` = 'tenant.settings_updated' AND `entity_id` = ?",
            [self::$tenantId]
        );

        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/booking", 'operator');
        self::extractCsrf($page['body'], 'operator');

        // Trigger a real change
        $current = Database::query('SELECT `slot_duration_minutes` FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        $newVal = ((int) $current[0]['slot_duration_minutes']) === 30 ? '45' : '30';

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings/booking", [
            '_csrf_token'           => self::$operatorCsrf,
            'slot_duration_minutes' => $newVal,
            'buffer_minutes'        => '0',
            'min_advance_hours'     => '1',
            'max_advance_days'      => '90',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $audit = Database::query(
            "SELECT * FROM `audit_log` WHERE `action` = 'tenant.settings_updated' AND `entity_id` = ? ORDER BY `created_at` DESC LIMIT 1",
            [self::$tenantId]
        );
        $this->assertNotEmpty($audit);
        $details = json_decode($audit[0]['details'], true);
        $this->assertSame('booking', $details['tab'] ?? null);
    }

    public function testTabNavShowsBookingForTimeslotTenant(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings", 'operator');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('tab-booking', $res['body'], 'Booking tab must appear for timeslot tenant');
    }

    public function testTabNavShowsBookingForResourceTenant(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$resourceTenantId . "/settings", 'operator');
        $this->assertSame(200, $res['code']);
        // Booking Rules tab must appear for resource tenants (Phase R)
        $this->assertStringContainsString('id="tab-booking"', $res['body'], 'Booking Rules tab must appear for resource tenant');
        $this->assertStringContainsString('tab-bookingpage', $res['body'], 'Booking Page tab must appear for all patterns');
    }

    public function testBookingTabForbiddenForCapacityTenant(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$capacityTenantId . "/settings/booking", 'operator');
        $this->assertSame(403, $res['code'], 'Booking rules GET must be 403 for capacity tenant (Phase R scope)');
    }

    public function testBookingPostForbiddenForCapacityTenant(): void
    {
        // Get CSRF from the settings page (not booking, which would 403)
        $page = self::httpGet("/admin/tenants/" . self::$capacityTenantId . "/settings", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$capacityTenantId . "/settings/booking", [
            '_csrf_token'       => self::$operatorCsrf,
            'min_advance_hours' => '2',
            'max_advance_days'  => '30',
        ], 'operator');

        $this->assertSame(403, $res['code'], 'Booking rules POST must be 403 for capacity tenant (Phase R scope)');
    }

    public function testTabNavHidesBookingForCapacityTenant(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$capacityTenantId . "/settings", 'operator');
        $this->assertSame(200, $res['code']);
        $this->assertStringNotContainsString('id="tab-booking"', $res['body'], 'Booking Rules tab must be hidden for capacity tenant');
        $this->assertStringContainsString('tab-bookingpage', $res['body'], 'Booking Page tab must appear for all patterns');
    }

    public function testBookingTabForbiddenForEventTenant(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$eventTenantId . "/settings/booking", 'operator');
        $this->assertSame(403, $res['code'], 'Booking rules GET must be 403 for event tenant (Phase R scope)');
    }

    public function testBookingPostForbiddenForEventTenant(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$eventTenantId . "/settings", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$eventTenantId . "/settings/booking", [
            '_csrf_token'       => self::$operatorCsrf,
            'min_advance_hours' => '2',
            'max_advance_days'  => '30',
        ], 'operator');

        $this->assertSame(403, $res['code'], 'Booking rules POST must be 403 for event tenant (Phase R scope)');
    }

    public function testTabNavHidesBookingForEventTenant(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$eventTenantId . "/settings", 'operator');
        $this->assertSame(200, $res['code']);
        $this->assertStringNotContainsString('id="tab-booking"', $res['body'], 'Booking Rules tab must be hidden for event tenant');
        $this->assertStringContainsString('tab-bookingpage', $res['body'], 'Booking Page tab must appear for all patterns');
    }

    // ════════════════════════════════════════════════════════════════
    // Booking Page tab (all patterns)
    // ════════════════════════════════════════════════════════════════

    public function testBookingPageTabLoadsForOperator(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/bookingpage", 'operator');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('ts-confirmation-msg', $res['body']);
        $this->assertStringContainsString('ts-cancel-policy', $res['body']);
    }

    public function testBookingPageTabLoadsForResourceTenant(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$resourceTenantId . "/settings/bookingpage", 'operator');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('ts-confirmation-msg', $res['body']);
    }

    public function testSaveBookingPageUpdatesAllFields(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/bookingpage", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings/bookingpage", [
            '_csrf_token'               => self::$operatorCsrf,
            'confirmation_message'      => 'Thank you for booking!',
            'cancellation_policy'       => 'Cancel 24h in advance.',
            'require_phone'             => '1',
            'booking_requires_approval' => '1',
            'allow_cancellation'        => '1',
            'cancellation_hours_before' => '48',
            'allow_rescheduling'        => '0',
            'rescheduling_hours_before' => '12',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $row = Database::query(
            'SELECT `confirmation_message`, `cancellation_policy`, `require_phone`, `booking_requires_approval`,
                    `allow_cancellation`, `cancellation_hours_before`, `allow_rescheduling`, `rescheduling_hours_before`
             FROM `tenants` WHERE `id` = ?',
            [self::$tenantId]
        );
        $this->assertSame('Thank you for booking!', $row[0]['confirmation_message']);
        $this->assertSame('Cancel 24h in advance.', $row[0]['cancellation_policy']);
        $this->assertSame(1, (int) $row[0]['require_phone']);
        $this->assertSame(1, (int) $row[0]['booking_requires_approval']);
        $this->assertSame(1, (int) $row[0]['allow_cancellation']);
        $this->assertSame(48, (int) $row[0]['cancellation_hours_before']);
        $this->assertSame(0, (int) $row[0]['allow_rescheduling']);
        $this->assertSame(12, (int) $row[0]['rescheduling_hours_before']);
    }

    // ════════════════════════════════════════════════════════════════
    // Email Templates tab (all patterns)
    // ════════════════════════════════════════════════════════════════

    public function testEmailsTabLoadsForOperator(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/emails", 'operator');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('tpl-confirmation-subject', $res['body']);
        $this->assertStringContainsString('tpl-reminder-subject', $res['body']);
        $this->assertStringContainsString('tpl-cancellation-subject', $res['body']);
        $this->assertStringContainsString('tpl-reschedule_confirmation-subject', $res['body'], 'Reschedule confirmation type must render on the emails tab');
        $this->assertStringContainsString('tpl-approval_request-subject', $res['body']);
    }

    public function testSaveEmailTemplatesRoundTrip(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/emails", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings/emails", [
            '_csrf_token'                    => self::$operatorCsrf,
            'confirmation_subject'           => 'Your booking at {business_name}',
            'confirmation_heading'           => 'Welcome!',
            'confirmation_body_intro'        => 'Thanks for booking with us.',
            'confirmation_body_outro'        => 'See you soon!',
            'confirmation_cta_label'         => 'View Details',
            'confirmation_is_enabled'        => '1',
            'reminder_subject'               => '',
            'reminder_heading'               => '',
            'reminder_body_intro'            => '',
            'reminder_body_outro'            => '',
            'reminder_cta_label'             => '',
            'reminder_is_enabled'            => '1',
            'cancellation_subject'           => '',
            'cancellation_heading'           => '',
            'cancellation_body_intro'        => '',
            'cancellation_body_outro'        => '',
            'cancellation_cta_label'         => '',
            'cancellation_is_enabled'        => '0',
            'reschedule_confirmation_subject'  => 'Your appointment was moved',
            'reschedule_confirmation_heading'  => 'Rescheduled',
            'reschedule_confirmation_body_intro' => '',
            'reschedule_confirmation_body_outro' => '',
            'reschedule_confirmation_cta_label' => '',
            'reschedule_confirmation_is_enabled' => '1',
            'approval_request_subject'         => '',
            'approval_request_heading'         => '',
            'approval_request_body_intro'      => '',
            'approval_request_body_outro'      => '',
            'approval_request_cta_label'       => '',
            'approval_request_is_enabled'      => '1',
            'approval_confirmed_subject'       => '',
            'approval_confirmed_heading'       => '',
            'approval_confirmed_body_intro'    => '',
            'approval_confirmed_body_outro'    => '',
            'approval_confirmed_cta_label'     => '',
            'approval_confirmed_is_enabled'    => '1',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        // Verify confirmation template was saved
        $rows = Database::query(
            "SELECT `subject`, `heading`, `body_intro`, `body_outro`, `cta_label`, `is_enabled`
             FROM `tenant_email_templates` WHERE `tenant_id` = ? AND `type` = 'confirmation'",
            [self::$tenantId]
        );
        $this->assertNotEmpty($rows);
        $this->assertSame('Your booking at {business_name}', $rows[0]['subject']);
        $this->assertSame('Welcome!', $rows[0]['heading']);
        $this->assertSame(1, (int) $rows[0]['is_enabled']);

        // Verify cancellation was disabled
        $cancel = Database::query(
            "SELECT `is_enabled` FROM `tenant_email_templates` WHERE `tenant_id` = ? AND `type` = 'cancellation'",
            [self::$tenantId]
        );
        $this->assertNotEmpty($cancel);
        $this->assertSame(0, (int) $cancel[0]['is_enabled']);

        // Verify reschedule_confirmation was saved and enabled
        $resched = Database::query(
            "SELECT `subject`, `heading`, `is_enabled` FROM `tenant_email_templates` WHERE `tenant_id` = ? AND `type` = 'reschedule_confirmation'",
            [self::$tenantId]
        );
        $this->assertNotEmpty($resched, 'reschedule_confirmation row must be persisted');
        $this->assertSame('Your appointment was moved', $resched[0]['subject']);
        $this->assertSame('Rescheduled', $resched[0]['heading']);
        $this->assertSame(1, (int) $resched[0]['is_enabled'], 'reschedule_confirmation must be enabled');
    }

    public function testRescheduleConfirmationDisableRoundTrip(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/emails", 'operator');
        self::extractCsrf($page['body'], 'operator');

        // Submit with all types enabled except reschedule_confirmation
        $payload = [
            '_csrf_token' => self::$operatorCsrf,
        ];
        foreach (['confirmation', 'reminder', 'cancellation', 'approval_request', 'approval_confirmed'] as $t) {
            foreach (['subject', 'heading', 'body_intro', 'body_outro', 'cta_label'] as $f) {
                $payload["{$t}_{$f}"] = '';
            }
            $payload["{$t}_is_enabled"] = '1';
        }
        // Disable reschedule_confirmation
        foreach (['subject', 'heading', 'body_intro', 'body_outro', 'cta_label'] as $f) {
            $payload["reschedule_confirmation_{$f}"] = '';
        }
        $payload['reschedule_confirmation_is_enabled'] = '0';

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings/emails", $payload, 'operator');
        $this->assertSame(302, $res['code']);

        $row = Database::query(
            "SELECT `is_enabled` FROM `tenant_email_templates` WHERE `tenant_id` = ? AND `type` = 'reschedule_confirmation'",
            [self::$tenantId]
        );
        $this->assertNotEmpty($row);
        $this->assertSame(0, (int) $row[0]['is_enabled'], 'reschedule_confirmation must be disabled after save');
    }

    // ════════════════════════════════════════════════════════════════
    // Embed settings tab
    // ════════════════════════════════════════════════════════════════

    public function testEmbedTabLoadsForOperator(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/embed", 'operator');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('ts-embed-label', $res['body'], 'Embed settings form must render');
        $this->assertStringContainsString('ts-embed-position', $res['body']);
        $this->assertStringContainsString('ts-embed-domains', $res['body']);
        $this->assertStringContainsString('embed-code-snippet', $res['body'], 'Embed code preview must render');
    }

    public function testEmbedTabForbiddenForManager(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/embed", 'manager');
        $this->assertSame(403, $res['code']);
    }

    public function testSaveEmbedSettingsRoundTrip(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/embed", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings/embed", [
            '_csrf_token'            => self::$operatorCsrf,
            'embed_button_label'     => 'Reserve Now',
            'embed_button_position'  => 'bottom-left',
            'allowed_embed_domains'  => "https://example.com\nshop.test.com/\n  my-site.org  ",
        ], 'operator');
        $this->assertSame(302, $res['code']);

        // Verify persistence
        $row = Database::query(
            "SELECT `embed_button_label`, `embed_button_position`, `allowed_embed_domains` FROM `tenants` WHERE `id` = ?",
            [self::$tenantId]
        );
        $this->assertNotEmpty($row);
        $this->assertSame('Reserve Now', $row[0]['embed_button_label']);
        $this->assertSame('bottom-left', $row[0]['embed_button_position']);
        // Domains should be stored comma-separated, protocols stripped, trimmed
        $this->assertSame('example.com,shop.test.com,my-site.org', $row[0]['allowed_embed_domains']);
    }

    public function testSaveEmbedInvalidPositionPreservesCurrentValues(): void
    {
        $before=Database::query('SELECT embed_button_position,allowed_embed_domains FROM tenants WHERE id=?',[self::$tenantId])[0];
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/settings/embed", 'operator');
        self::extractCsrf($page['body'], 'operator');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/settings/embed", [
            '_csrf_token'            => self::$operatorCsrf,
            'embed_button_label'     => 'Book Now',
            'embed_button_position'  => 'top-center',
            'allowed_embed_domains'  => '',
        ], 'operator');
        $this->assertSame(302, $res['code']);

        $row = Database::query(
            "SELECT `embed_button_position`, `allowed_embed_domains` FROM `tenants` WHERE `id` = ?",
            [self::$tenantId]
        );
        $this->assertSame($before['embed_button_position'], $row[0]['embed_button_position']);
        $this->assertSame($before['allowed_embed_domains'], $row[0]['allowed_embed_domains']);
    }

    // ════════════════════════════════════════════════════════════════
    // Embed config public API
    // ════════════════════════════════════════════════════════════════

    public function testEmbedConfigReturnsJson(): void
    {
        // First set some known embed values
        $slug = Database::query("SELECT `slug` FROM `tenants` WHERE `id` = ?", [self::$tenantId])[0]['slug'];

        Database::execute(
            "UPDATE `tenants` SET `embed_button_label` = 'Book Now', `embed_button_position` = 'bottom-right' WHERE `id` = ?",
            [self::$tenantId]
        );

        $res = self::httpGet("/api/{$slug}/embed-config");
        $this->assertSame(200, $res['code']);

        $data = json_decode($res['body'], true);
        $this->assertNotNull($data);
        $this->assertSame($slug, $data['slug']);
        $this->assertSame('Book Now', $data['button_label']);
        $this->assertSame('bottom-right', $data['button_position']);
        $this->assertArrayHasKey('brand_color', $data);
        $this->assertArrayHasKey('name', $data);

        // PRD contract: must include consent settings, custom fields, and allowed domains
        $this->assertArrayHasKey('requires_consent', $data, 'embed-config must include requires_consent');
        $this->assertArrayHasKey('consent_text', $data, 'embed-config must include consent_text');
        $this->assertArrayHasKey('privacy_policy_url', $data, 'embed-config must include privacy_policy_url');
        $this->assertArrayHasKey('custom_fields', $data, 'embed-config must include custom_fields');
        $this->assertArrayHasKey('allowed_domains', $data, 'embed-config must include allowed_domains');

        // Demo-aware behavior: is_demo must always be present as a boolean
        $this->assertArrayHasKey('is_demo', $data, 'embed-config must include is_demo for demo-aware widget behavior');
        $this->assertIsBool($data['is_demo'], 'is_demo must be a boolean value');
    }

    public function testEmbedConfigDemoFlagIsBool(): void
    {
        $slug = Database::query("SELECT `slug` FROM `tenants` WHERE `id` = ?", [self::$tenantId])[0]['slug'];
        $res = self::httpGet("/api/{$slug}/embed-config");
        $data = json_decode($res['body'], true);

        $this->assertArrayHasKey('is_demo', $data);
        $this->assertIsBool($data['is_demo']);
    }

    public function testEmbedScriptContainsDemoBadgeLogic(): void
    {
        $slug = Database::query("SELECT `slug` FROM `tenants` WHERE `id` = ?", [self::$tenantId])[0]['slug'];
        $res = self::httpGet("/embed/{$slug}.js");
        $this->assertSame(200, $res['code']);

        // The widget script must contain demo badge rendering logic
        $this->assertStringContainsString('is_demo', $res['body'],
            'Embed script must reference is_demo for demo-aware badge rendering');
        $this->assertStringContainsString('vb-embed-demo', $res['body'],
            'Embed script must contain demo badge element ID');
    }

    public function testEmbedConfigCacheHeaders(): void
    {
        $slug = Database::query("SELECT `slug` FROM `tenants` WHERE `id` = ?", [self::$tenantId])[0]['slug'];
        $res = self::httpGet("/api/{$slug}/embed-config");
        $this->assertSame(200, $res['code']);

        // Embed-config must have public cache-control, not no-store
        $this->assertStringContainsString('max-age=60', $res['headers'], 'embed-config must be publicly cached for 60s');
        $this->assertStringNotContainsString('no-store', $res['headers'], 'embed-config must not have no-store');
    }

    public function testEmbedConfigReturns404ForMissing(): void
    {
        $res = self::httpGet("/api/nonexistent-slug-999/embed-config");
        $this->assertSame(404, $res['code']);
    }

    public function testEmbedScriptReturnsJavascript(): void
    {
        $slug = Database::query("SELECT `slug` FROM `tenants` WHERE `id` = ?", [self::$tenantId])[0]['slug'];
        $res = self::httpGet("/embed/{$slug}.js");
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('__vbEmbed', $res['body'], 'Embed script must contain widget code');
        $this->assertStringContainsString($slug, $res['body'], 'Embed script must reference tenant slug');
    }

    public function testEmbedScriptCacheHeaders(): void
    {
        $slug = Database::query("SELECT `slug` FROM `tenants` WHERE `id` = ?", [self::$tenantId])[0]['slug'];
        $res = self::httpGet("/embed/{$slug}.js");
        $this->assertSame(200, $res['code']);

        // Embed script must have public 1-hour cache, not no-store
        $this->assertStringContainsString('max-age=3600', $res['headers'], 'embed script must be cached for 1 hour');
        $this->assertStringNotContainsString('no-store', $res['headers'], 'embed script must not have no-store');
    }

    public function testEmbedScriptReturns404ForMissing(): void
    {
        $res = self::httpGet("/embed/nonexistent-slug-999.js");
        $this->assertSame(404, $res['code']);
    }

    // ── Embed booking page header tests ──

    public function testEmbedBookingPageHasCorrectFrameAncestors(): void
    {
        $slug = Database::query("SELECT `slug` FROM `tenants` WHERE `id` = ?", [self::$tenantId])[0]['slug'];
        $res = self::httpGet("/book/{$slug}?embed=1");
        $this->assertSame(200, $res['code']);

        // Must NOT have frame-ancestors 'none' (that blocks embedding)
        $this->assertStringNotContainsString("frame-ancestors 'none'", $res['headers'],
            'Embed booking page must not block iframe embedding');

        // Must have frame-ancestors with at least 'self'
        $this->assertStringContainsString("frame-ancestors 'self'", $res['headers'],
            'Embed booking page must include frame-ancestors self');

        // Must NOT have X-Frame-Options (skipped for embed)
        // HTTP/2 lowercases header names
        $this->assertDoesNotMatchRegularExpression('/x-frame-options/i', $res['headers'],
            'Embed booking page must not have X-Frame-Options');
    }

    public function testEmbedBookingPageIsStateless(): void
    {
        $slug = Database::query("SELECT `slug` FROM `tenants` WHERE `id` = ?", [self::$tenantId])[0]['slug'];

        // Use raw curl without sending any existing cookies
        $ch = curl_init(self::$baseUrl . "/book/{$slug}?embed=1");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
        ]);
        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);

        $headers = substr($response, 0, $headerSize);
        $this->assertSame(200, $code);

        // Must NOT set any cookies (stateless embed per PRD)
        $this->assertStringNotContainsString('Set-Cookie', $headers,
            'Embed booking page must not set cookies (stateless requirement)');
    }

    public function testNonEmbedBookingPageBlocksFraming(): void
    {
        $slug = Database::query("SELECT `slug` FROM `tenants` WHERE `id` = ?", [self::$tenantId])[0]['slug'];
        $res = self::httpGet("/book/{$slug}");
        $this->assertSame(200, $res['code']);

        // Non-embed booking page MUST block framing
        $this->assertStringContainsString("frame-ancestors 'none'", $res['headers'],
            'Non-embed booking page must block iframe embedding');
        // HTTP/2 lowercases header names, so check case-insensitively
        $this->assertMatchesRegularExpression('/x-frame-options:\s*SAMEORIGIN/i', $res['headers'],
            'Non-embed booking page must have X-Frame-Options');
    }

    // ════════════════════════════════════════════════════════════════
    // Seed data
    // ════════════════════════════════════════════════════════════════

    private static function seedData(): void
    {
        self::$tenantId = Ulid::generate();
        self::$resourceTenantId = Ulid::generate();

        Database::execute(
            "INSERT INTO `tenants`
             (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`, `brand_color`, `timezone`, `currency`)
             VALUES (?, ?, 'Settings Test Tenant', 'settings-test@test.test', 'timeslot', 'active', '#2563EB', 'UTC', 'EUR')",
            [self::$tenantId, 'test-settings-' . substr(self::$tenantId, -8)]
        );

        Database::execute(
            "INSERT INTO `tenants`
             (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`, `brand_color`, `timezone`, `currency`)
             VALUES (?, ?, 'Resource Test Tenant', 'resource-test@test.test', 'resource', 'active', '#2563EB', 'UTC', 'EUR')",
            [self::$resourceTenantId, 'test-resource-' . substr(self::$resourceTenantId, -8)]
        );

        self::$capacityTenantId = Ulid::generate();
        Database::execute(
            "INSERT INTO `tenants`
             (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`, `brand_color`, `timezone`, `currency`)
             VALUES (?, ?, 'Capacity Test Tenant', 'capacity-test@test.test', 'capacity', 'active', '#2563EB', 'UTC', 'EUR')",
            [self::$capacityTenantId, 'test-capacity-' . substr(self::$capacityTenantId, -8)]
        );

        self::$eventTenantId = Ulid::generate();
        Database::execute(
            "INSERT INTO `tenants`
             (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`, `brand_color`, `timezone`, `currency`)
             VALUES (?, ?, 'Event Test Tenant', 'event-test@test.test', 'event', 'active', '#2563EB', 'UTC', 'EUR')",
            [self::$eventTenantId, 'test-event-' . substr(self::$eventTenantId, -8)]
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
        $email = 'test-ts-owner-' . bin2hex(random_bytes(4)) . '@test.test';
        $password = 'OwnerTsTest123!';
        self::$ownerId = Ulid::generate();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::execute(
            "INSERT INTO `business_users` (`id`, `tenant_id`, `name`, `email`, `password_hash`, `role`, `is_active`, `force_password_change`)
             VALUES (?, ?, 'TS Test Owner', ?, ?, 'owner', 1, 0)",
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
        $email = 'test-ts-mgr-' . bin2hex(random_bytes(4)) . '@test.test';
        $password = 'MgrTsTest123!';
        self::$managerId = Ulid::generate();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::execute(
            "INSERT INTO `business_users` (`id`, `tenant_id`, `name`, `email`, `password_hash`, `role`, `is_active`, `force_password_change`)
             VALUES (?, ?, 'TS Test Manager', ?, ?, 'manager', 1, 0)",
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
        if(str_contains($path,'/settings')&&!isset($data['expected_version'])) {
            $form=self::httpGet($path,$role);
            if(preg_match('/name="expected_version"\s+value="([^"]+)"/',$form['body'],$match)) { $data['expected_version']=$match[1]; }
        }
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
        if ($cookie) $headers[] = 'Cookie: ' . $cookie;
        if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
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

    /** @return array{code: int, headers: string, body: string} */
    private static function httpGetPublic(string $path): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
        ]);

        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);

        return [
            'code'    => $code,
            'headers' => substr($response, 0, $headerSize),
            'body'    => substr($response, $headerSize),
        ];
    }
}
