<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\Ulid;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for Slice 5: Public Homepage & Business Access Requests.
 *
 * Covers:
 * - Homepage default mode (simple logo/name, no form)
 * - Homepage applications mode (full landing with form when enabled)
 * - Form validation (required fields, invalid email)
 * - Duplicate pending email rejection
 * - Website field persistence
 * - Anti-spam: honeypot rejection, timestamp rejection
 * - POST guard when applications disabled
 * - Operator-only access to /admin/applications
 * - Approve/reject status transitions, reviewer timestamps
 * - Audit logging for approve/reject
 *
 * Also covers Slice 4 tenant-isolation:
 * - Disabling powered-by for one tenant does not affect another
 */
final class BusinessApplicationsTest extends TestCase
{
    private static string $baseUrl;
    private static bool $appReachable = false;
    private static bool $dbReady = false;
    private static string $setupError = '';

    private static string $operatorCookie = '';
    private static string $operatorCsrf = '';

    /** Public session cookie (unauthenticated, needed for CSRF) */
    private static string $publicCookie = '';
    private static string $publicCsrf = '';

    /** ID of a created application for approve/reject tests */
    private static string $applicationId = '';

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

        // Ensure business_applications table exists
        try {
            Database::query('SELECT 1 FROM `business_applications` LIMIT 1');
        } catch (\Throwable) {
            self::$setupError = 'business_applications table not found';
            return;
        }

        // Clean any leftover test data
        try {
            Database::execute("DELETE FROM `business_applications` WHERE `email` LIKE '%@batest.test'");
        } catch (\Throwable) {}

        try {
            TestFixtures::provision();
            self::loginOperator();
        } catch (\Throwable $e) {
            self::$setupError = 'Provisioning failed: ' . $e->getMessage();
        }
    }

    protected function setUp(): void
    {
        if (!self::$appReachable) {
            $this->markTestSkipped(self::$setupError ?: 'App not reachable');
        }
        if (!self::$dbReady) {
            $this->markTestSkipped(self::$setupError ?: 'DB not ready');
        }

        try {
            Database::execute('TRUNCATE TABLE `rate_limits`');
        } catch (\Throwable) {}
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Database::execute("DELETE FROM `business_applications` WHERE `email` LIKE '%@batest.test'");
        } catch (\Throwable) {}
        // Restore default: applications disabled
        try {
            Database::execute("DELETE FROM `settings` WHERE `key` = 'enable_applications'");
        } catch (\Throwable) {}
    }

    // ════════════════════════════════════════════════════════════════
    // Homepage default mode (applications disabled)
    // ════════════════════════════════════════════════════════════════

    public function testHomepageDefaultModeShowsSimplePage(): void
    {
        // Ensure applications disabled
        Database::upsertSetting('enable_applications', '0');

        $res = self::httpPublic('GET', '/');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('class="lp-simple"', $res['body'],
            'Default homepage must render the simple centered layout');
        $this->assertStringNotContainsString('id="request-form"', $res['body'],
            'Request form must not render when applications disabled');
        $this->assertStringNotContainsString('class="lp-features"', $res['body'],
            'Feature list must not render when applications disabled');
    }

    public function testHomepageDefaultModeShowsAdminLogin(): void
    {
        Database::upsertSetting('enable_applications', '0');

        $res = self::httpPublic('GET', '/');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('/admin/login', $res['body'],
            'Default homepage must link to admin login');
    }

    // ════════════════════════════════════════════════════════════════
    // Homepage applications mode (enabled)
    // ════════════════════════════════════════════════════════════════

    public function testHomepageApplicationsModeShowsFullLanding(): void
    {
        Database::upsertSetting('enable_applications', '1');

        $res = self::httpPublic('GET', '/');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('id="request-form"', $res['body'],
            'Request form must render when applications enabled');
        $this->assertStringContainsString('lp-features', $res['body'],
            'Feature list must render when applications enabled');
        $this->assertStringContainsString('lp-hero', $res['body'],
            'Hero section must render when applications enabled');
    }

    public function testHomepageApplicationsModeHasAntiSpamFields(): void
    {
        Database::upsertSetting('enable_applications', '1');

        $res = self::httpPublic('GET', '/');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('name="__hp"', $res['body'],
            'Honeypot field must be present');
        $this->assertStringContainsString('name="__ts"', $res['body'],
            'Timestamp field must be present');
        $this->assertStringContainsString('name="_csrf_token"', $res['body'],
            'CSRF token must be present');
    }

    public function testHomepageApplicationsModeHasWebsiteField(): void
    {
        Database::upsertSetting('enable_applications', '1');

        $res = self::httpPublic('GET', '/');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('name="website"', $res['body'],
            'Website field must be present on the request form');
    }

    // ════════════════════════════════════════════════════════════════
    // POST /request-access — validation
    // ════════════════════════════════════════════════════════════════

    public function testSubmitRejectsWhenApplicationsDisabled(): void
    {
        // First, enable applications to get a valid CSRF token + session
        Database::upsertSetting('enable_applications', '1');
        $home = self::httpPublic('GET', '/');
        $csrf = self::extractPublicCsrf($home);

        // Now disable applications before POST
        Database::upsertSetting('enable_applications', '0');

        $res = self::httpPublic('POST', '/request-access', [
            '_csrf_token'   => $csrf,
            '__ts'          => (string) ((time() - 10) * 1000),
            '__hp'          => '',
            'business_name' => 'Rejected Corp',
            'contact_name'  => 'Rejected User',
            'email'         => 'rejected-disabled@batest.test',
        ]);

        // Should redirect back without inserting
        $this->assertSame(302, $res['code']);

        $row = Database::query(
            "SELECT COUNT(*) AS cnt FROM `business_applications` WHERE `email` = 'rejected-disabled@batest.test'"
        );
        $this->assertSame(0, (int) $row[0]['cnt'],
            'Application must not be inserted when applications disabled');
    }

    public function testSubmitRejectsEmptyRequiredFields(): void
    {
        Database::upsertSetting('enable_applications', '1');

        $home = self::httpPublic('GET', '/');
        $csrf = self::extractPublicCsrf($home);

        $res = self::httpPublic('POST', '/request-access', [
            '_csrf_token'   => $csrf,
            '__ts'          => (string) ((time() - 10) * 1000),
            '__hp'          => '',
            'business_name' => '',
            'contact_name'  => '',
            'email'         => '',
        ]);

        $this->assertContains($res['code'], [302, 403],
            'Empty fields must be rejected via redirect or CSRF block');
    }

    public function testSubmitRejectsInvalidEmail(): void
    {
        Database::upsertSetting('enable_applications', '1');

        $home = self::httpPublic('GET', '/');
        $csrf = self::extractPublicCsrf($home);

        $res = self::httpPublic('POST', '/request-access', [
            '_csrf_token'   => $csrf,
            '__ts'          => (string) ((time() - 10) * 1000),
            '__hp'          => '',
            'business_name' => 'Valid Biz',
            'contact_name'  => 'Valid Contact',
            'email'         => 'not-an-email',
        ]);

        $this->assertContains($res['code'], [302, 403]);

        $row = Database::query(
            "SELECT COUNT(*) AS cnt FROM `business_applications` WHERE `email` = 'not-an-email'"
        );
        $this->assertSame(0, (int) $row[0]['cnt']);
    }

    // ════════════════════════════════════════════════════════════════
    // Anti-spam: honeypot and timestamp
    // ════════════════════════════════════════════════════════════════

    public function testSubmitRejectsHoneypotFilled(): void
    {
        Database::upsertSetting('enable_applications', '1');

        $home = self::httpPublic('GET', '/');
        $csrf = self::extractPublicCsrf($home);

        $res = self::httpPublic('POST', '/request-access', [
            '_csrf_token'   => $csrf,
            '__ts'          => (string) ((time() - 10) * 1000),
            '__hp'          => 'bot-text',
            'business_name' => 'Bot Corp',
            'contact_name'  => 'Bot User',
            'email'         => 'bot-hp@batest.test',
        ]);

        $this->assertContains($res['code'], [302, 403]);

        $row = Database::query(
            "SELECT COUNT(*) AS cnt FROM `business_applications` WHERE `email` = 'bot-hp@batest.test'"
        );
        $this->assertSame(0, (int) $row[0]['cnt'],
            'Honeypot-filled submission must be rejected');
    }

    public function testSubmitRejectsTooFastTimestamp(): void
    {
        Database::upsertSetting('enable_applications', '1');

        $home = self::httpPublic('GET', '/');
        $csrf = self::extractPublicCsrf($home);

        $res = self::httpPublic('POST', '/request-access', [
            '_csrf_token'   => $csrf,
            '__ts'          => (string) (time() * 1000), // Current time = too fast
            '__hp'          => '',
            'business_name' => 'Speed Corp',
            'contact_name'  => 'Speed User',
            'email'         => 'bot-ts@batest.test',
        ]);

        $this->assertContains($res['code'], [302, 403]);

        $row = Database::query(
            "SELECT COUNT(*) AS cnt FROM `business_applications` WHERE `email` = 'bot-ts@batest.test'"
        );
        $this->assertSame(0, (int) $row[0]['cnt'],
            'Too-fast timestamp submission must be rejected');
    }

    public function testSubmitRejectsMissingTimestamp(): void
    {
        Database::upsertSetting('enable_applications', '1');

        $home = self::httpPublic('GET', '/');
        $csrf = self::extractPublicCsrf($home);

        $res = self::httpPublic('POST', '/request-access', [
            '_csrf_token'   => $csrf,
            '__hp'          => '',
            'business_name' => 'NoTS Corp',
            'contact_name'  => 'NoTS User',
            'email'         => 'bot-nots@batest.test',
        ]);

        $this->assertContains($res['code'], [302, 403]);

        $row = Database::query(
            "SELECT COUNT(*) AS cnt FROM `business_applications` WHERE `email` = 'bot-nots@batest.test'"
        );
        $this->assertSame(0, (int) $row[0]['cnt'],
            'Missing timestamp submission must be rejected');
    }

    public function testSubmitRejectsStaleTimestamp(): void
    {
        Database::upsertSetting('enable_applications', '1');

        $home = self::httpPublic('GET', '/');
        $csrf = self::extractPublicCsrf($home);

        // Timestamp from 2 hours ago = stale
        $res = self::httpPublic('POST', '/request-access', [
            '_csrf_token'   => $csrf,
            '__ts'          => (string) ((time() - 7200) * 1000),
            '__hp'          => '',
            'business_name' => 'Stale Corp',
            'contact_name'  => 'Stale User',
            'email'         => 'bot-stale@batest.test',
        ]);

        $this->assertContains($res['code'], [302, 403]);

        $row = Database::query(
            "SELECT COUNT(*) AS cnt FROM `business_applications` WHERE `email` = 'bot-stale@batest.test'"
        );
        $this->assertSame(0, (int) $row[0]['cnt'],
            'Stale timestamp submission (>1 hour) must be rejected');
    }

    // ════════════════════════════════════════════════════════════════
    // Successful submission + website + duplicate check
    // ════════════════════════════════════════════════════════════════

    public function testSuccessfulSubmissionWithWebsite(): void
    {
        Database::upsertSetting('enable_applications', '1');

        $home = self::httpPublic('GET', '/');
        $csrf = self::extractPublicCsrf($home);

        $res = self::httpPublic('POST', '/request-access', [
            '_csrf_token'   => $csrf,
            '__ts'          => (string) ((time() - 10) * 1000),
            '__hp'          => '',
            'business_name' => 'Acme Salon',
            'contact_name'  => 'Jane Doe',
            'email'         => 'jane@batest.test',
            'phone'         => '+1234567890',
            'website'       => 'https://acme-salon.example.com',
            'message'       => 'We need booking for our salon.',
        ]);

        $this->assertSame(302, $res['code']);

        $row = Database::query(
            "SELECT * FROM `business_applications` WHERE `email` = 'jane@batest.test' LIMIT 1"
        );
        $this->assertNotEmpty($row, 'Application must be inserted');
        $this->assertSame('Acme Salon', $row[0]['business_name']);
        $this->assertSame('Jane Doe', $row[0]['contact_name']);
        $this->assertSame('+1234567890', $row[0]['phone']);
        $this->assertSame('https://acme-salon.example.com', $row[0]['website']);
        $this->assertSame('pending', $row[0]['status']);
        $this->assertSame('We need booking for our salon.', $row[0]['message']);

        self::$applicationId = $row[0]['id'];
    }

    public function testDuplicatePendingEmailRejected(): void
    {
        // Seed its own pending application: whatever order tests run in, this one stays pending.
        Database::execute("DELETE FROM `business_applications` WHERE `email` = 'dup@batest.test'");
        Database::execute(
            "INSERT INTO `business_applications` (`id`, `business_name`, `contact_name`, `email`, `status`, `created_at`)
             VALUES (?, 'Dup Salon', 'Dee Dup', 'dup@batest.test', 'pending', NOW())",
            [Ulid::generate()]
        );
        Database::upsertSetting('enable_applications', '1');

        $home = self::httpPublic('GET', '/');
        $csrf = self::extractPublicCsrf($home);

        $res = self::httpPublic('POST', '/request-access', [
            '_csrf_token'   => $csrf,
            '__ts'          => (string) ((time() - 10) * 1000),
            '__hp'          => '',
            'business_name' => 'Dup Salon 2',
            'contact_name'  => 'Dee Dup 2',
            'email'         => 'dup@batest.test',
        ]);

        $this->assertContains($res['code'], [302, 403]);

        $rows = Database::query(
            "SELECT COUNT(*) AS cnt FROM `business_applications` WHERE `email` = 'dup@batest.test'"
        );
        $this->assertSame(1, (int) $rows[0]['cnt'],
            'Duplicate pending application must be rejected — only one row expected');
        Database::execute("DELETE FROM `business_applications` WHERE `email` = 'dup@batest.test'");
    }

    // ════════════════════════════════════════════════════════════════
    // Admin /admin/applications — operator-only access
    // ════════════════════════════════════════════════════════════════

    public function testApplicationsPageRequiresAuth(): void
    {
        $res = self::httpPublic('GET', '/admin/applications');
        $this->assertContains($res['code'], [302, 403],
            'Applications page must redirect or forbid unauthenticated access');
    }

    public function testApplicationsPageLoadsForOperator(): void
    {
        $res = self::httpGet('/admin/applications');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('tab-pending', $res['body'],
            'Applications page must show status tabs');
    }

    public function testApplicationsPageShowsTestApplication(): void
    {
        // Its own pending application, so approval elsewhere in the class cannot hide it.
        Database::execute("DELETE FROM `business_applications` WHERE `email` = 'show@batest.test'");
        Database::execute(
            "INSERT INTO `business_applications` (`id`, `business_name`, `contact_name`, `email`, `website`, `status`, `created_at`)
             VALUES (?, 'Show Salon', 'Sam Show', 'show@batest.test', 'https://show-salon.example.com', 'pending', NOW())",
            [Ulid::generate()]
        );

        try {
            $res = self::httpGet('/admin/applications?status=pending');
            $this->assertSame(200, $res['code']);
            $this->assertStringContainsString('Show Salon', $res['body']);
            $this->assertStringContainsString('show@batest.test', $res['body']);
            $this->assertStringContainsString('show-salon.example.com', $res['body'],
                'Website must be visible in the admin review table');
        } finally {
            Database::execute("DELETE FROM `business_applications` WHERE `email` = 'show@batest.test'");
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Approve/Reject actions
    // ════════════════════════════════════════════════════════════════

    #[Depends('testSuccessfulSubmissionWithWebsite')]
    public function testApproveApplication(): void
    {

        // Get fresh CSRF
        $page = self::httpGet('/admin/applications');
        self::extractCsrf($page['body']);

        $res = self::httpPost('/admin/applications/' . self::$applicationId . '/approve', [
            '_csrf_token' => self::$operatorCsrf,
        ]);

        $this->assertSame(302, $res['code']);

        $row = Database::query(
            'SELECT `status`, `reviewed_at`, `reviewed_by` FROM `business_applications` WHERE `id` = ?',
            [self::$applicationId]
        );
        $this->assertSame('approved', $row[0]['status']);
        $this->assertNotNull($row[0]['reviewed_at'],
            'reviewed_at must be set on approval');
        $this->assertNotEmpty($row[0]['reviewed_by'],
            'reviewed_by must reference the operator');
    }

    #[Depends('testApproveApplication')]
    public function testApproveCreatesAuditEntry(): void
    {

        $rows = Database::query(
            "SELECT * FROM `audit_log` WHERE `entity_type` = 'business_application' AND `entity_id` = ? AND `action` LIKE '%approve%' ORDER BY `created_at` DESC LIMIT 1",
            [self::$applicationId]
        );
        $this->assertNotEmpty($rows, 'Approval must create an audit log entry');
    }

    public function testRejectApplication(): void
    {
        // Create a second application to test rejection
        $rejectId = Ulid::generate();
        Database::execute(
            "INSERT INTO `business_applications` (`id`, `business_name`, `contact_name`, `email`, `status`, `created_at`)
             VALUES (?, 'Reject Corp', 'Reject User', 'reject@batest.test', 'pending', NOW())",
            [$rejectId]
        );

        $page = self::httpGet('/admin/applications');
        self::extractCsrf($page['body']);

        $res = self::httpPost('/admin/applications/' . $rejectId . '/reject', [
            '_csrf_token' => self::$operatorCsrf,
        ]);

        $this->assertSame(302, $res['code']);

        $row = Database::query(
            'SELECT `status`, `reviewed_at`, `reviewed_by` FROM `business_applications` WHERE `id` = ?',
            [$rejectId]
        );
        $this->assertSame('rejected', $row[0]['status']);
        $this->assertNotNull($row[0]['reviewed_at']);
        $this->assertNotEmpty($row[0]['reviewed_by']);

        // Check audit
        $audit = Database::query(
            "SELECT * FROM `audit_log` WHERE `entity_id` = ? AND `action` LIKE '%reject%' LIMIT 1",
            [$rejectId]
        );
        $this->assertNotEmpty($audit, 'Rejection must create an audit log entry');
    }

    // ════════════════════════════════════════════════════════════════
    // Slice 4: Tenant-isolation for show_powered_by
    // ════════════════════════════════════════════════════════════════

    public function testPoweredByIsolationBetweenTenants(): void
    {
        // Use the main test tenant + resource tenant from TestFixtures
        $tenantA = TestFixtures::BUSINESS_TENANT_ID;

        // Create a second tenant for isolation test
        $tenantBId = '01TESTBAISO' . substr(Ulid::generate(), 11);
        $tenantBSlug = 'ba-iso-test-' . bin2hex(random_bytes(3));

        Database::execute(
            "INSERT INTO `tenants` (`id`, `name`, `slug`, `email`, `booking_pattern`, `status`, `timezone`, `show_powered_by`)
             VALUES (?, 'Isolation Tenant', ?, 'isolation@batest.test', 'timeslot', 'active', 'UTC', 1)",
            [$tenantBId, $tenantBSlug]
        );

        try {
            // Tenant A: disable powered_by
            Database::execute('UPDATE `tenants` SET `show_powered_by` = 0 WHERE `id` = ?', [$tenantA]);

            // Tenant B: keep powered_by enabled (set above to 1)
            $slugA = Database::query('SELECT `slug` FROM `tenants` WHERE `id` = ?', [$tenantA]);
            $pageA = self::httpPublic('GET', '/book/' . $slugA[0]['slug']);
            $this->assertSame(200, $pageA['code']);
            $this->assertStringNotContainsString('vb-book-footer', $pageA['body'],
                'Tenant A must NOT show footer when powered_by=0');

            $pageB = self::httpPublic('GET', '/book/' . $tenantBSlug);
            $this->assertSame(200, $pageB['code']);
            $this->assertStringContainsString('vb-book-footer', $pageB['body'],
                'Tenant B must show footer when powered_by=1 — isolation violated');

            // Restore tenant A
            Database::execute('UPDATE `tenants` SET `show_powered_by` = 1 WHERE `id` = ?', [$tenantA]);
        } finally {
            // Cleanup
            try { Database::execute('DELETE FROM `tenants` WHERE `id` = ?', [$tenantBId]); } catch (\Throwable) {}
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Settings toggle
    // ════════════════════════════════════════════════════════════════

    public function testSettingsPageShowsApplicationsToggle(): void
    {
        $res = self::httpGet('/admin/settings');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('enable_applications', $res['body'],
            'General settings must show the enable_applications toggle');
    }

    // ════════════════════════════════════════════════════════════════
    // HTTP Helpers
    // ════════════════════════════════════════════════════════════════

    private static function loginOperator(): void
    {
        $loginPage = self::httpGet('/admin/login');
        self::extractCsrf($loginPage['body']);

        $res = self::httpPost('/admin/login', [
            '_csrf_token' => self::$operatorCsrf,
            'email'       => TestFixtures::OPERATOR_EMAIL,
            'password'    => TestFixtures::OPERATOR_PASSWORD,
        ]);

        if (preg_match('/vb_session=([^;]+)/', $res['headers'], $m)) {
            self::$operatorCookie = 'vb_session=' . $m[1];
        }

        $dashboard = self::httpGet('/admin');
        self::extractCsrf($dashboard['body']);
    }

    private static function extractCsrf(string $html): void
    {
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $html, $m)) {
            self::$operatorCsrf = $m[1];
        }
    }

    private static function extractPublicCsrf(array $response): string
    {
        // Track public session cookies (PHPSESSID for public routes, vb_session for admin)
        if (preg_match('/PHPSESSID=([^;\s]+)/', $response['headers'], $m)) {
            // Append or replace PHPSESSID in the cookie string
            if (str_contains(self::$publicCookie, 'PHPSESSID=')) {
                self::$publicCookie = preg_replace('/PHPSESSID=[^;\s]+/', 'PHPSESSID=' . $m[1], self::$publicCookie);
            } else {
                self::$publicCookie = (self::$publicCookie !== '' ? self::$publicCookie . '; ' : '') . 'PHPSESSID=' . $m[1];
            }
        }
        if (preg_match('/vb_session=([^;\s]+)/', $response['headers'], $m)) {
            if (str_contains(self::$publicCookie, 'vb_session=')) {
                self::$publicCookie = preg_replace('/vb_session=[^;\s]+/', 'vb_session=' . $m[1], self::$publicCookie);
            } else {
                self::$publicCookie = (self::$publicCookie !== '' ? self::$publicCookie . '; ' : '') . 'vb_session=' . $m[1];
            }
        }
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $response['body'], $m)) {
            return $m[1];
        }
        return '';
    }

    /** @return array{code: int, headers: string, body: string} */
    private static function httpGet(string $path): array
    {
        return self::http('GET', $path, [], true);
    }

    /** @return array{code: int, headers: string, body: string} */
    private static function httpPost(string $path, array $data): array
    {
        return self::http('POST', $path, $data, true);
    }

    /** @return array{code: int, headers: string, body: string} */
    private static function httpPublic(string $method, string $path, array $data = []): array
    {
        return self::http($method, $path, $data, false);
    }

    /** @return array{code: int, headers: string, body: string} */
    private static function http(string $method, string $path, array $data = [], bool $authenticated = false): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
        ]);

        $headers = [];
        if ($authenticated && self::$operatorCookie) {
            $headers[] = 'Cookie: ' . self::$operatorCookie;
        } elseif (!$authenticated && self::$publicCookie) {
            $headers[] = 'Cookie: ' . self::$publicCookie;
        }
        if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $data = TestFixtures::withCatalogVersion($path, $data, $authenticated ? self::$operatorCookie : self::$publicCookie);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);

        $responseHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // Track session cookies for both authenticated and public flows
        if ($authenticated) {
            if (preg_match('/vb_session=([^;\s]+)/', $responseHeaders, $m)) {
                self::$operatorCookie = 'vb_session=' . $m[1];
            }
        } else {
            // Public routes may use PHPSESSID or vb_session
            if (preg_match('/PHPSESSID=([^;\s]+)/', $responseHeaders, $m)) {
                if (str_contains(self::$publicCookie, 'PHPSESSID=')) {
                    self::$publicCookie = preg_replace('/PHPSESSID=[^;\s]+/', 'PHPSESSID=' . $m[1], self::$publicCookie);
                } else {
                    self::$publicCookie = (self::$publicCookie !== '' ? self::$publicCookie . '; ' : '') . 'PHPSESSID=' . $m[1];
                }
            }
            if (preg_match('/vb_session=([^;\s]+)/', $responseHeaders, $m)) {
                if (str_contains(self::$publicCookie, 'vb_session=')) {
                    self::$publicCookie = preg_replace('/vb_session=[^;\s]+/', 'vb_session=' . $m[1], self::$publicCookie);
                } else {
                    self::$publicCookie = (self::$publicCookie !== '' ? self::$publicCookie . '; ' : '') . 'vb_session=' . $m[1];
                }
            }
        }

        return [
            'code'    => $code,
            'headers' => $responseHeaders,
            'body'    => $body,
        ];
    }
}
