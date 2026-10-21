<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for resource-pattern booking flows.
 *
 * Covers:
 * - Public API: POST /api/{slug}/bookings (resource pattern)
 * - Public API: conflict detection on overlapping stays
 * - Admin: GET  /admin/tenants/{tenant_id}/bookings/create → resource form
 * - Admin: POST /admin/tenants/{tenant_id}/bookings/create → resource booking
 * - Admin: conflict detection on overlapping stays
 *
 * Uses real HTTP against the running app at APP_TEST_URL.
 */
final class ResourceBookingFlowTest extends TestCase
{
    private static string $baseUrl;
    private static bool $appReachable = false;
    private static bool $dbReady = false;

    // Seed data
    private static string $tenantId = '';
    private static string $slug = '';
    private static string $resourceId = '';

    // Auth state for operator
    private static string $operatorCookie = '';
    private static string $operatorCsrf = '';

    /** @var list<array{0: string, 1: string}> */
    private array $cleanupIds = [];

    private static string $setupError = '';

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
            self::seedResourceData();
            self::loginOperator();
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
            $this->markTestSkipped(self::$setupError ?: 'Database or seed data not available');
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanupIds) as [$table, $id]) {
            try {
                Database::execute("DELETE FROM `{$table}` WHERE `id` = ?", [$id]);
            } catch (\Throwable) {
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$tenantId === '') {
            return;
        }

        try {
            Database::execute('DELETE FROM `blocked_dates` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `bookings` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `customers` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `resources` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        } catch (\Throwable) {
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Public API — resource booking
    // ════════════════════════════════════════════════════════════════

    public function testPublicResourceBookingSuccess(): void
    {
        $checkIn  = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $checkOut = (new \DateTimeImmutable('+12 days'))->format('Y-m-d');

        $csrf = $this->fetchPublicCsrf();

        $res = $this->httpPostJsonWithCsrf('/api/' . self::$slug . '/bookings', [
            'resource_id'       => self::$resourceId,
            'check_in'          => $checkIn,
            'check_out'         => $checkOut,
            'guest_count'       => 1,
            'customer'          => [
                'name'  => 'Resource Flow Test',
                'email' => 'res-flow-' . substr(Ulid::generate(), -6) . '@example.com',
                'phone' => '+31 600 000 001',
            ],
            'notes'             => 'Integration test booking',
            'consent_given'     => true,
            'customer_timezone' => 'Europe/Amsterdam',
            '__ts'              => (string) ((time() - 10) * 1000),
            '__hp'              => '',
        ], $csrf);

        $this->assertSame(201, $res['code'], 'Resource booking must return 201. Body: ' . $res['body']);
        $data = json_decode($res['body'], true);
        $this->assertArrayHasKey('booking', $data);
        $this->assertSame($checkIn, $data['booking']['check_in']);
        $this->assertSame($checkOut, $data['booking']['check_out']);
        $this->assertSame(2, $data['booking']['nights']);

        $this->cleanupIds[] = ['bookings', $data['booking']['id']];
    }

    public function testPublicResourceBookingConflict(): void
    {
        $checkIn  = (new \DateTimeImmutable('+20 days'))->format('Y-m-d');
        $checkOut = (new \DateTimeImmutable('+22 days'))->format('Y-m-d');

        // First booking — must succeed
        $csrf1 = $this->fetchPublicCsrf();
        $res1 = $this->httpPostJsonWithCsrf('/api/' . self::$slug . '/bookings', [
            'resource_id'       => self::$resourceId,
            'check_in'          => $checkIn,
            'check_out'         => $checkOut,
            'guest_count'       => 1,
            'customer'          => [
                'name'  => 'Conflict Test A',
                'email' => 'conflict-a-' . substr(Ulid::generate(), -6) . '@example.com',
                'phone' => '+31 600 000 002',
            ],
            'consent_given'     => true,
            'customer_timezone' => 'Europe/Amsterdam',
            '__ts'              => (string) ((time() - 10) * 1000),
            '__hp'              => '',
        ], $csrf1);

        $this->assertSame(201, $res1['code'], 'First booking must succeed. Body: ' . $res1['body']);
        $data1 = json_decode($res1['body'], true);
        $this->cleanupIds[] = ['bookings', $data1['booking']['id']];

        // Second booking — overlapping dates, must be rejected
        $csrf2 = $this->fetchPublicCsrf();
        $overlapIn  = (new \DateTimeImmutable('+21 days'))->format('Y-m-d');
        $overlapOut = (new \DateTimeImmutable('+23 days'))->format('Y-m-d');

        $res2 = $this->httpPostJsonWithCsrf('/api/' . self::$slug . '/bookings', [
            'resource_id'       => self::$resourceId,
            'check_in'          => $overlapIn,
            'check_out'         => $overlapOut,
            'guest_count'       => 1,
            'customer'          => [
                'name'  => 'Conflict Test B',
                'email' => 'conflict-b-' . substr(Ulid::generate(), -6) . '@example.com',
                'phone' => '+31 600 000 003',
            ],
            'consent_given'     => true,
            'customer_timezone' => 'Europe/Amsterdam',
            '__ts'              => (string) ((time() - 10) * 1000),
            '__hp'              => '',
        ], $csrf2);

        $this->assertSame(409, $res2['code'], 'Overlapping booking must return 409. Body: ' . $res2['body']);
        $data2 = json_decode($res2['body'], true);
        $this->assertArrayHasKey('error', $data2, 'Conflict response must have error field');
        $this->assertStringContainsString('booked', $data2['error'], 'Error must indicate booking conflict');
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Admin — resource manual booking
    // ════════════════════════════════════════════════════════════════

    public function testAdminCreateFormLoadsResourcePattern(): void
    {
        $res = self::httpGet('/admin/tenants/' . self::$tenantId . '/bookings/create');

        $this->assertSame(200, $res['code'], 'Create form must return 200');
        $this->assertStringContainsString('create_resource_id', $res['body'], 'Form must contain resource select');
        $this->assertStringContainsString('create_check_in', $res['body'], 'Form must contain check-in input');
        $this->assertStringContainsString('create_check_out', $res['body'], 'Form must contain check-out input');
        $this->assertStringContainsString('create_guest_count', $res['body'], 'Form must contain guest count input');
        // Must NOT contain timeslot fields
        $this->assertStringNotContainsString('create_service_id', $res['body'], 'Form must not contain service select');
        $this->assertStringNotContainsString('create_time', $res['body'], 'Form must not contain time select');
    }

    public function testAdminResourceBookingSuccess(): void
    {
        $checkIn  = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $checkOut = (new \DateTimeImmutable('+32 days'))->format('Y-m-d');

        $res = self::httpPost('/admin/tenants/' . self::$tenantId . '/bookings/create', [
            '_csrf_token'    => self::$operatorCsrf,
            'resource_id'    => self::$resourceId,
            'check_in'       => $checkIn,
            'check_out'      => $checkOut,
            'guest_count'    => '1',
            'customer_name'  => 'Admin Resource Test',
            'customer_email' => 'admin-res-' . substr(Ulid::generate(), -6) . '@test.test',
            'customer_phone' => '+31 600 000 004',
            'notes'          => 'Admin resource test',
        ]);

        $this->assertSame(302, $res['code'], 'Successful creation must redirect. Body: ' . $res['body']);
        $this->assertStringNotContainsString('/create', $res['headers'], 'Must not redirect back to create form');

        // Verify booking in DB
        $bookings = Database::query(
            "SELECT * FROM `bookings` WHERE `tenant_id` = ? AND `source` = 'admin' AND `booking_pattern` = 'resource' ORDER BY `created_at` DESC LIMIT 1",
            [self::$tenantId]
        );

        $this->assertNotEmpty($bookings, 'Admin resource booking must exist in DB');
        $booking = $bookings[0];
        $this->assertSame('resource', $booking['booking_pattern']);
        $this->assertSame('admin', $booking['source']);
        $this->assertSame(self::$resourceId, $booking['resource_id']);
        $this->assertNull($booking['consent_given_at'], 'Admin bookings must not have consent');

        $this->cleanupIds[] = ['bookings', $booking['id']];
    }

    public function testAdminResourceBookingConflict(): void
    {
        $checkIn  = (new \DateTimeImmutable('+40 days'))->format('Y-m-d');
        $checkOut = (new \DateTimeImmutable('+42 days'))->format('Y-m-d');

        // First booking
        $res1 = self::httpPost('/admin/tenants/' . self::$tenantId . '/bookings/create', [
            '_csrf_token'    => self::$operatorCsrf,
            'resource_id'    => self::$resourceId,
            'check_in'       => $checkIn,
            'check_out'      => $checkOut,
            'guest_count'    => '1',
            'customer_name'  => 'Admin Conflict A',
            'customer_email' => 'admin-conflict-a-' . substr(Ulid::generate(), -6) . '@test.test',
        ]);

        $this->assertSame(302, $res1['code'], 'First admin booking must succeed');
        $this->assertStringNotContainsString('/create', $res1['headers']);

        $bookings = Database::query(
            "SELECT `id` FROM `bookings` WHERE `tenant_id` = ? AND `source` = 'admin' ORDER BY `created_at` DESC LIMIT 1",
            [self::$tenantId]
        );
        $this->assertNotEmpty($bookings);
        $this->cleanupIds[] = ['bookings', $bookings[0]['id']];

        // Second booking — overlapping dates, must be rejected
        $overlapIn  = (new \DateTimeImmutable('+41 days'))->format('Y-m-d');
        $overlapOut = (new \DateTimeImmutable('+43 days'))->format('Y-m-d');

        $res2 = self::httpPost('/admin/tenants/' . self::$tenantId . '/bookings/create', [
            '_csrf_token'    => self::$operatorCsrf,
            'resource_id'    => self::$resourceId,
            'check_in'       => $overlapIn,
            'check_out'      => $overlapOut,
            'guest_count'    => '1',
            'customer_name'  => 'Admin Conflict B',
            'customer_email' => 'admin-conflict-b-' . substr(Ulid::generate(), -6) . '@test.test',
        ]);

        $this->assertSame(302, $res2['code'], 'Conflict must redirect back');
        $this->assertStringContainsString('/create', $res2['headers'], 'Must redirect back to create form');
    }

    public function testAdminResourceValidationPreservesInput(): void
    {
        // Submit with missing customer name — validation fails, input preserved
        $res = self::httpPost('/admin/tenants/' . self::$tenantId . '/bookings/create', [
            '_csrf_token'    => self::$operatorCsrf,
            'resource_id'    => self::$resourceId,
            'check_in'       => '2026-09-01',
            'check_out'      => '2026-09-03',
            'guest_count'    => '2',
            'customer_name'  => '',
            'customer_email' => 'preserve@test.test',
        ]);

        $this->assertSame(302, $res['code'], 'Validation failure must redirect back');
        $this->assertStringContainsString('/create', $res['headers']);

        // Reload the form — old input should be visible
        $form = self::httpGet('/admin/tenants/' . self::$tenantId . '/bookings/create');
        $this->assertSame(200, $form['code']);
        $this->assertStringContainsString('2026-09-01', $form['body'], 'Check-in must be preserved');
        $this->assertStringContainsString('2026-09-03', $form['body'], 'Check-out must be preserved');
        $this->assertStringContainsString('preserve@test.test', $form['body'], 'Email must be preserved');
    }

    // ════════════════════════════════════════════════════════════════
    // Public CSRF helper
    // ════════════════════════════════════════════════════════════════

    private function fetchPublicCsrf(): array
    {
        $cookieFile = sys_get_temp_dir() . '/vb_res_csrf_' . bin2hex(random_bytes(8)) . '.txt';

        $ch = curl_init(self::$baseUrl . '/book/' . self::$slug);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
        ]);
        $body = (string) curl_exec($ch);
        unset($ch);

        preg_match('/window\.__VB_CSRF__\s*=\s*"([^"]+)"/', $body, $m);
        $token = $m[1] ?? '';

        $this->assertNotEmpty($token, 'CSRF token must be present in resource booking page');

        return ['token' => $token, 'cookieFile' => $cookieFile];
    }

    private function httpPostJsonWithCsrf(string $path, array $payload, array $csrf): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-CSRF-Token: ' . $csrf['token'],
            ],
            CURLOPT_COOKIEFILE     => $csrf['cookieFile'],
            CURLOPT_COOKIEJAR      => $csrf['cookieFile'],
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

    // ════════════════════════════════════════════════════════════════
    // Admin HTTP helpers
    // ════════════════════════════════════════════════════════════════

    /**
     * @return array{code: int, headers: string, body: string}
     */
    private static function httpGet(string $path): array
    {
        return self::http('GET', $path);
    }

    /**
     * @return array{code: int, headers: string, body: string}
     */
    private static function httpPost(string $path, array $data): array
    {
        return self::http('POST', $path, $data);
    }

    /**
     * @return array{code: int, headers: string, body: string}
     */
    private static function http(string $method, string $path, array $data = []): array
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
        if (self::$operatorCookie) {
            $headers[] = 'Cookie: ' . self::$operatorCookie;
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $data = TestFixtures::withCatalogVersion($path, $data, self::$operatorCookie);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);

        $responseHeaders = substr($response, 0, $headerSize);

        // Update session cookie
        if (preg_match('/vb_session=([^;]+)/', $responseHeaders, $m)) {
            self::$operatorCookie = 'vb_session=' . $m[1];
        }

        return [
            'code'    => $code,
            'headers' => $responseHeaders,
            'body'    => substr($response, $headerSize),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    // Seed + auth helpers
    // ════════════════════════════════════════════════════════════════

    private static function seedResourceData(): void
    {
        self::$tenantId = Ulid::generate();
        self::$resourceId = Ulid::generate();
        self::$slug = 'test-res-flow-' . substr(self::$tenantId, -8);

        // Tenant with resource pattern
        Database::execute(
            "INSERT INTO `tenants` (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`,
             `brand_color`, `timezone`, `locale`, `currency`, `requires_consent`, `consent_text`,
             `max_advance_days`)
             VALUES (?, ?, 'Resource Flow Test', 'res-flow@test.test', 'resource', 'active',
             '#0EA5E9', 'Europe/Amsterdam', 'en', 'EUR', 1,
             'I consent to the processing of my personal data.', 90)",
            [self::$tenantId, self::$slug]
        );

        // Resource: 2-guest room, min 1 night, max 14 nights
        Database::execute(
            "INSERT INTO `resources` (`id`, `tenant_id`, `name`, `description`, `capacity`,
             `price_per_night`, `min_stay_nights`, `max_stay_nights`, `amenities`, `sort_order`, `is_active`)
             VALUES (?, ?, 'Test Room', 'Integration test room', 2, 100.00, 1, 14, ?, 1, 1)",
            [self::$resourceId, self::$tenantId, json_encode(['Wi-Fi'])]
        );
    }

    private static function loginOperator(): void
    {
        // Get login page + CSRF token
        $loginPage = self::httpGet('/admin/login');
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $loginPage['body'], $m)) {
            self::$operatorCsrf = $m[1];
        }

        // Login
        $res = self::httpPost('/admin/login', [
            '_csrf_token' => self::$operatorCsrf,
            'email'       => TestFixtures::OPERATOR_EMAIL,
            'password'    => TestFixtures::OPERATOR_PASSWORD,
        ]);

        if (preg_match('/vb_session=([^;]+)/', $res['headers'], $m)) {
            self::$operatorCookie = 'vb_session=' . $m[1];
        }

        // Get fresh CSRF from dashboard
        $dashboard = self::httpGet('/admin');
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $dashboard['body'], $m)) {
            self::$operatorCsrf = $m[1];
        }
    }
}
