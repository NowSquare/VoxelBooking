<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for admin routes.
 *
 * Covers operator flows, business-user flows, unauthenticated redirects,
 * and dashboard correctness. All fixtures are provisioned automatically
 * by TestFixtures::provision().
 */
final class AdminRoutesTest extends TestCase
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
        curl_close($ch);

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
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'vb_admin_test_') ?: '/tmp/vb_admin_test_cookies';

        // Clear rate limits to prevent 429s during full suite runs
        try {
            Database::execute('DELETE FROM `rate_limits`');
        } catch (\Throwable) {
            // Table may not exist; not critical
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->cookieJar) && file_exists($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Operator: page access
    // ════════════════════════════════════════════════════════════════

    public function test_operator_bookings_list_returns_200(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/bookings');
        $this->assertSame(200, $r['code'], 'Bookings list should be accessible');
        $this->assertStringContainsString('Bookings', $r['body']);
    }

    public function test_operator_tenants_list_returns_200(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants');
        $this->assertSame(200, $r['code'], 'Tenants list should be accessible');
        $this->assertStringContainsString('Businesses', $r['body']);
    }

    public function test_operator_tenant_create_returns_200(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants/create');
        $this->assertSame(200, $r['code'], 'Create tenant form should be accessible');
    }

    public function test_tenants_search_includes_matching_tenant(): void
    {
        $this->doLoginOperator();
        // Fixture tenant is named "Test Tenant" — search for part of it
        $r = $this->get('/admin/tenants?search=' . urlencode('Test Tenant'));
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('test-fixture', $r['body'], 'Matching tenant slug must appear in search results');
        $this->assertStringContainsString('vb-table', $r['body'], 'Matching search must show the table');
    }

    public function test_tenants_search_excludes_nonmatching_tenants(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants?search=' . urlencode('zzznonexistent999'));
        $this->assertSame(200, $r['code']);
        // Fixture tenant row must not appear
        $this->assertStringNotContainsString('test-fixture', $r['body'], 'Non-matching tenant must be excluded from results');
        // Filtered-empty message instead of table
        $this->assertStringNotContainsString('vb-table-wrap', $r['body'], 'Must not render table for zero results');
        $this->assertStringContainsString('No businesses found', $r['body']);
    }

    public function test_tenants_status_filter_active_includes_fixture(): void
    {
        $this->doLoginOperator();
        // Fixture tenant has status=active
        $r = $this->get('/admin/tenants?status=active');
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('test-fixture', $r['body'], 'Active fixture tenant must appear under active filter');
        $this->assertStringContainsString('vb-filter-tab active', $r['body'], 'Active tab must be highlighted');
    }

    public function test_tenants_status_filter_archived_excludes_active_fixture(): void
    {
        $this->doLoginOperator();
        // Fixture tenant has status=active, so filtering by archived must exclude it
        $r = $this->get('/admin/tenants?status=archived');
        $this->assertSame(200, $r['code']);
        $this->assertStringNotContainsString('test-fixture', $r['body'], 'Active tenant must not appear under archived filter');
    }

    public function test_tenants_filtered_empty_shows_toolbar_and_message(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants?search=' . urlencode('zzznonexistent999'));
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('vb-filter-tabs', $r['body'], 'Filter tabs must remain visible when search returns zero rows');
        $this->assertStringContainsString('No businesses found', $r['body'], 'Filtered-empty must show "No businesses found"');
        $this->assertStringNotContainsString('No businesses yet', $r['body'], 'Must not show true zero-state when search is active');
        // Scoped result-count must render even when zero rows match
        $this->assertStringContainsString('vb-table-result-count', $r['body'], 'Scoped result-count row must render for filtered-empty');
        $this->assertStringContainsString('No businesses', $r['body'], 'Zero count must show "No businesses"');
        $this->assertStringContainsString('vb-table-active-filter-dot', $r['body'], 'Active filter dot must appear for filtered-empty');
    }

    public function test_tenants_search_renders_csp_safe_clear_button(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants?search=' . urlencode('Test'));
        $this->assertSame(200, $r['code']);
        // Shared table-search partial must use CSP-safe Alpine component
        $this->assertStringContainsString('x-data="tableSearch"', $r['body'], 'Search form must use registered tableSearch component');
        $this->assertStringContainsString('x-ref="searchInput"', $r['body'], 'Search input must have x-ref for clear method');
        $this->assertStringContainsString('vb-table-search-clear', $r['body'], 'Clear button must be rendered');
        $this->assertStringContainsString('data-initial="Test"', $r['body'], 'data-initial must carry the search value');
    }

    public function test_tenants_result_count_uses_proper_pluralization(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants?search=' . urlencode('Test Tenant'));
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('vb-table-result-count', $r['body'], 'Result count row must render');
        $this->assertStringContainsString('1 business', $r['body'], 'Singular count must read "1 business" not "1 businesses"');
        $this->assertStringContainsString('vb-table-active-filter-dot', $r['body'], 'Active filter dot must appear when search is set');
    }

    public function test_tenants_empty_search_returns_unfiltered_results(): void
    {
        $this->doLoginOperator();
        // Empty search= (post-clear fallback) must behave like no filter
        $filtered = $this->get('/admin/tenants?search=');
        $unfiltered = $this->get('/admin/tenants');
        $this->assertSame(200, $filtered['code']);
        $this->assertSame(200, $unfiltered['code']);
        // Both must show the fixture tenant
        $this->assertStringContainsString('test-fixture', $filtered['body'], 'Empty search must show all tenants');
        $this->assertStringContainsString('test-fixture', $unfiltered['body'], 'Unfiltered must show all tenants');
        // Neither should show the filtered-empty state
        $this->assertStringNotContainsString('No businesses found', $filtered['body'], 'Empty search must not trigger filtered-empty');
        $this->assertStringNotContainsString('No businesses found', $unfiltered['body']);
    }

    public function test_tenants_clear_button_url_excludes_search_param(): void
    {
        $this->doLoginOperator();
        // When search is active, the form's action should be the base URL
        $r = $this->get('/admin/tenants?search=Test&status=active');
        $this->assertSame(200, $r['code']);
        // The form action must be the base tenants URL (clear navigates to action + hidden fields)
        $this->assertStringContainsString('action="/admin/tenants"', $r['body'], 'Search form action must be clean base URL');
        // The status hidden field must be preserved for clear to carry it
        $this->assertStringContainsString('name="status"', $r['body'], 'Status hidden field name present');
        $this->assertStringContainsString('value="active"', $r['body'], 'Status hidden field value present');
    }

    public function test_operator_tenant_dashboard_returns_200(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants/' . TestFixtures::BUSINESS_TENANT_ID);
        $this->assertSame(200, $r['code'], 'Tenant dashboard should be accessible');
    }

    public function test_operator_tenant_dashboard_redirects_for_invalid_tenant(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants/01NONEXISTENT00000000000');
        $this->assertSame(302, $r['code'], 'Invalid tenant should redirect');
    }

    public function test_operator_tenant_bookings_returns_200(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants/' . TestFixtures::BUSINESS_TENANT_ID . '/bookings');
        $this->assertSame(200, $r['code'], 'Tenant bookings list should be accessible');
    }

    public function test_bookings_filtered_empty_shows_toolbar_and_no_results_message(): void
    {
        $this->doLoginOperator();

        // Use impossible status value to guarantee zero results while keeping filters active
        $r = $this->get('/admin/tenants/' . TestFixtures::BUSINESS_TENANT_ID . '/bookings?status=no_show&from=2099-01-01&to=2099-01-02');
        $this->assertSame(200, $r['code'], 'Filtered bookings page must return 200');

        // Toolbar (filter tabs) must remain visible so the user can clear/change filters
        $this->assertStringContainsString('vb-filter-tabs', $r['body'], 'Filter tabs must be visible when filters return zero rows');

        // Filtered-empty message must appear, not the true zero-state
        $this->assertStringContainsString('No bookings found', $r['body'], 'Filtered-empty must show "No bookings found"');
        $this->assertStringNotContainsString('No bookings yet', $r['body'], 'Must not show true zero-state when filters are active');
    }

    public function test_bookings_uses_shared_sort_header(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/bookings');
        $this->assertSame(200, $r['code']);
        // Shared sort header partial must render sortable columns
        $this->assertStringContainsString('vb-th-sort', $r['body'], 'Shared sort header must render sortable columns');
        // Inline bookingSortHeader function must not appear in output
        $this->assertStringNotContainsString('bookingSortHeader', $r['body'], 'Inline bookingSortHeader must not appear in output');
    }

    public function test_operator_settings_returns_200(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/settings');
        $this->assertSame(200, $r['code'], 'Settings should be accessible for operators');
    }

    // ════════════════════════════════════════════════════════════════
    // Document title contract: browser tab titles must be page-specific
    // ════════════════════════════════════════════════════════════════

    public function test_tenant_create_document_title_is_page_specific(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants/create');
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString(
            '<title>New Business',
            $r['body'],
            'Browser tab should say "New Business", not just "Businesses"'
        );
    }

    public function test_tenant_edit_document_title_is_page_specific(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants/' . TestFixtures::BUSINESS_TENANT_ID . '/edit');
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString(
            '<title>Edit Business',
            $r['body'],
            'Browser tab should say "Edit Business", not just "Businesses"'
        );
    }

    public function test_booking_detail_document_title_is_page_specific(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/bookings/' . TestFixtures::BOOKING_ID);
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString(
            '<title>Booking Details',
            $r['body'],
            'Browser tab should say "Booking Details", not just "Bookings"'
        );
    }

    public function test_tenant_list_document_title_is_section_name(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants');
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString(
            '<title>Businesses',
            $r['body'],
            'Business list tab should say "Businesses"'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Business user: redirect, allowed access, and denied access
    // ════════════════════════════════════════════════════════════════

    public function test_business_user_admin_redirects_to_tenant_dashboard(): void
    {
        $this->doLoginBusinessUser();
        $r = $this->get('/admin');
        $this->assertSame(302, $r['code'], 'Business user on /admin should be redirected');
        $this->assertStringContainsString(
            '/admin/tenants/' . TestFixtures::BUSINESS_TENANT_ID,
            $r['location'],
            'Redirect should point to the business user\'s tenant dashboard'
        );
    }

    public function test_business_user_tenant_dashboard_returns_200(): void
    {
        $this->doLoginBusinessUser();
        $r = $this->get('/admin/tenants/' . TestFixtures::BUSINESS_TENANT_ID);
        $this->assertSame(200, $r['code'], 'Business user should access own tenant dashboard');
    }

    public function test_business_user_tenant_bookings_returns_200(): void
    {
        $this->doLoginBusinessUser();
        $r = $this->get('/admin/tenants/' . TestFixtures::BUSINESS_TENANT_ID . '/bookings');
        $this->assertSame(200, $r['code'], 'Business user should access own tenant bookings');
    }

    public function test_business_user_settings_returns_403(): void
    {
        $this->doLoginBusinessUser();
        $r = $this->get('/admin/settings');
        $this->assertSame(403, $r['code'], 'Business user should be denied operator-only settings');
    }

    public function test_business_user_account_returns_200(): void
    {
        $this->doLoginBusinessUser();
        $r = $this->get('/admin/account');
        $this->assertSame(200, $r['code'], 'Business user should access standalone account page');
    }

    public function test_business_user_other_tenant_returns_403(): void
    {
        $this->doLoginBusinessUser();
        // Access a tenant that is NOT theirs
        $r = $this->get('/admin/tenants/01SOMEOTHERTENANT0000000');
        $this->assertSame(403, $r['code'], 'Business user should be denied access to other tenants');
    }

    // ════════════════════════════════════════════════════════════════
    // Bookings CSV Export
    // ════════════════════════════════════════════════════════════════

    public function test_operator_bookings_export_returns_csv(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/bookings/export');
        $this->assertSame(200, $r['code'], 'Export should return 200');
        $h = strtolower($r['headers']); // HTTP/2 lowercases header names
        $this->assertStringContainsString('text/csv', $h, 'Content-Type must be text/csv');
        $this->assertStringContainsString('content-disposition:', $h, 'Must have Content-Disposition');
        $this->assertStringContainsString('bookings-export-', $h, 'Filename must include prefix');
        $this->assertStringContainsString('.csv', $h, 'Filename must end in .csv');
        // CSV header row
        $this->assertStringContainsString('Date,Time,', $r['body'], 'CSV must contain header row');
        $this->assertStringContainsString(',Business', $r['body'], 'Operator export must include Business column');
    }

    public function test_operator_bookings_export_respects_status_filter(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/bookings/export?status=confirmed');
        $this->assertSame(200, $r['code']);
        // Every data row must be confirmed (skip BOM + header line)
        $lines = array_filter(explode("\n", trim($r['body'])), fn($l) => $l !== '' && !str_starts_with($l, 'Date,'));
        foreach ($lines as $line) {
            // Remove BOM if present
            $line = ltrim($line, "\xEF\xBB\xBF");
            if (str_starts_with($line, 'Date,')) continue;
            $this->assertStringContainsString('confirmed', strtolower($line), 'Filtered export must only contain confirmed bookings');
        }
    }

    public function test_tenant_bookings_export_returns_csv_without_tenant_column(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants/' . TestFixtures::BUSINESS_TENANT_ID . '/bookings/export');
        $this->assertSame(200, $r['code'], 'Tenant export should return 200');
        $h = strtolower($r['headers']);
        $this->assertStringContainsString('text/csv', $h);
        // Tenant column must NOT be in header for tenant-scoped export
        $headerLine = strtok(ltrim($r['body'], "\xEF\xBB\xBF"), "\n");
        $this->assertStringNotContainsString('Business', $headerLine, 'Tenant-scoped export must not include Business column');
    }

    public function test_unauthenticated_export_redirects_to_login(): void
    {
        $r = $this->get('/admin/bookings/export');
        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString('/admin/login', $r['location']);
    }

    // ════════════════════════════════════════════════════════════════
    // Tenants export
    // ════════════════════════════════════════════════════════════════

    public function test_operator_tenants_export_returns_csv(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants/export');
        $this->assertSame(200, $r['code'], 'Tenants export should return 200');
        $h = strtolower($r['headers']);
        $this->assertStringContainsString('text/csv', $h, 'Content-Type must be text/csv');
        $this->assertStringContainsString('content-disposition:', $h, 'Must have Content-Disposition');
        $this->assertStringContainsString('tenants-export-', $h, 'Filename must include prefix');
        // CSV header row must include count columns that match the visible table
        $headerLine = strtok(ltrim($r['body'], "\xEF\xBB\xBF"), "\n");
        $this->assertStringContainsString('Name', $headerLine, 'CSV must contain Name column');
        $this->assertStringContainsString('Slug', $headerLine, 'CSV must contain Slug column');
        $this->assertStringContainsString('Status', $headerLine, 'CSV must contain Status column');
        $this->assertStringContainsString('Bookings', $headerLine, 'CSV must contain Bookings column');
        $this->assertStringContainsString('Services', $headerLine, 'CSV must contain Services column');
    }

    public function test_tenants_export_respects_status_filter(): void
    {
        $this->doLoginOperator();
        // The test tenant is active, so filtering for archived should exclude it
        $r = $this->get('/admin/tenants/export?status=archived');
        $this->assertSame(200, $r['code']);
        $body = ltrim($r['body'], "\xEF\xBB\xBF");
        $lines = array_filter(explode("\n", trim($body)), fn($l) => $l !== '');
        // Only header line, no data rows (test tenant is active, not archived)
        $this->assertCount(1, $lines, 'Archived filter must exclude active test tenant');
    }

    // ════════════════════════════════════════════════════════════════
    // Customers export
    // ════════════════════════════════════════════════════════════════

    public function test_tenant_customers_export_returns_csv(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/tenants/' . TestFixtures::BUSINESS_TENANT_ID . '/customers/export');
        $this->assertSame(200, $r['code'], 'Customers export should return 200');
        $h = strtolower($r['headers']);
        $this->assertStringContainsString('text/csv', $h, 'Content-Type must be text/csv');
        $this->assertStringContainsString('content-disposition:', $h, 'Must have Content-Disposition');
        $this->assertStringContainsString('customers-export-', $h, 'Filename must include prefix');
        // CSV header row
        $headerLine = strtok(ltrim($r['body'], "\xEF\xBB\xBF"), "\n");
        $this->assertStringContainsString('Name', $headerLine);
        $this->assertStringContainsString('Email', $headerLine);
        $this->assertStringContainsString('Bookings', $headerLine);
    }

    public function test_customers_export_respects_search_filter(): void
    {
        $this->doLoginOperator();
        // Search for a nonexistent customer should yield header-only
        $r = $this->get('/admin/tenants/' . TestFixtures::BUSINESS_TENANT_ID . '/customers/export?search=zzz_no_match_zzz');
        $this->assertSame(200, $r['code']);
        $body = ltrim($r['body'], "\xEF\xBB\xBF");
        $lines = array_filter(explode("\n", trim($body)), fn($l) => $l !== '');
        $this->assertCount(1, $lines, 'Non-matching search must yield header-only CSV');
    }

    // ════════════════════════════════════════════════════════════════
    // Audit log export
    // ════════════════════════════════════════════════════════════════

    public function test_operator_audit_export_returns_csv(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/settings/audit/export');
        $this->assertSame(200, $r['code'], 'Audit export should return 200');
        $h = strtolower($r['headers']);
        $this->assertStringContainsString('text/csv', $h, 'Content-Type must be text/csv');
        $this->assertStringContainsString('content-disposition:', $h, 'Must have Content-Disposition');
        $this->assertStringContainsString('audit-log-export-', $h, 'Filename must include prefix');
        // CSV header row
        $headerLine = strtok(ltrim($r['body'], "\xEF\xBB\xBF"), "\n");
        $this->assertStringContainsString('Action', $headerLine);
        $this->assertStringContainsString('Entity Type', $headerLine);
        $this->assertStringContainsString('Actor Type', $headerLine);
    }

    public function test_audit_export_respects_action_filter(): void
    {
        $this->doLoginOperator();
        // Filter for auth.login only — every row must contain auth.login
        $r = $this->get('/admin/settings/audit/export?action=auth.login');
        $this->assertSame(200, $r['code']);
        $body = ltrim($r['body'], "\xEF\xBB\xBF");
        $lines = array_filter(explode("\n", trim($body)), fn($l) => $l !== '');
        // Skip header
        $dataLines = array_slice($lines, 1);
        foreach ($dataLines as $line) {
            $this->assertStringContainsString('auth.login', $line, 'Filtered audit export must only contain auth.login rows');
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Unauthenticated access redirects
    // ════════════════════════════════════════════════════════════════

    public function test_unauthenticated_bookings_redirects_to_login(): void
    {
        $r = $this->get('/admin/bookings');
        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString('/admin/login', $r['location']);
    }

    public function test_unauthenticated_tenants_redirects_to_login(): void
    {
        $r = $this->get('/admin/tenants');
        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString('/admin/login', $r['location']);
    }

    // ════════════════════════════════════════════════════════════════
    // Dashboard correctness: bounded counts and nearest-first
    // ════════════════════════════════════════════════════════════════

    public function test_operator_dashboard_shows_bounded_metrics(): void
    {
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;
        $customerId = $this->ensureTestCustomer($tenantId);

        $todayStart = date('Y-m-d 10:00:00');
        $todayEnd = date('Y-m-d 10:30:00');
        $oldStart = date('Y-m-d 10:00:00', strtotime('-30 days'));
        $oldEnd = date('Y-m-d 10:30:00', strtotime('-30 days'));

        // Clean all bookings except the test fixture booking
        Database::execute("DELETE FROM `bookings` WHERE `id` != ?", [TestFixtures::BOOKING_ID]);

        // Insert today's booking (should appear in both Today and This Week)
        Database::execute(
            "INSERT INTO `bookings` (`id`, `tenant_id`, `booking_pattern`, `customer_id`, `start_datetime`, `end_datetime`, `status`, `source`)
             VALUES (?, ?, 'timeslot', ?, ?, ?, 'confirmed', 'web')",
            ['01TESTBKTODAY00000000000', $tenantId, $customerId, $todayStart, $todayEnd]
        );

        // Insert old booking (30 days ago — outside the 7-day week window)
        Database::execute(
            "INSERT INTO `bookings` (`id`, `tenant_id`, `booking_pattern`, `customer_id`, `start_datetime`, `end_datetime`, `status`, `source`)
             VALUES (?, ?, 'timeslot', ?, ?, ?, 'confirmed', 'web')",
            ['01TESTBKOLD0000000000000', $tenantId, $customerId, $oldStart, $oldEnd]
        );

        $this->doLoginOperator();
        $r = $this->get('/admin');
        $this->assertSame(200, $r['code']);

        // Extract "Bookings Today" metric value
        $todayMatch = preg_match('/Bookings Today.*?vb-metric-value[^>]*>(\d+)/s', $r['body'], $mToday);
        $this->assertSame(1, $todayMatch, 'Should find Bookings Today metric');
        $todayCount = (int) ($mToday[1] ?? 0);
        $this->assertGreaterThanOrEqual(1, $todayCount, 'Bookings Today should be >= 1');

        // Extract "This Week" metric value
        $weekMatch = preg_match('/This Week.*?vb-metric-value[^>]*>(\d+)/s', $r['body'], $mWeek);
        $this->assertSame(1, $weekMatch, 'Should find This Week metric');
        $weekCount = (int) ($mWeek[1] ?? 0);

        // The 30-day-old booking must be excluded from This Week.
        // If both test bookings were counted, weekCount would be todayCount + 1.
        $this->assertSame(
            $todayCount,
            $weekCount,
            'This Week should equal Bookings Today — the 30-day-old booking must be excluded from the 7-day window'
        );
    }

    public function test_tenant_dashboard_shows_nearest_first_upcoming(): void
    {
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;
        $customerId = $this->ensureTestCustomer($tenantId);

        // Clean prior test bookings
        Database::execute("DELETE FROM `bookings` WHERE `id` LIKE '01TESTBK%'");

        $futureNear = date('Y-m-d 09:00:00', strtotime('+1 day'));
        $futureNearEnd = date('Y-m-d 09:30:00', strtotime('+1 day'));
        $futureFar = date('Y-m-d 14:00:00', strtotime('+5 days'));
        $futureFarEnd = date('Y-m-d 14:30:00', strtotime('+5 days'));

        // Insert far booking first (to confirm sort order, not insertion order)
        Database::execute(
            "INSERT INTO `bookings` (`id`, `tenant_id`, `booking_pattern`, `customer_id`, `start_datetime`, `end_datetime`, `status`, `source`)
             VALUES (?, ?, 'timeslot', ?, ?, ?, 'confirmed', 'web')",
            ['01TESTBKFAR0000000000000', $tenantId, $customerId, $futureFar, $futureFarEnd]
        );

        // Insert near booking second
        Database::execute(
            "INSERT INTO `bookings` (`id`, `tenant_id`, `booking_pattern`, `customer_id`, `start_datetime`, `end_datetime`, `status`, `source`)
             VALUES (?, ?, 'timeslot', ?, ?, ?, 'confirmed', 'web')",
            ['01TESTBKNEAR000000000000', $tenantId, $customerId, $futureNear, $futureNearEnd]
        );

        $this->doLoginOperator();
        $r = $this->get('/admin/tenants/' . $tenantId);
        $this->assertSame(200, $r['code']);

        // "Next Up" should show the near booking before the far booking.
        // Match on the booking links: the displayed date follows the locale's
        // date notation, so it cannot be predicted here.
        $nearPos = strpos($r['body'], '/bookings/01TESTBKNEAR000000000000');
        $farPos = strpos($r['body'], '/bookings/01TESTBKFAR0000000000000');

        $this->assertNotFalse($nearPos, 'Near booking should appear on tenant dashboard');
        $this->assertNotFalse($farPos, 'Far booking should appear on tenant dashboard');
        $this->assertLessThan($farPos, $nearPos, 'Nearest booking should appear before the far one (ASC sort)');
    }

    // ── Helpers ──

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

    private function doLoginBusinessUser(): void
    {
        $response = $this->get('/admin/login');
        preg_match('/name="_csrf_token" value="([^"]+)"/', $response['body'], $m);
        $csrf = $m[1] ?? '';

        $this->post('/admin/login', [
            'email' => TestFixtures::BUSINESS_EMAIL,
            'password' => TestFixtures::BUSINESS_PASSWORD,
            '_csrf_token' => $csrf,
        ]);
    }

    /**
     * Ensure a test customer exists for booking seed data.
     * Uses DELETE + INSERT for deterministic state.
     */
    private function ensureTestCustomer(string $tenantId): string
    {
        $id = '01TESTCUSTOMER0000000000';
        Database::execute(
            "DELETE FROM `customers` WHERE `id` = ? OR `email` = 'customer@example.com'",
            [$id]
        );
        Database::execute(
            "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`) VALUES (?, ?, 'Test Customer', 'customer@example.com')",
            [$id, $tenantId]
        );
        return $id;
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
