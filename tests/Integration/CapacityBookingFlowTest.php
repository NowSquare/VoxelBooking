<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for capacity-pattern booking flows.
 *
 * Covers:
 * - Public API: POST /api/{slug}/bookings (capacity pattern)
 * - Public API: capacity-exceeded conflict detection
 * - Admin: GET  /admin/tenants/{tenant_id}/bookings/create → capacity form
 * - Admin: POST /admin/tenants/{tenant_id}/bookings/create → capacity booking
 * - Admin: capacity slot management CRUD + role gate
 *
 * Uses real HTTP against the running app at APP_TEST_URL.
 */
final class CapacityBookingFlowTest extends TestCase
{
    private static string $baseUrl;
    private static bool $appReachable = false;
    private static bool $dbReady = false;

    // Seed data
    private static string $tenantId = '';
    private static string $slug = '';
    private static string $slotId = '';

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
            self::seedCapacityData();
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
            Database::execute('DELETE FROM `bookings` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `capacity_slots` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `blocked_dates` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `customers` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        } catch (\Throwable) {
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Public API — capacity booking
    // ════════════════════════════════════════════════════════════════

    public function testPublicCapacityBookingSuccess(): void
    {
        // Use a Monday (slot is configured for Monday)
        $nextMonday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Monday');
        $date = $nextMonday->format('Y-m-d');

        $csrf = $this->fetchPublicCsrf();

        $res = $this->httpPostJsonWithCsrf('/api/' . self::$slug . '/bookings', [
            'slot_id'           => self::$slotId,
            'date'              => $date,
            'party_size'        => 4,
            'customer'          => [
                'name'  => 'Capacity Flow Test',
                'email' => 'cap-flow-' . substr(Ulid::generate(), -6) . '@example.com',
                'phone' => '+39 333 000 001',
            ],
            'notes'             => 'Integration test capacity booking',
            'consent_given'     => true,
            'customer_timezone' => 'Europe/Rome',
            '__ts'              => (string) ((time() - 10) * 1000),
            '__hp'              => '',
        ], $csrf);

        $this->assertSame(201, $res['code'], 'Capacity booking must return 201. Body: ' . $res['body']);
        $data = json_decode($res['body'], true);
        $this->assertArrayHasKey('booking', $data);
        $this->assertSame($date, $data['booking']['date']);
        $this->assertSame(4, $data['booking']['party_size']);

        $this->cleanupIds[] = ['bookings', $data['booking']['id']];
    }

    public function testPublicCapacityBookingExceedsCapacity(): void
    {
        $nextMonday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('next Monday');
        $date = $nextMonday->format('Y-m-d');

        // Fill the slot to near-capacity by adding a large booking directly
        $customerId = Ulid::generate();
        Database::execute(
            "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`) VALUES (?, ?, 'Fill Test', 'fill@test.test')",
            [$customerId, self::$tenantId]
        );
        $this->cleanupIds[] = ['customers', $customerId];

        $fillBookingId = Ulid::generate();
        Database::execute(
            "INSERT INTO `bookings` (`id`, `tenant_id`, `customer_id`, `booking_pattern`, `start_datetime`, `end_datetime`, `party_size`, `status`, `source`)
             VALUES (?, ?, ?, 'capacity', ?, ?, 18, 'confirmed', 'web')",
            [$fillBookingId, self::$tenantId, $customerId, "{$date} 19:00:00", "{$date} 21:00:00"]
        );
        $this->cleanupIds[] = ['bookings', $fillBookingId];

        // Now try to book party of 5 — only 2 remaining, should be rejected
        $csrf = $this->fetchPublicCsrf();

        $res = $this->httpPostJsonWithCsrf('/api/' . self::$slug . '/bookings', [
            'slot_id'           => self::$slotId,
            'date'              => $date,
            'party_size'        => 5,
            'customer'          => [
                'name'  => 'Over Capacity Test',
                'email' => 'over-cap-' . substr(Ulid::generate(), -6) . '@example.com',
                'phone' => '+39 333 000 002',
            ],
            'consent_given'     => true,
            'customer_timezone' => 'Europe/Rome',
            '__ts'              => (string) ((time() - 10) * 1000),
            '__hp'              => '',
        ], $csrf);

        $this->assertSame(409, $res['code'], 'Over-capacity booking must return 409. Body: ' . $res['body']);
        $data = json_decode($res['body'], true);
        $this->assertArrayHasKey('error', $data);
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Admin — capacity manual booking
    // ════════════════════════════════════════════════════════════════

    public function testAdminCreateFormLoadsCapacityPattern(): void
    {
        $res = self::httpGet('/admin/tenants/' . self::$tenantId . '/bookings/create');

        $this->assertSame(200, $res['code'], 'Create form must return 200');
        $this->assertStringContainsString('create_slot_id', $res['body'], 'Form must contain slot select');
        $this->assertStringContainsString('create_party_size', $res['body'], 'Form must contain party size input');
        $this->assertStringContainsString('create_date', $res['body'], 'Form must contain date input');
        // Must NOT contain timeslot or resource fields
        $this->assertStringNotContainsString('create_service_id', $res['body'], 'Form must not contain service select');
        $this->assertStringNotContainsString('create_resource_id', $res['body'], 'Form must not contain resource select');
    }

    public function testAdminCapacityBookingSuccess(): void
    {
        $nextMonday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))->modify('+2 weeks Monday');
        $date = $nextMonday->format('Y-m-d');

        $res = self::httpPost('/admin/tenants/' . self::$tenantId . '/bookings/create', [
            '_csrf_token'    => self::$operatorCsrf,
            'slot_id'        => self::$slotId,
            'date'           => $date,
            'party_size'     => '3',
            'customer_name'  => 'Admin Capacity Test',
            'customer_email' => 'admin-cap-' . substr(Ulid::generate(), -6) . '@test.test',
            'customer_phone' => '+39 333 000 003',
            'notes'          => 'Admin capacity test',
        ]);

        $this->assertSame(302, $res['code'], 'Successful creation must redirect. Body: ' . $res['body']);
        $this->assertStringNotContainsString('/create', $res['headers'], 'Must not redirect back to create form');

        // Verify booking in DB
        $bookings = Database::query(
            "SELECT * FROM `bookings` WHERE `tenant_id` = ? AND `source` = 'admin' AND `booking_pattern` = 'capacity' ORDER BY `created_at` DESC LIMIT 1",
            [self::$tenantId]
        );

        $this->assertNotEmpty($bookings, 'Admin capacity booking must exist in DB');
        $booking = $bookings[0];
        $this->assertSame('capacity', $booking['booking_pattern']);
        $this->assertSame('admin', $booking['source']);
        $this->assertSame(3, (int) $booking['party_size']);

        $this->cleanupIds[] = ['bookings', $booking['id']];
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Admin — capacity slot management
    // ════════════════════════════════════════════════════════════════

    public function testAdminCapacitySlotsPageLoads(): void
    {
        $res = self::httpGet('/admin/tenants/' . self::$tenantId . '/capacity-slots');
        $this->assertSame(200, $res['code'], 'Capacity slots page must return 200');
        $this->assertStringContainsString('capacity-slots-table', $res['body'], 'Must contain slots table');
    }

    public function testAdminCreateCapacitySlot(): void
    {
        $res = self::httpPost('/admin/tenants/' . self::$tenantId . '/capacity-slots', [
            '_csrf_token'    => self::$operatorCsrf,
            'day_of_week'    => '4', // Friday
            'start_time'     => '20:00',
            'end_time'       => '22:00',
            'max_capacity'   => '15',
            'max_party_size' => '6',
            'label'          => 'Late Dinner',
        ]);

        $this->assertSame(302, $res['code'], 'Slot creation must redirect');

        // Verify in DB
        $slot = Database::query(
            "SELECT * FROM `capacity_slots` WHERE `tenant_id` = ? AND `day_of_week` = 4 AND `label` = 'Late Dinner'",
            [self::$tenantId]
        );
        $this->assertNotEmpty($slot, 'New capacity slot must exist in DB');
        $this->assertSame(15, (int) $slot[0]['max_capacity']);
        $this->assertSame(6, (int) $slot[0]['max_party_size']);

        // Verify audit log
        $audit = Database::query(
            "SELECT * FROM `audit_log` WHERE `entity_type` = 'capacity_slot' AND `entity_id` = ? ORDER BY `created_at` DESC LIMIT 1",
            [$slot[0]['id']]
        );
        $this->assertNotEmpty($audit, 'Audit log entry must exist for slot creation');
        $this->assertSame('capacity_slot.created', $audit[0]['action']);

        $this->cleanupIds[] = ['capacity_slots', $slot[0]['id']];
    }

    public function testAdminDeleteCapacitySlot(): void
    {
        // Create a slot first
        $deleteSlotId = Ulid::generate();
        Database::execute(
            "INSERT INTO `capacity_slots` (`id`, `tenant_id`, `day_of_week`, `start_time`, `end_time`, `max_capacity`, `max_party_size`, `label`)
             VALUES (?, ?, 5, '21:00:00', '23:00:00', 10, 4, 'Delete Test')",
            [$deleteSlotId, self::$tenantId]
        );

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/capacity-slots/{$deleteSlotId}/delete", [
            '_csrf_token' => self::$operatorCsrf,
        ]);

        $this->assertSame(302, $res['code'], 'Slot deletion must redirect');

        // Verify deleted
        $slot = Database::query(
            'SELECT * FROM `capacity_slots` WHERE `id` = ?',
            [$deleteSlotId]
        );
        $this->assertEmpty($slot, 'Slot must be deleted from DB');
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Admin — capacity slot validation
    // ════════════════════════════════════════════════════════════════

    public function testAdminRejectsOversizedCapacity(): void
    {
        $res = self::httpPost('/admin/tenants/' . self::$tenantId . '/capacity-slots', [
            '_csrf_token'    => self::$operatorCsrf,
            'day_of_week'    => '2',
            'start_time'     => '12:00',
            'end_time'       => '14:00',
            'max_capacity'   => '2000000000000',
            'min_party_size' => '1',
            'max_party_size' => '8',
            'label'          => 'Overflow Test',
        ]);

        $this->assertSame(302, $res['code'], 'Oversized capacity must redirect, not 500');

        // Verify no slot was created
        $slot = Database::query(
            "SELECT * FROM `capacity_slots` WHERE `tenant_id` = ? AND `label` = 'Overflow Test'",
            [self::$tenantId]
        );
        $this->assertEmpty($slot, 'Oversized capacity must not create a slot');
    }

    public function testAdminRejectsOversizedPartySize(): void
    {
        $res = self::httpPost('/admin/tenants/' . self::$tenantId . '/capacity-slots', [
            '_csrf_token'    => self::$operatorCsrf,
            'day_of_week'    => '2',
            'start_time'     => '12:00',
            'end_time'       => '14:00',
            'max_capacity'   => '50',
            'min_party_size' => '1',
            'max_party_size' => '50000',
            'label'          => 'Party Overflow',
        ]);

        $this->assertSame(302, $res['code'], 'Oversized party size must redirect, not 500');

        $slot = Database::query(
            "SELECT * FROM `capacity_slots` WHERE `tenant_id` = ? AND `label` = 'Party Overflow'",
            [self::$tenantId]
        );
        $this->assertEmpty($slot, 'Oversized party size must not create a slot');
    }

    public function testAdminRejectsDuplicateSlot(): void
    {
        // The seed already has Monday 19:00-21:00. Try to create the exact same window.
        $res = self::httpPost('/admin/tenants/' . self::$tenantId . '/capacity-slots', [
            '_csrf_token'    => self::$operatorCsrf,
            'day_of_week'    => '0',
            'start_time'     => '19:00',
            'end_time'       => '21:00',
            'max_capacity'   => '30',
            'min_party_size' => '1',
            'max_party_size' => '6',
            'label'          => 'Duplicate Test',
        ]);

        $this->assertSame(302, $res['code'], 'Duplicate slot must redirect, not 500');

        // Verify the duplicate was not created (only the original should exist)
        $slots = Database::query(
            "SELECT * FROM `capacity_slots` WHERE `tenant_id` = ? AND `day_of_week` = 0 AND `start_time` = '19:00:00' AND `end_time` = '21:00:00'",
            [self::$tenantId]
        );
        $this->assertCount(1, $slots, 'Only the original slot must exist, no duplicate');
        $this->assertSame('Dinner Service', $slots[0]['label'], 'The original slot must be unchanged');
    }

    public function testAdminRejectsMinExceedsMaxPartySize(): void
    {
        $res = self::httpPost('/admin/tenants/' . self::$tenantId . '/capacity-slots', [
            '_csrf_token'    => self::$operatorCsrf,
            'day_of_week'    => '3',
            'start_time'     => '10:00',
            'end_time'       => '12:00',
            'max_capacity'   => '50',
            'min_party_size' => '10',
            'max_party_size' => '5',
            'label'          => 'Min Exceeds Max',
        ]);

        $this->assertSame(302, $res['code'], 'Min > max party size must redirect, not 500');

        $slot = Database::query(
            "SELECT * FROM `capacity_slots` WHERE `tenant_id` = ? AND `label` = 'Min Exceeds Max'",
            [self::$tenantId]
        );
        $this->assertEmpty($slot, 'Invalid party size range must not create a slot');
    }

    public function testAdminRejectsPartyExceedsCapacity(): void
    {
        $res = self::httpPost('/admin/tenants/' . self::$tenantId . '/capacity-slots', [
            '_csrf_token'    => self::$operatorCsrf,
            'day_of_week'    => '3',
            'start_time'     => '14:00',
            'end_time'       => '16:00',
            'max_capacity'   => '10',
            'min_party_size' => '1',
            'max_party_size' => '15',
            'label'          => 'Party Exceeds Cap',
        ]);

        $this->assertSame(302, $res['code'], 'Party > capacity must redirect, not 500');

        $slot = Database::query(
            "SELECT * FROM `capacity_slots` WHERE `tenant_id` = ? AND `label` = 'Party Exceeds Cap'",
            [self::$tenantId]
        );
        $this->assertEmpty($slot, 'Party size > capacity must not create a slot');
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Public page shell — translated controls
    // ════════════════════════════════════════════════════════════════

    public function testPublicPageShellRendersTranslatedControls(): void
    {
        $ch = curl_init(self::$baseUrl . '/book/' . self::$slug);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        $this->assertSame(200, $code, 'Booking page must return 200');

        // The page must NOT contain raw i18n key fallbacks
        $this->assertStringNotContainsString("buttons.back", $body, 'Must not show raw buttons.back key');

        // The i18n blob injected into window.__VB_I18N__ must contain
        // the translated back-navigation and spots_remaining keys
        $this->assertStringContainsString('change_party_size', $body, 'I18n blob must contain capacity back-nav key');
        $this->assertStringContainsString('change_date_cap', $body, 'I18n blob must contain capacity date back-nav key');
        $this->assertStringContainsString('spots_remaining', $body, 'I18n blob must contain spots_remaining key');

        // The capacity pattern config must be present
        $this->assertStringContainsString('"booking_pattern":"capacity"', $body, 'Page must inject capacity booking_pattern');

        // Calendar month-nav buttons must have aria-labels (accessibility)
        $this->assertStringContainsString('aria-label="Previous month"', $body, 'Prev-month button must have aria-label');
        $this->assertStringContainsString('aria-label="Next month"', $body, 'Next-month button must have aria-label');
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Manager role gate — capacity-slots denied
    // ════════════════════════════════════════════════════════════════

    public function testManagerDeniedCapacitySlots(): void
    {
        // Create a temporary manager for this tenant
        $managerId = Ulid::generate();
        $managerEmail = 'cap-mgr-' . substr($managerId, -6) . '@test.test';
        $hash = password_hash('ManagerTest123!', PASSWORD_BCRYPT, ['cost' => 12]);

        Database::execute(
            "INSERT INTO `business_users` (`id`, `tenant_id`, `name`, `email`, `password_hash`, `role`, `is_active`, `force_password_change`)
             VALUES (?, ?, 'Capacity Manager Test', ?, ?, 'manager', 1, 0)",
            [$managerId, self::$tenantId, $managerEmail, $hash]
        );
        Database::execute(
            "DELETE FROM `auth_emails` WHERE `email` = ?",
            [$managerEmail]
        );
        Database::execute(
            "INSERT INTO `auth_emails` (`email`, `user_type`, `user_id`) VALUES (?, 'business_user', ?)",
            [$managerEmail, $managerId]
        );

        try {
            // Login as manager
            $loginPage = self::httpGetUnauthenticated('/admin/login');
            $csrfToken = '';
            if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $loginPage['body'], $m)) {
                $csrfToken = $m[1];
            }

            $mgrCookie = '';
            $loginRes = self::httpPostUnauthenticated('/admin/login', [
                '_csrf_token' => $csrfToken,
                'email'       => $managerEmail,
                'password'    => 'ManagerTest123!',
            ]);
            if (preg_match('/vb_session=([^;]+)/', $loginRes['headers'], $m)) {
                $mgrCookie = 'vb_session=' . $m[1];
            }

            // GET capacity-slots as manager — must be denied (403 or redirect)
            $res = self::httpGetWithCookie('/admin/tenants/' . self::$tenantId . '/capacity-slots', $mgrCookie);
            $this->assertContains(
                $res['code'],
                [403, 302],
                'Manager must be denied on capacity-slots, got ' . $res['code']
            );
        } finally {
            Database::execute('DELETE FROM `auth_emails` WHERE `email` = ?', [$managerEmail]);
            Database::execute('DELETE FROM `business_users` WHERE `id` = ?', [$managerId]);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Public CSRF helper
    // ════════════════════════════════════════════════════════════════

    private function fetchPublicCsrf(): array
    {
        $cookieFile = sys_get_temp_dir() . '/vb_cap_csrf_' . bin2hex(random_bytes(8)) . '.txt';

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

        $this->assertNotEmpty($token, 'CSRF token must be present in capacity booking page');

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

    /** @return array{code: int, headers: string, body: string} */
    private static function httpGet(string $path): array
    {
        return self::http('GET', $path);
    }

    /** @return array{code: int, headers: string, body: string} */
    private static function httpPost(string $path, array $data): array
    {
        return self::http('POST', $path, $data);
    }

    /** @return array{code: int, headers: string, body: string} */
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

    /**
     * Unauthenticated GET — no session cookie injected.
     * @return array{code: int, headers: string, body: string}
     */
    private static function httpGetUnauthenticated(string $path): array
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

    /**
     * Unauthenticated POST — no session cookie injected, returns Set-Cookie.
     * @return array{code: int, headers: string, body: string}
     */
    private static function httpPostUnauthenticated(string $path, array $data): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
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

    /**
     * GET with explicit session cookie — for role-gating tests.
     * @return array{code: int, headers: string, body: string}
     */
    private static function httpGetWithCookie(string $path, string $cookie): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => ['Cookie: ' . $cookie],
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
    // Seed + auth helpers
    // ════════════════════════════════════════════════════════════════

    private static function seedCapacityData(): void
    {
        self::$tenantId = Ulid::generate();
        self::$slotId = Ulid::generate();
        self::$slug = 'test-cap-flow-' . substr(self::$tenantId, -8);

        // Tenant with capacity pattern
        Database::execute(
            "INSERT INTO `tenants` (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`,
             `brand_color`, `timezone`, `locale`, `currency`, `requires_consent`, `consent_text`,
             `max_advance_days`)
             VALUES (?, ?, 'Capacity Flow Test', 'cap-flow@test.test', 'capacity', 'active',
             '#D32F2F', 'Europe/Rome', 'en', 'EUR', 1,
             'I consent to the processing of my personal data.', 90)",
            [self::$tenantId, self::$slug]
        );

        // Capacity slot: Monday 19:00-21:00, capacity 20, max party 8
        Database::execute(
            "INSERT INTO `capacity_slots` (`id`, `tenant_id`, `day_of_week`, `start_time`, `end_time`, `max_capacity`, `max_party_size`, `label`, `is_active`)
             VALUES (?, ?, 0, '19:00:00', '21:00:00', 20, 8, 'Dinner Service', 1)",
            [self::$slotId, self::$tenantId]
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

