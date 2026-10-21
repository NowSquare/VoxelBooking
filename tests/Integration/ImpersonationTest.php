<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for operator tenant impersonation.
 *
 * Covers:
 * - Operator can start impersonation
 * - Impersonation does not require an existing business_user row
 * - Tenant shell/banner appear while impersonating
 * - Exit impersonation returns to operator tenant list
 * - Logout while impersonating restores operator session
 * - Audit log records impersonation start/end
 * - Non-operator cannot impersonate
 */
final class ImpersonationTest extends TestCase
{
    private static bool $appReachable = false;
    private string $baseUrl;
    private string $cookieJar;

    public static function setUpBeforeClass(): void
    {
        $baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');

        $ch = curl_init($baseUrl . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 5]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($code === 0) {
            return;
        }

        self::$appReachable = true;

        try {
            TestFixtures::provision();
        } catch (\Throwable) {
            // best-effort
        }
    }

    protected function setUp(): void
    {
        if (!self::$appReachable) {
            $this->markTestSkipped('App not reachable');
        }

        $this->baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'vb_imp_test_') ?: '/tmp/vb_imp_test_cookies';
    }

    protected function tearDown(): void
    {
        if (isset($this->cookieJar) && file_exists($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Start impersonation
    // ════════════════════════════════════════════════════════════════

    public function test_operator_can_start_impersonation(): void
    {
        $this->doLoginOperator();
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;

        $csrf = $this->getCsrf();
        $r = $this->post("/admin/tenants/{$tenantId}/impersonate", ['_csrf_token' => $csrf]);

        $this->assertSame(302, $r['code'], 'Impersonation start should redirect');
        $this->assertStringContainsString(
            "/admin/tenants/{$tenantId}",
            $r['location'],
            'Should redirect to tenant dashboard'
        );
    }

    public function test_impersonation_shows_pill_in_topbar(): void
    {
        $this->doLoginOperator();
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;

        $csrf = $this->getCsrf();
        $this->post("/admin/tenants/{$tenantId}/impersonate", ['_csrf_token' => $csrf]);

        // Follow redirect to tenant dashboard
        $r = $this->get("/admin/tenants/{$tenantId}");

        $this->assertSame(200, $r['code']);

        // Pill element exists
        $this->assertStringContainsString(
            'vb-impersonation-pill',
            $r['body'],
            'Impersonation pill should be visible'
        );

        // Pill is inside .vb-topbar-right (before .vb-profile-menu)
        $this->assertMatchesRegularExpression(
            '/vb-topbar-right.*vb-impersonation-pill.*vb-profile-menu/s',
            $r['body'],
            'Pill must be inside .vb-topbar-right, before the profile menu'
        );

        // Exit form posts to the correct endpoint
        $this->assertStringContainsString(
            '/admin/impersonate/exit',
            $r['body'],
            'Exit form action should post to /admin/impersonate/exit'
        );

        // Exit button has accessible title
        $this->assertMatchesRegularExpression(
            '/class="vb-impersonation-exit-btn"[^>]*title="[^"]*[Ee]xit/',
            $r['body'],
            'Exit button should have a title attribute mentioning exit'
        );
    }

    public function test_impersonation_shows_tenant_sidebar(): void
    {
        $this->doLoginOperator();
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;

        $csrf = $this->getCsrf();
        $this->post("/admin/tenants/{$tenantId}/impersonate", ['_csrf_token' => $csrf]);

        $r = $this->get("/admin/tenants/{$tenantId}");

        // Should show tenant nav items (Calendar, Customers), not operator nav (Tenants, All Bookings)
        $this->assertStringContainsString(
            "/admin/tenants/{$tenantId}/calendar",
            $r['body'],
            'Calendar link should point to impersonated tenant'
        );
        $this->assertStringContainsString(
            "/admin/tenants/{$tenantId}/customers",
            $r['body'],
            'Customers link should point to impersonated tenant'
        );
        // Operator-level nav items should be hidden
        $this->assertStringNotContainsString(
            'href="/admin/settings"',
            $r['body'],
            'Settings link should be hidden during impersonation'
        );
    }

    public function test_impersonation_hides_account_link_in_dropdown(): void
    {
        $this->doLoginOperator();
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;

        $csrf = $this->getCsrf();
        $this->post("/admin/tenants/{$tenantId}/impersonate", ['_csrf_token' => $csrf]);

        $r = $this->get("/admin/tenants/{$tenantId}");

        $this->assertSame(200, $r['code']);
        $this->assertStringNotContainsString(
            'href="/admin/account"',
            $r['body'],
            'Account link should be hidden in dropdown during impersonation'
        );
    }

    public function test_operator_sees_account_link_without_impersonation(): void
    {
        $this->doLoginOperator();

        $r = $this->get('/admin');

        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString(
            'href="/admin/account"',
            $r['body'],
            'Account link should be visible for operators not impersonating'
        );
    }

    public function test_impersonation_does_not_require_business_user_row(): void
    {
        $this->doLoginOperator();
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;

        // Even if no business_user row exists for the operator, impersonation works
        // (it's session-based, not auth_type switching)
        $csrf = $this->getCsrf();
        $r = $this->post("/admin/tenants/{$tenantId}/impersonate", ['_csrf_token' => $csrf]);

        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString("/admin/tenants/{$tenantId}", $r['location']);
    }

    // ════════════════════════════════════════════════════════════════
    // Exit impersonation
    // ════════════════════════════════════════════════════════════════

    public function test_exit_impersonation_returns_to_tenant_list(): void
    {
        $this->doLoginOperator();
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;

        // Start impersonation
        $csrf = $this->getCsrf();
        $this->post("/admin/tenants/{$tenantId}/impersonate", ['_csrf_token' => $csrf]);

        // Exit impersonation via dedicated route
        $csrf = $this->getCsrf();
        $r = $this->post('/admin/impersonate/exit', ['_csrf_token' => $csrf]);

        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString('/admin/tenants', $r['location']);

        // After exit, operator should see normal operator dashboard
        $r = $this->get('/admin');
        $this->assertSame(200, $r['code']);
        $this->assertStringNotContainsString(
            'vb-impersonation-pill',
            $r['body'],
            'Impersonation pill should be gone after exit'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Logout during impersonation
    // ════════════════════════════════════════════════════════════════

    public function test_logout_during_impersonation_restores_operator_session(): void
    {
        $this->doLoginOperator();
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;

        // Start impersonation
        $csrf = $this->getCsrf();
        $this->post("/admin/tenants/{$tenantId}/impersonate", ['_csrf_token' => $csrf]);

        // "Logout" (should exit impersonation, not destroy session)
        $csrf = $this->getCsrf();
        $r = $this->post('/auth/logout', ['_csrf_token' => $csrf]);

        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString(
            '/admin/tenants',
            $r['location'],
            'Logout during impersonation should redirect to /admin/tenants, not /admin/login'
        );

        // Operator session should still be alive
        $r = $this->get('/admin');
        $this->assertSame(200, $r['code'], 'Operator should still be authenticated after impersonation exit');
    }

    // ════════════════════════════════════════════════════════════════
    // Non-operator cannot impersonate
    // ════════════════════════════════════════════════════════════════

    public function test_business_user_cannot_start_impersonation(): void
    {
        $this->doLoginBusinessUser();
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;

        $csrf = $this->getCsrf();
        $r = $this->post("/admin/tenants/{$tenantId}/impersonate", ['_csrf_token' => $csrf]);

        // Should redirect to /admin (not the tenant dashboard via impersonation)
        $this->assertSame(302, $r['code']);
        $this->assertStringNotContainsString(
            '/impersonate',
            $r['location'],
            'Business user should not be able to impersonate'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Audit log
    // ════════════════════════════════════════════════════════════════

    public function test_audit_log_records_impersonation_events(): void
    {
        // Clear old impersonation audit entries
        try {
            \Tests\Support\TestEnvironment::boot();
            Database::connect();
            Database::query('SELECT 1');
        } catch (\Throwable) {
            $this->markTestSkipped('DB not available');
        }

        Database::execute(
            "DELETE FROM `audit_log` WHERE `action` IN ('impersonation.started', 'impersonation.ended')"
        );

        $this->doLoginOperator();
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;

        // Start
        $csrf = $this->getCsrf();
        $this->post("/admin/tenants/{$tenantId}/impersonate", ['_csrf_token' => $csrf]);

        // Exit
        $csrf = $this->getCsrf();
        $this->post('/admin/impersonate/exit', ['_csrf_token' => $csrf]);

        // Check audit log
        $entries = Database::query(
            "SELECT `action`, `entity_type`, `entity_id`, `actor_type`, `tenant_id`
             FROM `audit_log`
             WHERE `action` IN ('impersonation.started', 'impersonation.ended')
             ORDER BY `id` ASC"
        );

        $this->assertGreaterThanOrEqual(2, count($entries), 'Should have at least 2 impersonation audit entries');

        $start = $entries[count($entries) - 2];
        $end   = $entries[count($entries) - 1];

        $this->assertSame('impersonation.started', $start['action']);
        $this->assertSame('tenant', $start['entity_type']);
        $this->assertSame($tenantId, $start['entity_id']);
        $this->assertSame('operator', $start['actor_type']);
        $this->assertSame($tenantId, $start['tenant_id']);

        $this->assertSame('impersonation.ended', $end['action']);
        $this->assertSame('tenant', $end['entity_type']);
        $this->assertSame($tenantId, $end['entity_id']);
        $this->assertSame('operator', $end['actor_type']);
    }

    // ════════════════════════════════════════════════════════════════
    // Customer deep-link impersonation (redirect_to flow)
    // ════════════════════════════════════════════════════════════════

    public function test_impersonation_redirect_to_customer_page(): void
    {
        $this->doLoginOperator();
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;
        $customerId = TestFixtures::CUSTOMER_ID;

        $csrf = $this->getCsrf();
        $redirectTo = "/admin/tenants/{$tenantId}/customers/{$customerId}";
        $r = $this->post("/admin/tenants/{$tenantId}/impersonate", [
            '_csrf_token' => $csrf,
            'redirect_to' => $redirectTo,
        ]);

        $this->assertSame(302, $r['code'], 'Should redirect');
        $this->assertStringContainsString(
            $redirectTo,
            $r['location'],
            'Should redirect to customer page via redirect_to, not default tenant dashboard'
        );

        // Follow the redirect — customer page should load with impersonation pill
        $r = $this->get($redirectTo);
        $this->assertSame(200, $r['code'], 'Customer page should load');
        $this->assertStringContainsString(
            'vb-impersonation-pill',
            $r['body'],
            'Impersonation pill should appear on the customer page'
        );
    }

    public function test_impersonation_redirect_to_rejects_external_path(): void
    {
        $this->doLoginOperator();
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;

        // Each case must fall back to the default tenant dashboard
        $malicious = [
            '/admin/settings'                                    => 'plain external path',
            "/admin/tenants/{$tenantId}/../../settings"           => 'dot-segment traversal',
            "/admin/tenants/{$tenantId}/%2e%2e/settings"          => 'URL-encoded traversal',
            "http://evil.com/admin/tenants/{$tenantId}/customers" => 'scheme injection',
            "//evil.com/admin/tenants/{$tenantId}/customers"      => 'host injection',
            "/admin/tenants/{$tenantId}-evil/customers"           => 'prefix-boundary trick',
            '/admin/tenants/OTHER_TENANT_ID/customers'            => 'cross-tenant attempt',
        ];

        foreach ($malicious as $path => $label) {
            $csrf = $this->getCsrf();
            $r = $this->post("/admin/tenants/{$tenantId}/impersonate", [
                '_csrf_token' => $csrf,
                'redirect_to' => $path,
            ]);

            $this->assertSame(302, $r['code'], "[$label] should redirect");
            $this->assertStringEndsWith(
                "/admin/tenants/{$tenantId}",
                rtrim($r['location'], '/'),
                "[$label] must fall back to tenant dashboard, not follow malicious path"
            );
        }
    }

    public function test_impersonation_redirect_creates_audit_event(): void
    {
        try {
            \Tests\Support\TestEnvironment::boot();
            Database::connect();
            Database::query('SELECT 1');
        } catch (\Throwable) {
            $this->markTestSkipped('DB not available');
        }

        // Clear old entries
        Database::execute(
            "DELETE FROM `audit_log` WHERE `action` = 'impersonation.started'"
        );

        $this->doLoginOperator();
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;
        $customerId = TestFixtures::CUSTOMER_ID;

        $csrf = $this->getCsrf();
        $this->post("/admin/tenants/{$tenantId}/impersonate", [
            '_csrf_token' => $csrf,
            'redirect_to' => "/admin/tenants/{$tenantId}/customers/{$customerId}",
        ]);

        $entries = Database::query(
            "SELECT `action`, `entity_type`, `entity_id`
             FROM `audit_log`
             WHERE `action` = 'impersonation.started'
             ORDER BY `id` DESC LIMIT 1"
        );

        $this->assertNotEmpty($entries, 'Audit entry should exist for redirect_to flow');
        $this->assertSame('impersonation.started', $entries[0]['action']);
        $this->assertSame($tenantId, $entries[0]['entity_id']);
    }

    public function test_all_bookings_renders_impersonation_forms_for_operator(): void
    {
        // Seed a guaranteed future booking so the page is never empty
        $this->ensureTestBookingExists();
        $this->doLoginOperator();

        $r = $this->get('/admin/bookings');
        $this->assertSame(200, $r['code']);

        // Operator view MUST contain impersonation forms (booking is seeded)
        $this->assertStringContainsString(
            '/impersonate',
            $r['body'],
            'All Bookings operator view must have impersonation form actions'
        );
        $this->assertStringContainsString(
            'redirect_to',
            $r['body'],
            'Impersonation forms must include redirect_to hidden input'
        );
        $this->assertStringContainsString(
            'vb-btn-reset',
            $r['body'],
            'Customer names must use button-reset styling'
        );
    }

    public function test_dashboard_renders_impersonation_forms_for_operator(): void
    {
        // Seed a guaranteed future booking so the dashboard upcoming list is never empty
        $this->ensureTestBookingExists();
        $this->doLoginOperator();

        $r = $this->get('/admin');
        $this->assertSame(200, $r['code']);

        // Dashboard upcoming list MUST contain impersonation forms (booking is seeded)
        $this->assertStringContainsString(
            '/impersonate',
            $r['body'],
            'Dashboard operator view must have impersonation form actions'
        );
        $this->assertStringContainsString(
            'redirect_to',
            $r['body'],
            'Impersonation forms must include redirect_to'
        );
    }

    // ── Helpers ──

    /**
     * Ensure a future booking exists for the test tenant/customer.
     *
     * Uses the shared TestFixtures IDs so the booking appears on
     * All Bookings and Dashboard. Idempotent via SELECT + INSERT.
     */
    private function ensureTestBookingExists(): void
    {
        try {
            \Tests\Support\TestEnvironment::boot();
            Database::connect();
        } catch (\Throwable) {
            return; // DB unavailable — test will fail on its own assertions
        }

        // Ensure customer exists
        $customer = Database::query(
            'SELECT `id` FROM `customers` WHERE `id` = ? LIMIT 1',
            [TestFixtures::CUSTOMER_ID]
        );
        if (empty($customer)) {
            Database::execute(
                "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`)
                 VALUES (?, ?, 'Test Customer', 'customer@example.com')",
                [TestFixtures::CUSTOMER_ID, TestFixtures::BUSINESS_TENANT_ID]
            );
        }

        // Ensure booking exists (future date)
        $booking = Database::query(
            'SELECT `id` FROM `bookings` WHERE `id` = ? LIMIT 1',
            [TestFixtures::BOOKING_ID]
        );
        if (empty($booking)) {
            Database::execute(
                "INSERT INTO `bookings`
                 (`id`, `tenant_id`, `booking_pattern`, `customer_id`,
                  `start_datetime`, `end_datetime`, `status`, `source`)
                 VALUES (?, ?, 'timeslot', ?,
                  DATE_ADD(CURDATE(), INTERVAL 1 DAY),
                  DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 1 DAY), INTERVAL 30 MINUTE),
                  'confirmed', 'web')",
                [TestFixtures::BOOKING_ID, TestFixtures::BUSINESS_TENANT_ID, TestFixtures::CUSTOMER_ID]
            );
        }
    }

    private function doLoginOperator(): void
    {
        $r = $this->get('/admin/login');
        preg_match('/name="_csrf_token" value="([^"]+)"/', $r['body'], $m);
        $csrf = $m[1] ?? '';

        $this->post('/admin/login', [
            'email' => TestFixtures::OPERATOR_EMAIL,
            'password' => TestFixtures::OPERATOR_PASSWORD,
            '_csrf_token' => $csrf,
        ]);
    }

    private function doLoginBusinessUser(): void
    {
        $r = $this->get('/admin/login');
        preg_match('/name="_csrf_token" value="([^"]+)"/', $r['body'], $m);
        $csrf = $m[1] ?? '';

        $this->post('/admin/login', [
            'email' => TestFixtures::BUSINESS_EMAIL,
            'password' => TestFixtures::BUSINESS_PASSWORD,
            '_csrf_token' => $csrf,
        ]);
    }

    private function getCsrf(): string
    {
        $r = $this->get('/admin');
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $r['body'], $m)) {
            return $m[1];
        }
        // Try tenant dashboard
        $r = $this->get('/admin/tenants/' . TestFixtures::BUSINESS_TENANT_ID);
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $r['body'], $m)) {
            return $m[1];
        }
        return '';
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

        return compact('code', 'body', 'location');
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
