<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\Mailer;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * HTTP-level integration tests for the public booking flow.
 *
 * Covers:
 * - GET /book/{slug}           → booking page shell loads
 * - GET /book/{slug}           → 404 for missing/inactive tenant
 * - GET /api/{slug}/services   → returns active services
 * - GET /api/{slug}/staff      → returns filtered staff
 * - GET /api/{slug}/availability → returns slots for a date
 * - GET /api/{slug}/available-dates → returns available dates
 * - POST /api/{slug}/bookings  → creates booking with consent evidence
 * - POST /api/{slug}/bookings  → rejects duplicate booking (409)
 * - POST /api/{slug}/bookings  → validates required fields
 * - Config blob                → affordance flags, cancellation policy,
 *                                confirmation message, gating when disabled
 * - GET /api/{slug}/services   → includes preparation_text in response
 * - Page shell                 → preparationText hooks for review callout
 *
 * Deterministic: reachability is checked once in setUpBeforeClass,
 * not per-test. Rate limit records for the test IP are cleared
 * before the class runs.
 */
final class BookingFlowTest extends TestCase
{
    private string $baseUrl;
    private array $cleanupIds = [];
    private ?string $csrfCookieFile = null;

    private static bool $appReachable = false;
    private static bool $dbReady = false;

    /** @var array{slug: string, tenant_id: string, customer_email: string, service_id: string, staff_id: string} */
    private static array $seed = [];

    /**
     * One-time reachability + DB + seed. No per-test /health hits.
     */
    public static function setUpBeforeClass(): void
    {
        $baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');

        // Single reachability check
        $ch = curl_init($baseUrl . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Accept 200 or 429 — the app is running either way
        if ($code === 0) {
            return; // App unreachable, tests will skip per setUp
        }

        self::$appReachable = true;

        // DB connection
        try {
            require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
            EnvLoader::load(dirname(__DIR__, 2) . '/.env');
            Database::connect();
            Database::query('SELECT 1');
            self::$dbReady = true;
        } catch (\Throwable) {
            return;
        }

        // Clear ALL rate limit records so the test suite starts with a clean quota
        try {
            Database::execute('TRUNCATE TABLE `rate_limits`');
        } catch (\Throwable) {
            // table may not exist
        }

        // Seed test data
        self::$seed = self::seedTestTenant();
    }

    protected function setUp(): void
    {
        $this->baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');

        if (!self::$appReachable) {
            $this->markTestSkipped('App not reachable at ' . $this->baseUrl);
        }
        if (!self::$dbReady) {
            $this->markTestSkipped('Database not available');
        }
        if (empty(self::$seed)) {
            $this->markTestSkipped('Test seed not available');
        }

        // Clear rate limits before each test to prevent cross-test throttling
        try {
            Database::execute('TRUNCATE TABLE `rate_limits`');
        } catch (\Throwable) {
            // best-effort
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanupIds) as [$table, $id]) {
            try {
                Database::execute("DELETE FROM `{$table}` WHERE `id` = ?", [$id]);
            } catch (\Throwable) {
                // Best-effort
            }
        }

        // Clean up CSRF cookie files
        if ($this->csrfCookieFile && file_exists($this->csrfCookieFile)) {
            @unlink($this->csrfCookieFile);
            $this->csrfCookieFile = null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (empty(self::$seed)) {
            return;
        }

        try {
            Database::execute('DELETE FROM `service_staff` WHERE `service_id` = ?', [self::$seed['service_id']]);
            Database::execute('DELETE FROM `availability` WHERE `tenant_id` = ?', [self::$seed['tenant_id']]);
            Database::execute('DELETE FROM `bookings` WHERE `tenant_id` = ?', [self::$seed['tenant_id']]);
            Database::execute('DELETE FROM `customers` WHERE `tenant_id` = ?', [self::$seed['tenant_id']]);
            Database::execute('DELETE FROM `staff` WHERE `tenant_id` = ?', [self::$seed['tenant_id']]);
            Database::execute('DELETE FROM `services` WHERE `tenant_id` = ?', [self::$seed['tenant_id']]);
            Database::execute('DELETE FROM `tenants` WHERE `id` = ?', [self::$seed['tenant_id']]);
        } catch (\Throwable) {
            // Best-effort
        }

        self::$seed = [];
    }

    // ════════════════════════════════════════════════════════════════
    // Page load tests
    // ════════════════════════════════════════════════════════════════

    public function testBookingPageLoads(): void
    {
        $res = $this->httpGet('/book/' . self::$seed['slug']);

        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('Booking Flow Test', $res['body'], 'Page must contain tenant name');
        $this->assertStringContainsString('__VB_CONFIG__', $res['body'], 'Page must inject config blob');
        $this->assertStringContainsString('booking.js', $res['body'], 'Page must load booking.js');
        $this->assertStringContainsString('booking-css.css', $res['body'], 'Page must load booking CSS');
    }

    public function testBookingPageConfigContainsBrandColor(): void
    {
        $res = $this->httpGet('/book/' . self::$seed['slug']);

        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('"brand_color":"#3B82F6"', $res['body']);
    }

    public function testBookingPage404ForMissingSlugs(): void
    {
        $res = $this->httpGet('/book/nonexistent-test-tenant-xyz');

        $this->assertSame(404, $res['code']);
        $this->assertStringContainsString('booking-css.css', $res['body'], '404 must use booking design system');
        $this->assertStringContainsString('not found', strtolower($res['body']));
    }

    /**
     * Regression: timezone picker is driven by canonical PHP timezone source,
     * not a hardcoded JS subset. Must include UTC and non-curated zones.
     */
    public function testBookingPageTimezoneGroupsFromCanonicalSource(): void
    {
        $res = $this->httpGet('/book/' . self::$seed['slug']);
        $this->assertSame(200, $res['code']);

        // __VB_TZ_GROUPS__ must be injected
        $this->assertStringContainsString('__VB_TZ_GROUPS__', $res['body'],
            'Booking page must inject __VB_TZ_GROUPS__ from server');

        // Must contain UTC (in "Other" group)
        $this->assertStringContainsString('"UTC"', $res['body'],
            'Timezone groups must include UTC');

        // Must contain a non-curated zone that the old hardcoded list omitted
        $this->assertStringContainsString('"America/Winnipeg"', $res['body'],
            'Timezone groups must include non-curated zones from the canonical source');

        // Must contain zones from multiple continents
        $this->assertStringContainsString('"Europe/Amsterdam"', $res['body'],
            'Timezone groups must include Europe/Amsterdam');
        $this->assertStringContainsString('"Asia/Tokyo"', $res['body'],
            'Timezone groups must include Asia/Tokyo');
        $this->assertStringContainsString('"Africa/Nairobi"', $res['body'],
            'Timezone groups must include Africa/Nairobi');
    }

    /**
     * Regression: a non-blue brand_color tenant produces matching --vb-brand
     * tokens in the rendered booking page HTML, not the default blue.
     */
    public function testBookingPageNonBlueBrandEmitsMatchingTokens(): void
    {
        // Temporarily set brand to rose
        Database::execute(
            'UPDATE `tenants` SET `brand_color` = ? WHERE `id` = ?',
            ['#E11D48', self::$seed['tenant_id']]
        );

        try {
            $res = $this->httpGet('/book/' . self::$seed['slug']);
            $this->assertSame(200, $res['code']);

            // Inline style must contain the rose brand, not the default blue
            $this->assertStringContainsString('--vb-brand: #E11D48', $res['body'],
                'Rendered page must emit tenant-specific --vb-brand token');
            $this->assertStringNotContainsString('--vb-brand: #2563EB', $res['body'],
                'Rendered page must not contain default blue when tenant uses non-blue brand');

            // brand-rgb must match rose
            $this->assertStringContainsString('--vb-brand-rgb: 225, 29, 72', $res['body'],
                'Rendered page must emit matching --vb-brand-rgb for rose');
        } finally {
            // Restore original blue
            Database::execute(
                'UPDATE `tenants` SET `brand_color` = ? WHERE `id` = ?',
                ['#3B82F6', self::$seed['tenant_id']]
            );
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Services API
    // ════════════════════════════════════════════════════════════════

    public function testServicesEndpointReturnsActiveServices(): void
    {
        $res = $this->httpGetJson('/api/' . self::$seed['slug'] . '/services');

        $this->assertSame(200, $res['code'], 'Services endpoint must return 200. Body: ' . $res['body']);
        $data = json_decode($res['body'], true);

        $this->assertArrayHasKey('services', $data);
        $this->assertNotEmpty($data['services'], 'Should return at least one service');

        $service = $data['services'][0];
        $this->assertArrayHasKey('id', $service);
        $this->assertArrayHasKey('name', $service);
        $this->assertArrayHasKey('duration_minutes', $service);
        $this->assertArrayHasKey('price', $service);
    }

    public function testServicesEndpoint404ForBadSlug(): void
    {
        $res = $this->httpGetJson('/api/no-such-tenant/services');

        $this->assertSame(404, $res['code']);
        $data = json_decode($res['body'], true);
        $this->assertSame('tenant_not_found', $data['error'] ?? '');
    }

    // ════════════════════════════════════════════════════════════════
    // Staff API
    // ════════════════════════════════════════════════════════════════

    public function testStaffEndpointReturnsAllStaff(): void
    {
        $res = $this->httpGetJson('/api/' . self::$seed['slug'] . '/staff');

        $this->assertSame(200, $res['code'], 'Staff endpoint must return 200. Body: ' . $res['body']);
        $data = json_decode($res['body'], true);

        $this->assertArrayHasKey('staff', $data);
        $this->assertNotEmpty($data['staff']);

        $member = $data['staff'][0];
        $this->assertArrayHasKey('id', $member);
        $this->assertArrayHasKey('name', $member);
    }

    public function testStaffFilteredByService(): void
    {
        $res = $this->httpGetJson('/api/' . self::$seed['slug'] . '/staff?service_id=' . self::$seed['service_id']);

        $this->assertSame(200, $res['code'], 'Filtered staff must return 200. Body: ' . $res['body']);
        $data = json_decode($res['body'], true);

        $this->assertNotEmpty($data['staff'] ?? [], 'Service-staff pivot should return linked staff');
    }

    // ════════════════════════════════════════════════════════════════
    // Availability API
    // ════════════════════════════════════════════════════════════════

    public function testAvailabilityEndpointReturnsSlotsForWeekday(): void
    {
        $nextMon = new \DateTimeImmutable('next Monday');
        $date = $nextMon->format('Y-m-d');

        $res = $this->httpGetJson('/api/' . self::$seed['slug'] . '/availability?date=' . $date);

        $this->assertSame(200, $res['code'], 'Availability must return 200. Body: ' . $res['body']);
        $data = json_decode($res['body'], true);

        $this->assertArrayHasKey('slots', $data);
        $this->assertNotEmpty($data['slots'], 'Monday should have available slots');

        foreach ($data['slots'] as $slot) {
            $this->assertArrayHasKey('time', $slot);
            $this->assertArrayHasKey('end_time', $slot);
        }
    }

    public function testAvailabilityRejectsInvalidDate(): void
    {
        $res = $this->httpGetJson('/api/' . self::$seed['slug'] . '/availability?date=invalid');

        $this->assertSame(400, $res['code']);
    }

    public function testAvailableDatesEndpointReturnsDatesArray(): void
    {
        $now = new \DateTimeImmutable();
        $year = $now->format('Y');
        $month = $now->format('n');

        $res = $this->httpGetJson('/api/' . self::$seed['slug'] . "/available-dates?year={$year}&month={$month}");

        $this->assertSame(200, $res['code'], 'Available dates must return 200. Body: ' . $res['body']);
        $data = json_decode($res['body'], true);

        $this->assertArrayHasKey('dates', $data);
        $this->assertIsArray($data['dates']);
    }

    // ════════════════════════════════════════════════════════════════
    // Booking creation — happy path
    // ════════════════════════════════════════════════════════════════

    public function testCreateBookingSucceeds(): void
    {
        $slot = $this->getFirstAvailableSlot('next Monday');

        $payload = [
            'service_id'     => self::$seed['service_id'],
            'staff_id'       => self::$seed['staff_id'],
            'start_datetime' => $slot['date'] . 'T' . $slot['time'] . ':00',
            'customer'       => [
                'name'  => 'Integration Tester',
                'email' => 'booking-test-' . substr(Ulid::generate(), -6) . '@example.com',
            ],
            'consent_given' => true,
            'notes'         => 'Integration test booking',
            '__ts'          => (time() - 10) * 1000,
        ];

        $csrf = $this->fetchCsrfContext();
        $res = $this->httpPostJsonWithCsrf('/api/' . self::$seed['slug'] . '/bookings', $payload, $csrf);

        $this->assertSame(201, $res['code'], 'Booking creation must return 201. Body: ' . $res['body']);
        $data = json_decode($res['body'], true);

        $this->assertArrayHasKey('booking', $data);
        $booking = $data['booking'];
        $this->assertArrayHasKey('id', $booking);
        $this->assertSame($slot['date'], $booking['date']);
        $this->assertSame($slot['time'], $booking['time']);
        $this->assertTrue($booking['consent_recorded'], 'Consent must be recorded');

        $this->cleanupIds[] = ['bookings', $booking['id']];
    }

    // ════════════════════════════════════════════════════════════════
    // Consent evidence via public flow
    // ════════════════════════════════════════════════════════════════

    public function testConsentEvidenceRecordedViaPublicBooking(): void
    {
        $slot = $this->getFirstAvailableSlot('next Tuesday');

        $payload = [
            'service_id'     => self::$seed['service_id'],
            'start_datetime' => $slot['date'] . 'T' . $slot['time'] . ':00',
            'customer'       => [
                'name'  => 'Consent Evidence Tester',
                'email' => 'consent-test-' . substr(Ulid::generate(), -6) . '@example.com',
            ],
            'consent_given' => true,
            '__ts'          => (time() - 10) * 1000,
        ];

        $csrf = $this->fetchCsrfContext();
        $res = $this->httpPostJsonWithCsrf('/api/' . self::$seed['slug'] . '/bookings', $payload, $csrf);
        $this->assertSame(201, $res['code'], 'Consent booking must return 201. Body: ' . $res['body']);

        $data = json_decode($res['body'], true);
        $this->assertArrayHasKey('booking', $data);
        $bookingId = $data['booking']['id'];
        $this->cleanupIds[] = ['bookings', $bookingId];

        // Verify consent was recorded in the database
        $rows = Database::query(
            'SELECT `consent_given_at`, `consent_text_shown` FROM `bookings` WHERE `id` = ?',
            [$bookingId]
        );

        $this->assertCount(1, $rows);
        $this->assertNotNull($rows[0]['consent_given_at'], 'consent_given_at must be set');
        $this->assertNotNull($rows[0]['consent_text_shown'], 'consent_text_shown must be recorded');
        $this->assertStringContainsString(
            'I agree to the processing',
            $rows[0]['consent_text_shown'],
            'Must record the exact consent text shown'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Slot conflict (double-booking prevention)
    // ════════════════════════════════════════════════════════════════

    public function testDoubleBookingReturns409(): void
    {
        $slot = $this->getFirstAvailableSlot('next Wednesday', self::$seed['staff_id']);

        $basePayload = [
            'service_id'     => self::$seed['service_id'],
            'staff_id'       => self::$seed['staff_id'],
            'start_datetime' => $slot['date'] . 'T' . $slot['time'] . ':00',
            'consent_given'  => true,
            '__ts'           => (time() - 10) * 1000,
        ];

        // First booking — should succeed
        $payload1 = $basePayload;
        $payload1['customer'] = [
            'name'  => 'First Booker',
            'email' => 'first-' . substr(Ulid::generate(), -6) . '@example.com',
        ];

        $csrf = $this->fetchCsrfContext();
        $res1 = $this->httpPostJsonWithCsrf('/api/' . self::$seed['slug'] . '/bookings', $payload1, $csrf);
        $this->assertSame(201, $res1['code'], 'First booking must succeed. Body: ' . $res1['body']);

        $data1 = json_decode($res1['body'], true);
        $this->assertArrayHasKey('booking', $data1);
        $this->cleanupIds[] = ['bookings', $data1['booking']['id']];

        // Second booking — same slot, same staff → should be 409
        $payload2 = $basePayload;
        $payload2['customer'] = [
            'name'  => 'Second Booker',
            'email' => 'second-' . substr(Ulid::generate(), -6) . '@example.com',
        ];

        $csrf2 = $this->fetchCsrfContext();
        $res2 = $this->httpPostJsonWithCsrf('/api/' . self::$seed['slug'] . '/bookings', $payload2, $csrf2);
        $this->assertSame(409, $res2['code'], 'Double booking must be rejected with 409. Body: ' . $res2['body']);

        $data2 = json_decode($res2['body'], true);
        $this->assertSame('slot_unavailable', $data2['error'] ?? '');
        $this->assertArrayHasKey('alternatives', $data2, 'Must offer alternative slots');

        // 3e: alternatives must include end_time for review summary
        if (!empty($data2['alternatives'])) {
            foreach ($data2['alternatives'] as $alt) {
                $this->assertArrayHasKey('end_time', $alt,
                    'Each alternative must include end_time for the review summary');
                $this->assertArrayHasKey('time', $alt);
                $this->assertArrayHasKey('staff_id', $alt);
            }
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Validation
    // ════════════════════════════════════════════════════════════════

    public function testBookingRejectsMissingCustomer(): void
    {
        $payload = [
            'service_id'     => self::$seed['service_id'],
            'start_datetime' => '2026-07-01T10:00:00',
            'customer'       => ['name' => '', 'email' => ''],
            'consent_given'  => true,
            '__ts'           => (time() - 10) * 1000,
        ];

        $csrf = $this->fetchCsrfContext();
        $res = $this->httpPostJsonWithCsrf('/api/' . self::$seed['slug'] . '/bookings', $payload, $csrf);
        $this->assertSame(422, $res['code'], 'Missing customer must return 422. Body: ' . $res['body']);

        $data = json_decode($res['body'], true);
        $this->assertSame('validation', $data['error'] ?? '');
    }

    public function testBookingRejectsInvalidEmail(): void
    {
        $payload = [
            'service_id'     => self::$seed['service_id'],
            'start_datetime' => '2026-07-01T10:00:00',
            'customer'       => ['name' => 'Test', 'email' => 'not-an-email'],
            'consent_given'  => true,
            '__ts'           => (time() - 10) * 1000,
        ];

        $csrf = $this->fetchCsrfContext();
        $res = $this->httpPostJsonWithCsrf('/api/' . self::$seed['slug'] . '/bookings', $payload, $csrf);
        $this->assertSame(422, $res['code'], 'Invalid email must return 422. Body: ' . $res['body']);
    }

    public function testBookingRejectsSpamSubmission(): void
    {
        $payload = [
            'service_id'     => self::$seed['service_id'],
            'start_datetime' => '2026-07-01T10:00:00',
            'customer'       => ['name' => 'Bot', 'email' => 'bot@example.com'],
            'consent_given'  => true,
            '__ts'           => time() * 1000, // Current time — too fast
        ];

        $res = $this->httpPostJson('/api/' . self::$seed['slug'] . '/bookings', $payload);
        $this->assertSame(422, $res['code'], 'Spam must return 422. Body: ' . $res['body']);

        $data = json_decode($res['body'], true);
        $this->assertSame('spam_detected', $data['error'] ?? '');
    }

    // ════════════════════════════════════════════════════════════════
    // Slice 2: Page-shell hooks — review & confirmation surfaces
    //
    // These tests verify that the page HTML contains both the config
    // blob values AND the Alpine.js template directives that consume
    // them. This is page-shell and config coverage, not runtime DOM
    // rendering — there is no browser test harness.
    // ════════════════════════════════════════════════════════════════

    public function testPageShellContainsReviewDisclosureHooks(): void
    {
        $res = $this->httpGet('/book/' . self::$seed['slug']);
        $this->assertSame(200, $res['code']);

        // Policy conditional gate
        $this->assertStringContainsString('x-if="hasCancellationPolicy"', $res['body'],
            'Review step must gate policy disclosure on hasCancellationPolicy');

        // Policy toggle button and CSS class
        $this->assertStringContainsString('vb-book-policy-toggle', $res['body'],
            'Review step must contain the policy toggle button');
        $this->assertStringContainsString('@click="togglePolicy"', $res['body'],
            'Policy toggle must have a click handler');

        // Policy text binding
        $this->assertStringContainsString('x-text="cancellationPolicyText"', $res['body'],
            'Policy text div must bind to cancellationPolicyText');

        // Translation hook for label
        $this->assertStringContainsString("x-text=\"t('review.cancellation_policy_label')\"", $res['body'],
            'Policy toggle label must use translation key');
    }

    public function testPageShellContainsConfirmationMessageHooks(): void
    {
        $res = $this->httpGet('/book/' . self::$seed['slug']);
        $this->assertSame(200, $res['code']);

        // Email-sent message (always rendered, filled at runtime)
        $this->assertStringContainsString('x-text="confirmEmailSent"', $res['body'],
            'Confirmation must bind email-sent message');

        // Custom confirmation message (conditional)
        $this->assertStringContainsString('x-if="confirmCustomMessage"', $res['body'],
            'Confirmation must gate custom message on confirmCustomMessage');
        $this->assertStringContainsString('x-text="confirmCustomMessage"', $res['body'],
            'Confirmation must bind custom message text');
    }

    public function testPageShellContainsCalendarActionHooks(): void
    {
        $res = $this->httpGet('/book/' . self::$seed['slug']);
        $this->assertSame(200, $res['code']);

        // .ics download action
        $this->assertStringContainsString('@click="downloadIcs"', $res['body'],
            'Confirmation must have .ics download action');
        $this->assertStringContainsString("x-text=\"t('buttons.download_ics')\"", $res['body'],
            'Download button must use translation key');

        // Google Calendar link
        $this->assertStringContainsString('x-bind:href="gcalUrl"', $res['body'],
            'Confirmation must have Google Calendar link');
    }

    public function testPageShellContainsRescheduleCancelAffordances(): void
    {
        $res = $this->httpGet('/book/' . self::$seed['slug']);
        $this->assertSame(200, $res['code']);

        // Reschedule affordance: gated on showReschedule and booking availability
        $this->assertStringContainsString('showReschedule', $res['body'],
            'Confirmation must gate reschedule on showReschedule');
        $this->assertStringContainsString('manageUrl(booking.id)', $res['body'],
            'Reschedule/cancel must link to the manage URL via manageUrl');

        // Cancel affordance: gated
        $this->assertStringContainsString('showCancel', $res['body'],
            'Confirmation must gate cancel on showCancel');

        // Book another action
        $this->assertStringContainsString('@click="bookAnother"', $res['body'],
            'Confirmation must have book-another action');

        // Config blob defaults (allow both by default)
        $this->assertStringContainsString('"allow_cancellation":true', $res['body']);
        $this->assertStringContainsString('"allow_rescheduling":true', $res['body']);
    }

    public function testConfigBlobCancellationPolicyWhenConfigured(): void
    {
        Database::execute(
            'UPDATE `tenants` SET `cancellation_policy` = ? WHERE `id` = ?',
            ['Cancel at least 24 hours before your appointment.', self::$seed['tenant_id']]
        );

        try {
            $res = $this->httpGet('/book/' . self::$seed['slug']);
            $this->assertSame(200, $res['code']);

            // Config blob contains the text
            $this->assertStringContainsString(
                'Cancel at least 24 hours before your appointment.',
                $res['body'],
                'Config blob must include cancellation_policy when set'
            );
        } finally {
            Database::execute(
                'UPDATE `tenants` SET `cancellation_policy` = NULL WHERE `id` = ?',
                [self::$seed['tenant_id']]
            );
        }
    }

    public function testConfigBlobConfirmationMessageWhenConfigured(): void
    {
        Database::execute(
            'UPDATE `tenants` SET `confirmation_message` = ? WHERE `id` = ?',
            ['We look forward to seeing you!', self::$seed['tenant_id']]
        );

        try {
            $res = $this->httpGet('/book/' . self::$seed['slug']);
            $this->assertSame(200, $res['code']);

            // Config blob contains the text
            $this->assertStringContainsString(
                'We look forward to seeing you!',
                $res['body'],
                'Config blob must include confirmation_message when set'
            );

            // Null by default should not appear after restore
        } finally {
            Database::execute(
                'UPDATE `tenants` SET `confirmation_message` = NULL WHERE `id` = ?',
                [self::$seed['tenant_id']]
            );
        }
    }

    public function testConfigBlobGatesRescheduleWhenDisabled(): void
    {
        Database::execute(
            'UPDATE `tenants` SET `allow_rescheduling` = 0 WHERE `id` = ?',
            [self::$seed['tenant_id']]
        );

        try {
            $res = $this->httpGet('/book/' . self::$seed['slug']);
            $this->assertSame(200, $res['code']);
            $this->assertStringContainsString('"allow_rescheduling":false', $res['body'],
                'Config blob must reflect allow_rescheduling=false when disabled');
        } finally {
            Database::execute(
                'UPDATE `tenants` SET `allow_rescheduling` = 1 WHERE `id` = ?',
                [self::$seed['tenant_id']]
            );
        }
    }

    public function testConfigBlobGatesCancellationWhenDisabled(): void
    {
        Database::execute(
            'UPDATE `tenants` SET `allow_cancellation` = 0 WHERE `id` = ?',
            [self::$seed['tenant_id']]
        );

        try {
            $res = $this->httpGet('/book/' . self::$seed['slug']);
            $this->assertSame(200, $res['code']);
            $this->assertStringContainsString('"allow_cancellation":false', $res['body'],
                'Config blob must reflect allow_cancellation=false when disabled');
        } finally {
            Database::execute(
                'UPDATE `tenants` SET `allow_cancellation` = 1 WHERE `id` = ?',
                [self::$seed['tenant_id']]
            );
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Slice 3: Page-shell hooks — preparation_text & custom fields
    //
    // These tests verify the API response shape and page-shell hooks
    // for 3a (custom-field review) and 3b (preparation_text callout).
    // 3a has no automated test — pure JS rendering requires browser.
    // ════════════════════════════════════════════════════════════════

    public function testServicesApiReturnsPreparationText(): void
    {
        $res = $this->httpGet('/api/' . self::$seed['slug'] . '/services');
        $this->assertSame(200, $res['code']);

        $data = json_decode($res['body'], true);
        $this->assertNotEmpty($data['services'], 'Tenant must have at least one active service');

        // Every service in the response must include the preparation_text key
        foreach ($data['services'] as $service) {
            $this->assertArrayHasKey('preparation_text', $service,
                "Service '{$service['name']}' must include preparation_text in API response");
        }
    }

    public function testPageShellContainsPreparationTextHooks(): void
    {
        $res = $this->httpGet('/book/' . self::$seed['slug']);
        $this->assertSame(200, $res['code']);

        // Conditional gate
        $this->assertStringContainsString('x-if="preparationText"', $res['body'],
            'Review step must gate preparation callout on preparationText');

        // Text binding
        $this->assertStringContainsString('x-text="preparationText"', $res['body'],
            'Preparation callout must bind text to preparationText getter');

        // CSS class
        $this->assertStringContainsString('vb-book-preparation-callout', $res['body'],
            'Preparation callout must use the dedicated CSS class');
    }

    // ════════════════════════════════════════════════════════════════
    // Slice 3: Honeypot anti-spam (3c)
    // ════════════════════════════════════════════════════════════════

    public function testPageShellContainsHoneypotField(): void
    {
        $res = $this->httpGet('/book/' . self::$seed['slug']);
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('name="__hp"', $res['body'],
            'Details form must contain the honeypot hidden field');
        $this->assertStringContainsString('vb-book-hp', $res['body'],
            'Honeypot field must use the dedicated CSS class');
    }

    public function testHoneypotRejectsFilledField(): void
    {
        $slot = $this->getFirstAvailableSlot('+1 day');

        $res = $this->httpPostJson('/api/' . self::$seed['slug'] . '/bookings', [
            'service_id'      => self::$seed['service_id'],
            'staff_id'        => self::$seed['staff_id'],
            'start_datetime'  => $slot['date'] . 'T' . $slot['time'] . ':00',
            'customer'        => ['name' => 'Bot User', 'email' => 'bot@spam.test', 'phone' => ''],
            'notes'           => '',
            'consent_given'   => true,
            'customer_timezone' => 'Europe/Amsterdam',
            '__ts'            => (string) ((time() - 10) * 1000),
            '__hp'            => 'bot-text',
        ]);

        $this->assertSame(422, $res['code']);
        $data = json_decode($res['body'], true);
        $this->assertSame('spam_detected', $data['error'],
            'Non-empty honeypot must return spam_detected error');
    }

    // ════════════════════════════════════════════════════════════════
    // Slice 3: Slot-taken recovery (3e)
    // ════════════════════════════════════════════════════════════════

    public function testPageShellContainsSlotRecoveryHooks(): void
    {
        $res = $this->httpGet('/book/' . self::$seed['slug']);
        $this->assertSame(200, $res['code']);

        $this->assertStringContainsString('x-if="slotAlternatives.length > 0"', $res['body'],
            'Review step must gate recovery panel on slotAlternatives');
        $this->assertStringContainsString('@click="selectAlternative(alt)"', $res['body'],
            'Recovery pills must call selectAlternative(alt)');
        $this->assertStringContainsString('vb-book-slot-recovery', $res['body'],
            'Recovery panel must use the dedicated CSS class');
    }

    // ════════════════════════════════════════════════════════════════
    // Slice 3: CSRF on booking POST (3d)
    // ════════════════════════════════════════════════════════════════

    public function testBookingRejectsMissingCsrfToken(): void
    {
        $payload = [
            'service_id'     => self::$seed['service_id'],
            'start_datetime' => '2026-07-01T10:00:00',
            'customer'       => ['name' => 'No CSRF', 'email' => 'nocsrf@test.local'],
            'consent_given'  => true,
            '__ts'           => (time() - 10) * 1000,
            '__hp'           => '',
        ];

        // POST without X-CSRF-Token header — should be rejected
        $res = $this->httpPostJson('/api/' . self::$seed['slug'] . '/bookings', $payload);
        $this->assertSame(403, $res['code'], 'Missing CSRF token must return 403. Body: ' . $res['body']);

        $data = json_decode($res['body'], true);
        $this->assertSame('csrf_mismatch', $data['error'] ?? '');
    }

    public function testBookingRejectsWrongCsrfToken(): void
    {
        $payload = [
            'service_id'     => self::$seed['service_id'],
            'start_datetime' => '2026-07-01T10:00:00',
            'customer'       => ['name' => 'Bad CSRF', 'email' => 'badcsrf@test.local'],
            'consent_given'  => true,
            '__ts'           => (time() - 10) * 1000,
            '__hp'           => '',
        ];

        // Fetch a real CSRF context but tamper with the token
        $csrf = $this->fetchCsrfContext();
        $csrf['token'] = 'totally-wrong-token-value';

        $res = $this->httpPostJsonWithCsrf('/api/' . self::$seed['slug'] . '/bookings', $payload, $csrf);
        $this->assertSame(403, $res['code'], 'Wrong CSRF token must return 403. Body: ' . $res['body']);

        $data = json_decode($res['body'], true);
        $this->assertSame('csrf_mismatch', $data['error'] ?? '');
    }

    // ════════════════════════════════════════════════════════════════
    // Slice 3: Max bookings per customer per day (3f)
    // ════════════════════════════════════════════════════════════════

    public function testMaxBookingsPerCustomerPerDayEnforced(): void
    {
        // Set limit to 1 booking per customer per day
        Database::execute(
            'UPDATE `tenants` SET `max_bookings_per_customer_per_day` = 1 WHERE `id` = ?',
            [self::$seed['tenant_id']]
        );

        try {
            $slot1 = $this->getFirstAvailableSlot('+1 day');
            $uniqueEmail = 'daily-limit-' . substr(Ulid::generate(), -6) . '@example.com';

            // First booking — should succeed
            $csrf1 = $this->fetchCsrfContext();
            $res1 = $this->httpPostJsonWithCsrf('/api/' . self::$seed['slug'] . '/bookings', [
                'service_id'      => self::$seed['service_id'],
                'staff_id'        => self::$seed['staff_id'],
                'start_datetime'  => $slot1['date'] . 'T' . $slot1['time'] . ':00',
                'customer'        => ['name' => 'Limit Test', 'email' => $uniqueEmail, 'phone' => ''],
                'notes'           => '',
                'consent_given'   => true,
                'customer_timezone' => 'Europe/Amsterdam',
                '__ts'            => (string) ((time() - 10) * 1000),
                '__hp'            => '',
            ], $csrf1);

            $this->assertSame(201, $res1['code'], 'First booking must succeed. Body: ' . $res1['body']);
            $data1 = json_decode($res1['body'], true);
            $this->cleanupIds[] = ['bookings', $data1['booking']['id']];

            // Second booking — same customer, same day, different slot → must be rejected
            $slot2 = $this->getFirstAvailableSlot('+1 day');

            $csrf2 = $this->fetchCsrfContext();
            $res2 = $this->httpPostJsonWithCsrf('/api/' . self::$seed['slug'] . '/bookings', [
                'service_id'      => self::$seed['service_id'],
                'staff_id'        => self::$seed['staff_id'],
                'start_datetime'  => $slot2['date'] . 'T' . $slot2['time'] . ':00',
                'customer'        => ['name' => 'Limit Test', 'email' => $uniqueEmail, 'phone' => ''],
                'notes'           => '',
                'consent_given'   => true,
                'customer_timezone' => 'Europe/Amsterdam',
                '__ts'            => (string) ((time() - 10) * 1000),
                '__hp'            => '',
            ], $csrf2);

            $this->assertSame(422, $res2['code'], 'Second booking must be rejected. Body: ' . $res2['body']);
            $data2 = json_decode($res2['body'], true);
            $this->assertSame('max_bookings_exceeded', $data2['error'],
                'Error code must be max_bookings_exceeded');
        } finally {
            // Restore default limit
            Database::execute(
                'UPDATE `tenants` SET `max_bookings_per_customer_per_day` = 3 WHERE `id` = ?',
                [self::$seed['tenant_id']]
            );
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Confirmation email dispatch
    // ════════════════════════════════════════════════════════════════

    /**
     * Booking response must include email_sent field.
     * Default test state has no SMTP configured → email_sent = false.
     */
    public function testCreateBookingReturnsEmailSentField(): void
    {
        // Explicitly mark SMTP as unconfigured in the DB.
        // Empty DB rows prevent .env MAIL_* fallback from activating.
        $savedSmtp = [];
        $smtpKeys = ['smtp_host', 'mail_transport'];
        foreach ($smtpKeys as $key) {
            $rows = Database::query(
                "SELECT `value` FROM `settings` WHERE `key` = ? LIMIT 1", [$key]
            );
            $savedSmtp[$key] = ['had' => !empty($rows), 'value' => $rows[0]['value'] ?? null];
            Database::execute(
                "INSERT INTO `settings` (`key`, `value`) VALUES (?, '')
                 ON DUPLICATE KEY UPDATE `value` = ''",
                [$key]
            );
        }
        Mailer::clearConfigCache();

        try {
            $slot = $this->getFirstAvailableSlot('next Thursday');

            $payload = [
                'service_id'     => self::$seed['service_id'],
                'staff_id'       => self::$seed['staff_id'],
                'start_datetime' => $slot['date'] . 'T' . $slot['time'] . ':00',
                'customer'       => [
                    'name'  => 'Email Flag Tester',
                    'email' => 'email-flag-' . substr(Ulid::generate(), -6) . '@example.com',
                ],
                'consent_given'  => true,
                '__ts'           => (time() - 10) * 1000,
                '__hp'           => '',
            ];

            $csrf = $this->fetchCsrfContext();
            $res = $this->httpPostJsonWithCsrf('/api/' . self::$seed['slug'] . '/bookings', $payload, $csrf);

            $this->assertSame(201, $res['code'], 'Booking must succeed. Body: ' . $res['body']);
            $data = json_decode($res['body'], true);

            $this->assertArrayHasKey('email_sent', $data['booking'], 'Response must include email_sent field');
            $this->assertFalse($data['booking']['email_sent'],
                'email_sent must be false when SMTP is not configured');

            $this->cleanupIds[] = ['bookings', $data['booking']['id']];
        } finally {
            // Restore SMTP settings
            foreach ($savedSmtp as $key => $prior) {
                if ($prior['had'] && $prior['value'] !== null) {
                    Database::execute(
                        "UPDATE `settings` SET `value` = ? WHERE `key` = ?",
                        [$prior['value'], $key]
                    );
                } else {
                    Database::execute("DELETE FROM `settings` WHERE `key` = ?", [$key]);
                }
            }
            Mailer::clearConfigCache();
        }
    }

    /**
     * Log transport: email_log row is created but email_sent stays false.
     * Log transport records without outbound delivery — UI must not claim sent.
     */
    public function testConfirmationEmailLoggedWithLogTransport(): void
    {
        // Capture prior mail_transport for exact restoration
        $prior = Database::query(
            "SELECT `value` FROM `settings` WHERE `key` = 'mail_transport' LIMIT 1"
        );
        $hadPrior = !empty($prior);
        $priorValue = $prior[0]['value'] ?? null;

        try {
            Database::execute(
                "INSERT INTO `settings` (`key`, `value`) VALUES ('mail_transport', 'log')
                 ON DUPLICATE KEY UPDATE `value` = 'log'"
            );
            Mailer::clearConfigCache();

            $slot = $this->getFirstAvailableSlot('next Friday');

            $payload = [
                'service_id'     => self::$seed['service_id'],
                'staff_id'       => self::$seed['staff_id'],
                'start_datetime' => $slot['date'] . 'T' . $slot['time'] . ':00',
                'customer'       => [
                    'name'  => 'Log Email Tester',
                    'email' => 'log-email-' . substr(Ulid::generate(), -6) . '@example.com',
                ],
                'consent_given'  => true,
                '__ts'           => (time() - 10) * 1000,
                '__hp'           => '',
            ];

            $csrf = $this->fetchCsrfContext();
            $res = $this->httpPostJsonWithCsrf('/api/' . self::$seed['slug'] . '/bookings', $payload, $csrf);

            $this->assertSame(201, $res['code'], 'Booking must succeed. Body: ' . $res['body']);
            $data = json_decode($res['body'], true);
            $bookingId = $data['booking']['id'];

            // email_sent is false — log transport does not reach customer inbox
            $this->assertFalse($data['booking']['email_sent'],
                'email_sent must be false for log transport (no outbound delivery)');

            // But email_log row exists — the email was recorded
            $logs = Database::query(
                'SELECT `type`, `status` FROM `email_log` WHERE `booking_id` = ?',
                [$bookingId]
            );
            $this->assertCount(2, $logs, 'Confirmation + staff notification must be logged in email_log');
            $types = array_column($logs, 'type');
            $this->assertContains('confirmation', $types, 'Customer confirmation must be logged');
            $this->assertContains('staff_notification', $types, 'Staff notification must be logged');

            // Clean up email_log rows created by this test
            Database::execute('DELETE FROM `email_log` WHERE `booking_id` = ?', [$bookingId]);

            $this->cleanupIds[] = ['bookings', $bookingId];

        } finally {
            // Restore exact prior mail_transport value
            if ($hadPrior) {
                Database::execute(
                    "UPDATE `settings` SET `value` = ? WHERE `key` = 'mail_transport'",
                    [$priorValue]
                );
            } else {
                Database::execute("DELETE FROM `settings` WHERE `key` = 'mail_transport'");
            }
            Mailer::clearConfigCache();
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Helpers
    // ════════════════════════════════════════════════════════════════

    /**
     * Fetch availability for the given relative day and return the first slot.
     * Fails the calling test with a clear message if no slots are available
     * (never reads response body without first asserting the status code).
     *
     * @return array{date: string, time: string, end_time: string}
     */
    private function getFirstAvailableSlot(string $relativeDay, ?string $staffId = null): array
    {
        // Try up to 7 consecutive days starting from $relativeDay to handle
        // weekends/holidays where the tenant has no availability configured.
        $baseDate = new \DateTimeImmutable($relativeDay);

        for ($offset = 0; $offset < 7; $offset++) {
            $date = $baseDate->modify("+{$offset} days")->format('Y-m-d');
            $params = '?date=' . $date;
            if ($staffId !== null) {
                $params .= '&staff_id=' . $staffId;
            }

            $res = $this->httpGetJson('/api/' . self::$seed['slug'] . '/availability' . $params);

            $this->assertSame(
                200,
                $res['code'],
                "Availability for {$date} must return 200. Got {$res['code']}. Body: {$res['body']}"
            );

            $data = json_decode($res['body'], true);
            $this->assertArrayHasKey('slots', $data, 'Response must contain slots key');

            if (!empty($data['slots'])) {
                $slot = $data['slots'][0];
                $slot['date'] = $date;
                return $slot;
            }
        }

        $this->fail(
            "No available slots found within 7 days starting from {$relativeDay} ({$baseDate->format('Y-m-d')})"
        );
    }

    // ════════════════════════════════════════════════════════════════
    // HTTP helpers
    // ════════════════════════════════════════════════════════════════

    private function httpGet(string $path): array
    {
        return $this->request('GET', $path);
    }

    private function httpGetJson(string $path): array
    {
        return $this->request('GET', $path, [], ['Accept: application/json']);
    }

    private function httpPostJson(string $path, array $payload): array
    {
        return $this->request('POST', $path, $payload, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
    }

    /**
     * POST JSON with a valid CSRF context (token + session cookie).
     *
     * @param array{token: string, cookieFile: string} $csrf from fetchCsrfContext()
     */
    private function httpPostJsonWithCsrf(string $path, array $payload, array $csrf): array
    {
        return $this->request('POST', $path, $payload, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-CSRF-Token: ' . $csrf['token'],
        ], $csrf['cookieFile']);
    }

    /**
     * Fetch a CSRF context by loading the booking page.
     *
     * Returns the CSRF token and cookie file path (for the session).
     *
     * @return array{token: string, cookieFile: string}
     */
    private function fetchCsrfContext(): array
    {
        $cookieFile = sys_get_temp_dir() . '/vb_csrf_' . bin2hex(random_bytes(8)) . '.txt';
        $this->csrfCookieFile = $cookieFile;

        $ch = curl_init($this->baseUrl . '/book/' . self::$seed['slug']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
        ]);
        $body = (string) curl_exec($ch);
        curl_close($ch);

        // Extract window.__VB_CSRF__ = "..."
        preg_match('/window\.__VB_CSRF__\s*=\s*"([^"]+)"/', $body, $m);
        $token = $m[1] ?? '';

        $this->assertNotEmpty($token, 'CSRF token must be present in booking page');

        return ['token' => $token, 'cookieFile' => $cookieFile];
    }

    /**
     * @return array{code: int, headers: string, body: string}
     */
    private function request(string $method, string $path, array $data = [], array $headers = [], ?string $cookieFile = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
        ]);

        if ($cookieFile) {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (in_array('Content-Type: application/json', $headers, true)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            }
        }

        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return [
            'code'    => $code,
            'headers' => substr($response, 0, $headerSize),
            'body'    => substr($response, $headerSize),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    // Seed data
    // ════════════════════════════════════════════════════════════════

    /**
     * Seeds a complete test tenant with service, staff, availability,
     * and service-staff pivot. Returns identifiers for test use.
     */
    private static function seedTestTenant(): array
    {
        $tenantId = Ulid::generate();
        $serviceId = Ulid::generate();
        $staffId = Ulid::generate();
        $slug = 'test-booking-' . substr($tenantId, -8);

        // Tenant
        Database::execute(
            "INSERT INTO `tenants` (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`,
             `brand_color`, `timezone`, `locale`, `currency`, `requires_consent`, `consent_text`,
             `slot_duration_minutes`, `max_advance_days`)
             VALUES (?, ?, 'Booking Flow Test', 'test@booking.test', 'timeslot', 'active',
             '#3B82F6', 'Europe/Amsterdam', 'en', 'EUR', 1,
             'I agree to the processing of my personal data for booking purposes.', 30, 60)",
            [$tenantId, $slug]
        );

        // Service: 30-minute appointment
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `description`, `duration_minutes`, `price`, `is_active`, `sort_order`)
             VALUES (?, ?, 'Test Consultation', 'A test service', 30, 25.00, 1, 1)",
            [$serviceId, $tenantId]
        );

        // Staff member
        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`, `sort_order`)
             VALUES (?, ?, 'Test Practitioner', 'staff@booking.test', 1, 1)",
            [$staffId, $tenantId]
        );

        // Service-staff pivot
        Database::execute(
            'INSERT INTO `service_staff` (`service_id`, `staff_id`) VALUES (?, ?)',
            [$serviceId, $staffId]
        );

        // Availability: Mon–Fri 09:00–17:00 (day_of_week: 0=Mon, 4=Fri per ISO convention)
        for ($day = 0; $day <= 4; $day++) {
            Database::execute(
                "INSERT INTO `availability` (`id`, `tenant_id`, `day_of_week`, `start_time`, `end_time`)
                 VALUES (?, ?, ?, '09:00', '17:00')",
                [Ulid::generate(), $tenantId, $day]
            );
        }

        return [
            'slug'           => $slug,
            'tenant_id'      => $tenantId,
            'customer_email' => 'test@booking.test',
            'service_id'     => $serviceId,
            'staff_id'       => $staffId,
        ];
    }
}
