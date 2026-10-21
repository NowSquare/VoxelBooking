<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * Timezone-specific integration tests for the booking flow.
 *
 * Covers:
 * - customer_timezone is captured and persisted for valid IANA identifiers
 * - customer_timezone is rejected/ignored for invalid strings
 * - customer_timezone does not alter the stored start_datetime (tenant-authoritative)
 * - Bookings near date boundaries (23:00+ tenant time) store correctly
 * - DST-aware timezone identifiers (America/New_York, Europe/London) are accepted
 * - Booking page config injects the tenant timezone for the frontend
 *
 * Requires: The app running at APP_TEST_URL (defaults to https://voxelbooking-app.test).
 */
final class TimezoneBookingTest extends TestCase
{
    private string $baseUrl;
    private array $cleanupIds = [];
    private ?string $csrfCookieFile = null;

    private static bool $appReachable = false;
    private static bool $dbReady = false;

    /** @var array{slug: string, tenant_id: string, service_id: string, staff_id: string} */
    private static array $seed = [];

    public static function setUpBeforeClass(): void
    {
        $baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');

        $ch = curl_init($baseUrl . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 5,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);


        if ($code === 0) {
            return;
        }

        self::$appReachable = true;

        try {
            require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
            \Tests\Support\TestEnvironment::boot();
            Database::connect();
            Database::query('SELECT 1');
            self::$dbReady = true;
        } catch (\Throwable) {
            return;
        }

        // Clear rate limits for clean test runs
        try {
            Database::execute('TRUNCATE TABLE `rate_limits`');
        } catch (\Throwable) {
            // table may not exist
        }

        self::$seed = self::seedTzTestTenant();
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

        // Clear rate limits before each test
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
                // best-effort
            }
        }

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
            // best-effort
        }

        self::$seed = [];
    }

    // ════════════════════════════════════════════════════════════════
    // customer_timezone capture
    // ════════════════════════════════════════════════════════════════

    /**
     * A valid IANA timezone identifier should be stored in the bookings table.
     */
    public function testValidCustomerTimezoneIsPersisted(): void
    {
        $slot = $this->getFirstAvailableSlot('next Monday');

        $payload = $this->buildPayload($slot, [
            'customer_timezone' => 'America/New_York',
        ]);

        $csrf = $this->fetchCsrfContext();
        $res = $this->httpPostJsonWithCsrf('/api/' . self::$seed['slug'] . '/bookings', $payload, $csrf);
        $this->assertSame(201, $res['code'], 'Booking with valid TZ must succeed. Body: ' . $res['body']);

        $data = json_decode($res['body'], true);
        $bookingId = $data['booking']['id'];
        $this->cleanupIds[] = ['bookings', $bookingId];

        // Verify timezone is stored in DB
        $rows = Database::query(
            'SELECT `customer_timezone` FROM `bookings` WHERE `id` = ?',
            [$bookingId]
        );

        $this->assertCount(1, $rows);
        $this->assertSame('America/New_York', $rows[0]['customer_timezone'],
            'customer_timezone must be persisted as-is for valid IANA identifiers');
    }

    /**
     * DST-aware European timezone (switches between CET/CEST) should be accepted.
     */
    public function testDstAwareEuropeanTimezoneAccepted(): void
    {
        $slot = $this->getFirstAvailableSlot('next Tuesday');

        $payload = $this->buildPayload($slot, [
            'customer_timezone' => 'Europe/London',
        ]);

        $csrf = $this->fetchCsrfContext();
        $res = $this->httpPostJsonWithCsrf('/api/' . self::$seed['slug'] . '/bookings', $payload, $csrf);
        $this->assertSame(201, $res['code'], 'Booking with EU DST zone must succeed. Body: ' . $res['body']);

        $data = json_decode($res['body'], true);
        $bookingId = $data['booking']['id'];
        $this->cleanupIds[] = ['bookings', $bookingId];

        $rows = Database::query(
            'SELECT `customer_timezone` FROM `bookings` WHERE `id` = ?',
            [$bookingId]
        );

        $this->assertSame('Europe/London', $rows[0]['customer_timezone']);
    }

    /**
     * DST-aware US timezone (switches between EDT/EST) should be accepted.
     */
    public function testDstAwareUsTimezoneAccepted(): void
    {
        $slot = $this->getFirstAvailableSlot('next Wednesday');

        $payload = $this->buildPayload($slot, [
            'customer_timezone' => 'America/Chicago',
        ]);

        $csrf = $this->fetchCsrfContext();
        $res = $this->httpPostJsonWithCsrf('/api/' . self::$seed['slug'] . '/bookings', $payload, $csrf);
        $this->assertSame(201, $res['code'], 'Booking with US DST zone must succeed. Body: ' . $res['body']);

        $data = json_decode($res['body'], true);
        $bookingId = $data['booking']['id'];
        $this->cleanupIds[] = ['bookings', $bookingId];

        $rows = Database::query(
            'SELECT `customer_timezone` FROM `bookings` WHERE `id` = ?',
            [$bookingId]
        );

        $this->assertSame('America/Chicago', $rows[0]['customer_timezone']);
    }

    /**
     * An invalid timezone string should be silently ignored (not stored).
     */
    public function testInvalidTimezoneIsRejectedWithoutCreatingBooking(): void
    {
        $slot = $this->getFirstAvailableSlot('next Thursday');
        $payload = $this->buildPayload($slot, ['customer_timezone'=>'Not/A_Real_Zone']);
        $before=(int)Database::query('SELECT COUNT(*) AS n FROM bookings WHERE tenant_id=?',[self::$seed['tenant_id']])[0]['n'];
        $res = $this->httpPostJsonWithCsrf('/api/' . self::$seed['slug'] . '/bookings', $payload, $this->fetchCsrfContext());
        $this->assertSame(422,$res['code']);
        $this->assertArrayHasKey('customer_timezone',json_decode($res['body'],true)['errors']);
        $this->assertSame($before,(int)Database::query('SELECT COUNT(*) AS n FROM bookings WHERE tenant_id=?',[self::$seed['tenant_id']])[0]['n']);
    }

    /**
     * An empty timezone string should result in NULL.
     */
    public function testEmptyTimezoneIsNull(): void
    {
        $slot = $this->getFirstAvailableSlot('next Friday');

        $payload = $this->buildPayload($slot, [
            'customer_timezone' => '',
        ]);

        $csrf = $this->fetchCsrfContext();
        $res = $this->httpPostJsonWithCsrf('/api/' . self::$seed['slug'] . '/bookings', $payload, $csrf);
        $this->assertSame(201, $res['code'], 'Booking with empty TZ must succeed. Body: ' . $res['body']);

        $data = json_decode($res['body'], true);
        $bookingId = $data['booking']['id'];
        $this->cleanupIds[] = ['bookings', $bookingId];

        $rows = Database::query(
            'SELECT `customer_timezone` FROM `bookings` WHERE `id` = ?',
            [$bookingId]
        );

        $this->assertNull($rows[0]['customer_timezone'],
            'Empty timezone string must result in NULL');
    }

    /**
     * Omitting customer_timezone entirely should result in NULL (backward compat).
     */
    public function testMissingTimezoneIsNull(): void
    {
        $slot = $this->getFirstAvailableSlot('next Monday');

        // Build payload WITHOUT customer_timezone
        $payload = [
            'service_id'     => self::$seed['service_id'],
            'staff_id'       => self::$seed['staff_id'],
            'start_datetime' => $slot['date'] . 'T' . $slot['time'] . ':00',
            'customer'       => [
                'name'  => 'No TZ Tester',
                'email' => 'notz-' . substr(Ulid::generate(), -6) . '@example.com',
            ],
            'consent_given' => true,
            '__ts'          => (time() - 10) * 1000,
        ];

        $csrf = $this->fetchCsrfContext();
        $res = $this->httpPostJsonWithCsrf('/api/' . self::$seed['slug'] . '/bookings', $payload, $csrf);
        $this->assertSame(201, $res['code'], 'Booking without TZ field must succeed. Body: ' . $res['body']);

        $data = json_decode($res['body'], true);
        $bookingId = $data['booking']['id'];
        $this->cleanupIds[] = ['bookings', $bookingId];

        $rows = Database::query(
            'SELECT `customer_timezone` FROM `bookings` WHERE `id` = ?',
            [$bookingId]
        );

        $this->assertNull($rows[0]['customer_timezone'],
            'Missing customer_timezone must result in NULL (backward compatibility)');
    }

    // ════════════════════════════════════════════════════════════════
    // Tenant-authoritative storage: customer_timezone must NOT
    // alter the stored start_datetime
    // ════════════════════════════════════════════════════════════════

    /**
     * The stored start_datetime must match the submitted value verbatim,
     * regardless of customer_timezone. The server does NOT convert.
     */
    public function testCustomerTimezoneDoesNotAlterStoredTime(): void
    {
        $slot = $this->getFirstAvailableSlot('next Tuesday');
        $submittedDt = $slot['date'] . 'T' . $slot['time'] . ':00';

        $payload = $this->buildPayload($slot, [
            'customer_timezone' => 'Pacific/Auckland', // UTC+12/+13 — large offset
        ]);

        $csrf = $this->fetchCsrfContext();
        $res = $this->httpPostJsonWithCsrf('/api/' . self::$seed['slug'] . '/bookings', $payload, $csrf);
        $this->assertSame(201, $res['code'], 'Body: ' . $res['body']);

        $data = json_decode($res['body'], true);
        $bookingId = $data['booking']['id'];
        $this->cleanupIds[] = ['bookings', $bookingId];

        // Verify the stored date and time match the submitted tenant-time values
        $rows = Database::query(
            'SELECT `start_datetime`, `customer_timezone` FROM `bookings` WHERE `id` = ?',
            [$bookingId]
        );

        $storedDt = new \DateTimeImmutable($rows[0]['start_datetime']);
        $this->assertSame($slot['date'], $storedDt->format('Y-m-d'),
            'Stored date must match submitted tenant date — server must NOT convert');
        $this->assertSame($slot['time'], $storedDt->format('H:i'),
            'Stored start_time must match submitted tenant time — server must NOT convert');
        $this->assertSame('Pacific/Auckland', $rows[0]['customer_timezone'],
            'customer_timezone must be stored as metadata only');
    }

    // ════════════════════════════════════════════════════════════════
    // Date boundary: late-night bookings
    // ════════════════════════════════════════════════════════════════

    /**
     * A booking at a late time slot stores correctly without date drift.
     * This tests the server-side storage, not client-side conversion.
     *
     * The seed availability runs 09:00–21:00, so the last 30-min slot is 20:30.
     */
    public function testLateBoundarySlotStoresCorrectly(): void
    {
        // Fetch all slots and pick the last one (closest to the date boundary)
        $date = (new \DateTimeImmutable('next Monday'))->format('Y-m-d');
        $res = $this->httpGetJson('/api/' . self::$seed['slug'] . '/availability?date=' . $date);
        $this->assertSame(200, $res['code'], 'Body: ' . $res['body']);

        $data = json_decode($res['body'], true);
        $slots = $data['slots'] ?? [];
        $this->assertNotEmpty($slots, 'Monday must have slots');

        // Get the last slot (latest time)
        $lastSlot = end($slots);
        $lastSlot['date'] = $date;

        $payload = $this->buildPayload($lastSlot, [
            'customer_timezone' => 'America/Los_Angeles', // UTC-7/-8 — would be previous day
        ]);

        $csrf = $this->fetchCsrfContext();
        $res = $this->httpPostJsonWithCsrf('/api/' . self::$seed['slug'] . '/bookings', $payload, $csrf);
        $this->assertSame(201, $res['code'], 'Late slot booking must succeed. Body: ' . $res['body']);

        $booking = json_decode($res['body'], true)['booking'];
        $this->cleanupIds[] = ['bookings', $booking['id']];

        // The stored date must be the same as the submitted date (no date drift)
        $this->assertSame($date, $booking['date'],
            'Late slot must NOT cause date drift in stored booking');
        $this->assertSame($lastSlot['time'], $booking['time'],
            'Late slot time must be preserved verbatim');
    }

    // ════════════════════════════════════════════════════════════════
    // Booking page config injects tenant timezone
    // ════════════════════════════════════════════════════════════════

    /**
     * The booking page HTML must inject the tenant timezone into
     * __VB_CONFIG__ so the frontend can initialize timezone conversion.
     */
    public function testBookingPageInjectsTenantTimezone(): void
    {
        $res = $this->httpGet('/book/' . self::$seed['slug']);

        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('"timezone":"Europe/Amsterdam"', $res['body'],
            'Booking page config must include tenant timezone for frontend TZ engine');
    }

    // ════════════════════════════════════════════════════════════════
    // Helpers
    // ════════════════════════════════════════════════════════════════

    private function buildPayload(array $slot, array $extra = []): array
    {
        return array_merge([
            'service_id'     => self::$seed['service_id'],
            'staff_id'       => self::$seed['staff_id'],
            'start_datetime' => $slot['date'] . 'T' . $slot['time'] . ':00',
            'customer'       => [
                'name'  => 'TZ Tester',
                'email' => 'tz-test-' . substr(Ulid::generate(), -6) . '@example.com',
            ],
            'consent_given' => true,
            '__ts'          => (time() - 10) * 1000,
        ], $extra);
    }

    private function getFirstAvailableSlot(string $relativeDay, ?string $staffId = null): array
    {
        $date = (new \DateTimeImmutable($relativeDay))->format('Y-m-d');
        $params = '?date=' . $date;
        if ($staffId !== null) {
            $params .= '&staff_id=' . $staffId;
        }

        $res = $this->httpGetJson('/api/' . self::$seed['slug'] . '/availability' . $params);

        $this->assertSame(
            200, $res['code'],
            "Availability for {$relativeDay} ({$date}) must return 200. Got {$res['code']}. Body: {$res['body']}"
        );

        $data = json_decode($res['body'], true);
        $this->assertNotEmpty($data['slots'] ?? [], "{$relativeDay} ({$date}) must have available slots");

        $slot = $data['slots'][0];
        $slot['date'] = $date;

        return $slot;
    }

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

    private function httpPostJsonWithCsrf(string $path, array $payload, array $csrf): array
    {
        return $this->request('POST', $path, $payload, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-CSRF-Token: ' . $csrf['token'],
        ], $csrf['cookieFile']);
    }

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


        return [
            'code'    => $code,
            'headers' => substr($response, 0, $headerSize),
            'body'    => substr($response, $headerSize),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    // Seed data — same as BookingFlowTest but extended hours for
    // boundary testing (09:00–21:00)
    // ════════════════════════════════════════════════════════════════

    private static function seedTzTestTenant(): array
    {
        $tenantId = Ulid::generate();
        $serviceId = Ulid::generate();
        $staffId = Ulid::generate();
        $slug = 'test-tz-' . substr($tenantId, -8);

        Database::execute(
            "INSERT INTO `tenants` (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`,
             `brand_color`, `timezone`, `locale`, `currency`, `requires_consent`, `consent_text`,
             `slot_duration_minutes`, `max_advance_days`)
             VALUES (?, ?, 'TZ Test Business', 'tz@test.test', 'timeslot', 'active',
             '#3B82F6', 'Europe/Amsterdam', 'en', 'EUR', 1,
             'I agree to the processing of my personal data.', 30, 90)",
            [$tenantId, $slug]
        );

        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `description`, `duration_minutes`, `price`, `is_active`, `sort_order`)
             VALUES (?, ?, 'TZ Consultation', 'Timezone test service', 30, 50.00, 1, 1)",
            [$serviceId, $tenantId]
        );

        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`, `sort_order`)
             VALUES (?, ?, 'TZ Tester', 'tz-staff@test.test', 1, 1)",
            [$staffId, $tenantId]
        );

        Database::execute(
            'INSERT INTO `service_staff` (`service_id`, `staff_id`) VALUES (?, ?)',
            [$serviceId, $staffId]
        );

        // Extended hours: Mon–Fri 09:00–21:00 for date boundary testing
        for ($day = 0; $day <= 4; $day++) {
            Database::execute(
                "INSERT INTO `availability` (`id`, `tenant_id`, `day_of_week`, `start_time`, `end_time`)
                 VALUES (?, ?, ?, '09:00', '21:00')",
                [Ulid::generate(), $tenantId, $day]
            );
        }

        return [
            'slug'       => $slug,
            'tenant_id'  => $tenantId,
            'service_id' => $serviceId,
            'staff_id'   => $staffId,
        ];
    }
}
