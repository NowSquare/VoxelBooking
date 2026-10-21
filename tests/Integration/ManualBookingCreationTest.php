<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for admin manual booking creation.
 *
 * Covers:
 * - GET  /admin/tenants/{tenant_id}/bookings/create → form loads for all roles
 * - POST /admin/tenants/{tenant_id}/bookings/create → creates booking (operator, owner, manager)
 * - Source is 'admin'
 * - Customer deduplication works
 * - Validation rejects missing fields
 * - Booking appears in DB + audit log
 * - "New booking" button visible on bookings list
 *
 * Uses real HTTP against the running app at APP_TEST_URL.
 */
final class ManualBookingCreationTest extends TestCase
{
    private static string $baseUrl;
    private static bool $appReachable = false;
    private static bool $dbReady = false;

    // Seed data
    private static string $tenantId = '';
    private static string $slug = '';
    private static string $serviceId = '';
    private static string $staffId = '';
    private static string $unlinkedStaffId = ''; // staff not linked to service via pivot

    // Auth state for operator
    private static string $operatorCookie = '';
    private static string $operatorCsrf = '';

    // Auth state for owner
    private static string $ownerCookie = '';
    private static string $ownerCsrf = '';
    private static string $ownerId = '';
    private static string $ownerEmail = '';

    // Auth state for manager
    private static string $managerCookie = '';
    private static string $managerCsrf = '';
    private static string $managerId = '';
    private static string $managerEmail = '';

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

        // Clear rate limits
        try {
            Database::execute('TRUNCATE TABLE `rate_limits`');
        } catch (\Throwable) {
        }

        try {
            // Provision test fixtures
            TestFixtures::provision();

            // Seed service + staff + availability for this test
            self::seedBookingData();

            // Login as operator
            self::loginOperator();

            // Create and login as owner
            self::createAndLoginOwner();

            // Create and login as manager
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
            Database::execute('DELETE FROM `service_staff` WHERE `service_id` = ?', [self::$serviceId]);
            Database::execute('DELETE FROM `availability` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `bookings` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `customers` WHERE `tenant_id` = ?', [self::$tenantId]);
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
    // Tests: Form accessibility
    // ════════════════════════════════════════════════════════════════

    public function testCreateFormLoadsForOperator(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/bookings/create", 'operator');

        $this->assertSame(200, $res['code'], 'Create form must return 200 for operator');
        $this->assertStringContainsString('create_service_id', $res['body'], 'Form must contain service select');
        $this->assertStringContainsString('create_customer_name', $res['body'], 'Form must contain customer name input');

        // CSP-safe Alpine migration assertions
        $this->assertStringContainsString('x-data="bookingCreate"', $res['body'], 'Must use CSP-safe Alpine.data() reference');
        $this->assertStringNotContainsString('function bookingCreate()', $res['body'], 'Inline script must be removed');
        $this->assertStringContainsString('data-slug=', $res['body'], 'Must have data-slug hydration attribute');
    }

    public function testCreateFormLoadsForOwner(): void
    {
        if (self::$ownerCookie === '') {
            $this->markTestSkipped('Owner login not available');
        }

        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/bookings/create", 'owner');

        $this->assertSame(200, $res['code'], 'Create form must return 200 for owner');
        $this->assertStringContainsString('create_service_id', $res['body']);
    }

    public function testCreateFormLoadsForManager(): void
    {
        if (self::$managerCookie === '') {
            $this->markTestSkipped('Manager login not available');
        }

        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/bookings/create", 'manager');

        $this->assertSame(200, $res['code'], 'Create form must return 200 for manager');
        $this->assertStringContainsString('create_service_id', $res['body']);
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Successful creation
    // ════════════════════════════════════════════════════════════════

    public function testOperatorCanCreateBooking(): void
    {
        $slot = $this->getAvailableSlot('next Monday');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/bookings/create", [
            '_csrf_token'    => self::$operatorCsrf,
            'service_id'     => self::$serviceId,
            'staff_id'       => self::$staffId,
            'date'           => $slot['date'],
            'time'           => $slot['time'],
            'customer_name'  => 'Operator Created Customer',
            'customer_email' => 'op-create-' . substr(Ulid::generate(), -6) . '@test.test',
            'customer_phone' => '+1234567890',
            'notes'          => 'Created by operator test',
        ], 'operator');

        // Should redirect to booking detail (302)
        $this->assertSame(302, $res['code'], 'Successful creation must redirect. Body: ' . $res['body']);
        $this->assertStringContainsString('/bookings/', $res['headers'], 'Must redirect to booking detail');

        // Verify booking was created with source='admin'
        $bookings = Database::query(
            "SELECT * FROM `bookings` WHERE `tenant_id` = ? AND `source` = 'admin' ORDER BY `created_at` DESC LIMIT 1",
            [self::$tenantId]
        );

        $this->assertNotEmpty($bookings, 'A booking with source=admin must exist');
        $booking = $bookings[0];
        $this->assertSame('admin', $booking['source']);
        $this->assertSame('timeslot', $booking['booking_pattern']);
        $this->assertNull($booking['consent_given_at'], 'Admin bookings must not have consent');
        $this->assertNull($booking['consent_text_shown'], 'Admin bookings must not have consent text');

        $this->cleanupIds[] = ['bookings', $booking['id']];
    }

    public function testOwnerCanCreateBooking(): void
    {
        if (self::$ownerCookie === '') {
            $this->markTestSkipped('Owner login not available');
        }

        $slot = $this->getAvailableSlot('next Tuesday');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/bookings/create", [
            '_csrf_token'    => self::$ownerCsrf,
            'service_id'     => self::$serviceId,
            'date'           => $slot['date'],
            'time'           => $slot['time'],
            'customer_name'  => 'Owner Created Customer',
            'customer_email' => 'owner-create-' . substr(Ulid::generate(), -6) . '@test.test',
        ], 'owner');

        $this->assertSame(302, $res['code'], 'Owner booking creation must redirect');

        $bookings = Database::query(
            "SELECT * FROM `bookings` WHERE `tenant_id` = ? AND `source` = 'admin' AND `notes` IS NULL ORDER BY `created_at` DESC LIMIT 1",
            [self::$tenantId]
        );
        $this->assertNotEmpty($bookings, 'Owner-created booking must exist');
        $this->cleanupIds[] = ['bookings', $bookings[0]['id']];
    }

    public function testManagerCanCreateBooking(): void
    {
        if (self::$managerCookie === '') {
            $this->markTestSkipped('Manager login not available');
        }

        $slot = $this->getAvailableSlot('next Wednesday');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/bookings/create", [
            '_csrf_token'    => self::$managerCsrf,
            'service_id'     => self::$serviceId,
            'date'           => $slot['date'],
            'time'           => $slot['time'],
            'customer_name'  => 'Manager Created Customer',
            'customer_email' => 'mgr-create-' . substr(Ulid::generate(), -6) . '@test.test',
        ], 'manager');

        $this->assertSame(302, $res['code'], 'Manager booking creation must redirect');
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Audit log
    // ════════════════════════════════════════════════════════════════

    public function testAuditLogContainsSourceAdmin(): void
    {
        $slot = $this->getAvailableSlot('next Thursday');
        $email = 'audit-test-' . substr(Ulid::generate(), -6) . '@test.test';

        self::httpPost("/admin/tenants/" . self::$tenantId . "/bookings/create", [
            '_csrf_token'    => self::$operatorCsrf,
            'service_id'     => self::$serviceId,
            'date'           => $slot['date'],
            'time'           => $slot['time'],
            'customer_name'  => 'Audit Test Customer',
            'customer_email' => $email,
        ], 'operator');

        // Find the booking
        $bookings = Database::query(
            "SELECT `id` FROM `bookings` WHERE `tenant_id` = ? AND `source` = 'admin' ORDER BY `created_at` DESC LIMIT 1",
            [self::$tenantId]
        );
        $this->assertNotEmpty($bookings);
        $this->cleanupIds[] = ['bookings', $bookings[0]['id']];

        // Check audit log — must contain booking.created AND source in details
        $logs = Database::query(
            "SELECT * FROM `audit_log` WHERE `entity_type` = 'booking' AND `entity_id` = ? AND `action` = 'booking.created'",
            [$bookings[0]['id']]
        );
        $this->assertNotEmpty($logs, 'Audit log must contain booking.created entry');

        $details = json_decode($logs[0]['details'], true);
        $this->assertIsArray($details);
        $this->assertArrayHasKey('source', $details, 'Audit details must include source');
        $this->assertSame('admin', $details['source'], 'Audit source must be admin');
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Customer deduplication
    // ════════════════════════════════════════════════════════════════

    public function testCustomerDeduplicationByEmail(): void
    {
        $email = 'dedup-test-' . substr(Ulid::generate(), -6) . '@test.test';
        $slot1 = $this->getAvailableSlot('next Monday');

        // Create first booking with this email
        self::httpPost("/admin/tenants/" . self::$tenantId . "/bookings/create", [
            '_csrf_token'    => self::$operatorCsrf,
            'service_id'     => self::$serviceId,
            'date'           => $slot1['date'],
            'time'           => $slot1['time'],
            'customer_name'  => 'First Name',
            'customer_email' => $email,
        ], 'operator');

        // Count customers with this email
        $count1 = Database::query(
            "SELECT COUNT(*) as cnt FROM `customers` WHERE `tenant_id` = ? AND `email` = ?",
            [self::$tenantId, $email]
        );
        $this->assertSame(1, (int) $count1[0]['cnt'], 'First booking should create one customer');

        $slot2 = $this->getAvailableSlot('next Tuesday');

        // Create second booking with the same email but different name
        self::httpPost("/admin/tenants/" . self::$tenantId . "/bookings/create", [
            '_csrf_token'    => self::$operatorCsrf,
            'service_id'     => self::$serviceId,
            'date'           => $slot2['date'],
            'time'           => $slot2['time'],
            'customer_name'  => 'Updated Name',
            'customer_email' => $email,
        ], 'operator');

        // Should still be one customer
        $count2 = Database::query(
            "SELECT COUNT(*) as cnt FROM `customers` WHERE `tenant_id` = ? AND `email` = ?",
            [self::$tenantId, $email]
        );
        $this->assertSame(1, (int) $count2[0]['cnt'], 'Second booking must reuse existing customer');

        // Booking submissions preserve existing identity; the submitted name belongs to this booking.
        $customer = Database::query(
            "SELECT `name` FROM `customers` WHERE `tenant_id` = ? AND `email` = ?",
            [self::$tenantId, $email]
        );
        $this->assertSame('First Name', $customer[0]['name']);
        $snapshots=Database::query('SELECT b.customer_name_snapshot FROM bookings b JOIN customers c ON c.id=b.customer_id WHERE b.tenant_id=? AND c.email=? ORDER BY b.id',[self::$tenantId,$email]);
        $this->assertSame(['First Name','Updated Name'],array_column($snapshots,'customer_name_snapshot'));
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Validation
    // ════════════════════════════════════════════════════════════════

    public function testRejectsMissingService(): void
    {
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/bookings/create", [
            '_csrf_token'    => self::$operatorCsrf,
            'service_id'     => '',
            'date'           => '2026-07-01',
            'time'           => '10:00',
            'customer_name'  => 'Test',
            'customer_email' => 'test@test.test',
        ], 'operator');

        $this->assertSame(302, $res['code'], 'Validation failure must redirect back');
        $this->assertStringContainsString('/create', $res['headers'], 'Must redirect back to create form');
    }

    public function testRejectsMissingDate(): void
    {
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/bookings/create", [
            '_csrf_token'    => self::$operatorCsrf,
            'service_id'     => self::$serviceId,
            'date'           => '',
            'time'           => '10:00',
            'customer_name'  => 'Test',
            'customer_email' => 'test@test.test',
        ], 'operator');

        $this->assertSame(302, $res['code']);
        $this->assertStringContainsString('/create', $res['headers']);
    }

    public function testRejectsMissingCustomerEmail(): void
    {
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/bookings/create", [
            '_csrf_token'    => self::$operatorCsrf,
            'service_id'     => self::$serviceId,
            'date'           => '2026-07-01',
            'time'           => '10:00',
            'customer_name'  => 'Test',
            'customer_email' => '',
        ], 'operator');

        $this->assertSame(302, $res['code']);
        $this->assertStringContainsString('/create', $res['headers']);
    }

    public function testRejectsInvalidEmail(): void
    {
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/bookings/create", [
            '_csrf_token'    => self::$operatorCsrf,
            'service_id'     => self::$serviceId,
            'date'           => '2026-07-01',
            'time'           => '10:00',
            'customer_name'  => 'Test',
            'customer_email' => 'not-an-email',
        ], 'operator');

        $this->assertSame(302, $res['code']);
        $this->assertStringContainsString('/create', $res['headers']);
    }

    public function testRejectsInvalidServiceStaffPairing(): void
    {
        $slot = $this->getAvailableSlot('next Monday');

        // Use unlinked staff (not in service_staff pivot for this service)
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/bookings/create", [
            '_csrf_token'    => self::$operatorCsrf,
            'service_id'     => self::$serviceId,
            'staff_id'       => self::$unlinkedStaffId,
            'date'           => $slot['date'],
            'time'           => $slot['time'],
            'customer_name'  => 'Invalid Staff Test',
            'customer_email' => 'invalid-staff-' . substr(Ulid::generate(), -6) . '@test.test',
        ], 'operator');

        // Must reject — either 302 redirect back to create or a clear failure
        $this->assertSame(302, $res['code'], 'Invalid service/staff pairing must be rejected');
        $this->assertStringContainsString('/create', $res['headers'], 'Must redirect back to create form');

        // Verify no booking was actually created with this staff
        $bookings = Database::query(
            "SELECT COUNT(*) as cnt FROM `bookings` WHERE `tenant_id` = ? AND `staff_id` = ?",
            [self::$tenantId, self::$unlinkedStaffId]
        );
        $this->assertSame(0, (int) $bookings[0]['cnt'], 'No booking must be created with unlinked staff');
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: explained failures (the form names what to fix)
    // ════════════════════════════════════════════════════════════════

    public function testInvalidEmailIsExplainedOnItsFieldWithInputKept(): void
    {
        $slot = $this->getAvailableSlot('next Tuesday');
        self::httpPost("/admin/tenants/" . self::$tenantId . "/bookings/create", [
            '_csrf_token'    => self::$operatorCsrf,
            'service_id'     => self::$serviceId,
            'date'           => $slot['date'],
            'time'           => $slot['time'],
            'customer_name'  => 'Kept Name',
            'customer_email' => 'not-an-email',
        ], 'operator');

        $form = self::httpGet("/admin/tenants/" . self::$tenantId . "/bookings/create", 'operator');
        $this->assertMatchesRegularExpression(
            '/name="customer_email"[^>]*is-invalid|is-invalid[^>]*name="customer_email"/',
            $form['body'],
            'The email input carries the error state.'
        );
        $this->assertStringContainsString(e(self::adminMessage('error_email_invalid')), $form['body']);
        $this->assertStringContainsString('value="Kept Name"', $form['body']);
    }

    public function testTakenTimeExplainsItself(): void
    {
        $slot = $this->getAvailableSlot('next Wednesday');
        $booking = [
            '_csrf_token'    => self::$operatorCsrf,
            'service_id'     => self::$serviceId,
            'staff_id'       => self::$staffId,
            'date'           => $slot['date'],
            'time'           => $slot['time'],
            'customer_name'  => 'First Caller',
            'customer_email' => 'first-' . substr(Ulid::generate(), -6) . '@test.test',
        ];
        $this->assertStringContainsString('/bookings/', self::httpPost("/admin/tenants/" . self::$tenantId . "/bookings/create", $booking, 'operator')['headers']);

        $booking['customer_email'] = 'second-' . substr(Ulid::generate(), -6) . '@test.test';
        self::httpPost("/admin/tenants/" . self::$tenantId . "/bookings/create", $booking, 'operator');

        $form = self::httpGet("/admin/tenants/" . self::$tenantId . "/bookings/create", 'operator');
        $this->assertStringContainsString(e(self::adminMessage('flash_slot_taken')), $form['body']);
        $this->assertStringNotContainsString(e(self::adminMessage('flash_create_failed')), $form['body']);
    }

    public function testDateBeyondTheBookingHorizonExplainsItself(): void
    {
        $far = (new \DateTimeImmutable('+90 days'))->modify('next Monday')->format('Y-m-d');
        self::httpPost("/admin/tenants/" . self::$tenantId . "/bookings/create", [
            '_csrf_token'    => self::$operatorCsrf,
            'service_id'     => self::$serviceId,
            'date'           => $far,
            'time'           => '10:00',
            'customer_name'  => 'Far Future',
            'customer_email' => 'far-' . substr(Ulid::generate(), -6) . '@test.test',
        ], 'operator');

        $form = self::httpGet("/admin/tenants/" . self::$tenantId . "/bookings/create", 'operator');
        $this->assertStringContainsString(e(self::adminMessage('error_too_far')), $form['body']);
    }

    public function testStaffMayLeaveRequiredCustomFieldsEmpty(): void
    {
        Database::execute(
            'UPDATE `tenants` SET `custom_fields` = ? WHERE `id` = ?',
            [json_encode([['name' => 'allergies', 'label' => 'Allergies', 'type' => 'text', 'required' => true]]), self::$tenantId]
        );
        try {
            $slot = $this->getAvailableSlot('next Thursday');
            $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/bookings/create", [
                '_csrf_token'    => self::$operatorCsrf,
                'service_id'     => self::$serviceId,
                'date'           => $slot['date'],
                'time'           => $slot['time'],
                'customer_name'  => 'Phone Caller',
                'customer_email' => 'phone-' . substr(Ulid::generate(), -6) . '@test.test',
            ], 'operator');

            $this->assertSame(302, $res['code']);
            $this->assertDoesNotMatchRegularExpression('#Location: [^\r\n]*/create#i', $res['headers'], 'A phone booking without answers is still created.');
        } finally {
            Database::execute('UPDATE `tenants` SET `custom_fields` = NULL WHERE `id` = ?', [self::$tenantId]);
        }
    }

    private static function adminMessage(string $key): string
    {
        return (require dirname(__DIR__, 2) . '/lang/en/admin.php')['bookings'][$key] ?? throw new \RuntimeException("Missing admin.bookings.{$key}");
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: "New booking" button visibility
    // ════════════════════════════════════════════════════════════════

    public function testNewBookingButtonVisibleOnTenantBookingsList(): void
    {
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/bookings", 'operator');

        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('btn-new-booking', $res['body'], 'New Booking button must be visible');
        $this->assertStringContainsString('/bookings/create', $res['body'], 'Must link to create page');
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Calendar entry point
    // ════════════════════════════════════════════════════════════════

    public function testCalendarDayViewHasNewBookingLink(): void
    {
        $today = date('Y-m-d');

        // Day view is now at /calendar/day (month is the default at /calendar)
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/calendar/day?date=" . $today, 'operator');

        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('/bookings/create', $res['body'], 'Calendar day must link to create booking');
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Consent-enabled tenant
    // ════════════════════════════════════════════════════════════════

    public function testConsentEnabledTenantCreatesBookingWithoutConsent(): void
    {
        // This tenant has requires_consent=1 (set in seedBookingData).
        // Admin bookings must succeed without consent.
        $slot = $this->getAvailableSlot('next Friday');

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/bookings/create", [
            '_csrf_token'    => self::$operatorCsrf,
            'service_id'     => self::$serviceId,
            'date'           => $slot['date'],
            'time'           => $slot['time'],
            'customer_name'  => 'Consent Bypass Customer',
            'customer_email' => 'consent-bypass-' . substr(Ulid::generate(), -6) . '@test.test',
        ], 'operator');

        // Must succeed (302 redirect to booking detail), not 500/302-to-create
        $this->assertSame(302, $res['code'], 'Admin booking on consent-enabled tenant must succeed');
        $this->assertStringNotContainsString('/create', $res['headers'], 'Must not redirect back to create form');

        // Verify booking exists with no consent fields
        $bookings = Database::query(
            "SELECT * FROM `bookings` WHERE `tenant_id` = ? AND `source` = 'admin' ORDER BY `created_at` DESC LIMIT 1",
            [self::$tenantId]
        );
        $this->assertNotEmpty($bookings);
        $this->assertNull($bookings[0]['consent_given_at'], 'Admin booking must not have consent timestamp');
        $this->assertNull($bookings[0]['consent_text_shown'], 'Admin booking must not have consent text');
        $this->cleanupIds[] = ['bookings', $bookings[0]['id']];
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Impersonation audit proof
    // ════════════════════════════════════════════════════════════════

    public function testImpersonatedBookingAuditHasOperatorActor(): void
    {
        // This test uses a separate cookie jar to avoid polluting shared state.
        // Flow: operator login → impersonate tenant → create booking → verify audit actor
        $cookieJar = tempnam(sys_get_temp_dir(), 'vb_imp_mb_') ?: '/tmp/vb_imp_mb_cookies';

        try {
            // 1. Login as operator with fresh session
            $loginPage = self::httpRaw('GET', '/admin/login', [], $cookieJar);
            preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $loginPage['body'], $m);
            $csrf = $m[1] ?? '';

            self::httpRaw('POST', '/admin/login', [
                '_csrf_token' => $csrf,
                'email'       => TestFixtures::OPERATOR_EMAIL,
                'password'    => TestFixtures::OPERATOR_PASSWORD,
            ], $cookieJar);

            // Get fresh CSRF
            $dashboard = self::httpRaw('GET', '/admin', [], $cookieJar);
            preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $dashboard['body'], $m);
            $csrf = $m[1] ?? '';

            // 2. Start impersonation
            $impRes = self::httpRaw('POST', '/admin/tenants/' . self::$tenantId . '/impersonate', [
                '_csrf_token' => $csrf,
            ], $cookieJar);

            $this->assertSame(302, $impRes['code'], 'Impersonation must redirect');

            // Get fresh CSRF from tenant context
            $tenantPage = self::httpRaw('GET', '/admin/tenants/' . self::$tenantId, [], $cookieJar);
            preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $tenantPage['body'], $m);
            $csrf = $m[1] ?? '';

            // 3. Create booking while impersonating
            $slot = $this->getAvailableSlot('next Monday');
            $email = 'imp-audit-' . substr(Ulid::generate(), -6) . '@test.test';

            $res = self::httpRaw('POST', '/admin/tenants/' . self::$tenantId . '/bookings/create', [
                '_csrf_token'    => $csrf,
                'service_id'     => self::$serviceId,
                'staff_id'       => self::$staffId,
                'date'           => $slot['date'],
                'time'           => $slot['time'],
                'customer_name'  => 'Impersonation Audit Test',
                'customer_email' => $email,
            ], $cookieJar);

            $this->assertSame(302, $res['code'], 'Booking creation during impersonation must succeed');

            // 4. Verify audit log has operator as actor
            $bookings = Database::query(
                "SELECT `id` FROM `bookings` WHERE `tenant_id` = ? AND `source` = 'admin' ORDER BY `created_at` DESC LIMIT 1",
                [self::$tenantId]
            );
            $this->assertNotEmpty($bookings, 'Impersonated booking must exist');
            $this->cleanupIds[] = ['bookings', $bookings[0]['id']];

            $logs = Database::query(
                "SELECT `actor_type`, `actor_id` FROM `audit_log` WHERE `entity_type` = 'booking' AND `entity_id` = ? AND `action` = 'booking.created'",
                [$bookings[0]['id']]
            );
            $this->assertNotEmpty($logs, 'Audit log must exist for impersonated booking');
            $this->assertSame('operator', $logs[0]['actor_type'], 'Actor type must be operator during impersonation');
            $this->assertSame(TestFixtures::OPERATOR_ID, $logs[0]['actor_id'], 'Actor ID must be the real operator ULID');
        } finally {
            if (file_exists($cookieJar)) {
                unlink($cookieJar);
            }
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Helpers
    // ════════════════════════════════════════════════════════════════

    private function getAvailableSlot(string $relativeDay): array
    {
        $date = (new \DateTimeImmutable($relativeDay))->format('Y-m-d');

        $ch = curl_init(self::$baseUrl . '/api/' . self::$slug . '/availability?date=' . $date . '&service_id=' . self::$serviceId);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);


        $this->assertSame(200, $code, "Availability for {$relativeDay} must return 200");
        $data = json_decode($body, true);
        $this->assertNotEmpty($data['slots'] ?? [], "Must have available slots on {$relativeDay}");

        $slot = $data['slots'][0];
        $slot['date'] = $date;
        return $slot;
    }

    // ════════════════════════════════════════════════════════════════
    // Seed + auth helpers
    // ════════════════════════════════════════════════════════════════

    private static function seedBookingData(): void
    {
        self::$tenantId = Ulid::generate();
        self::$serviceId = Ulid::generate();
        self::$staffId = Ulid::generate();
        self::$slug = 'test-manual-' . substr(self::$tenantId, -8);

        // Tenant — requires_consent=1 to test admin consent bypass
        Database::execute(
            "INSERT INTO `tenants` (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`,
             `brand_color`, `timezone`, `locale`, `currency`, `requires_consent`, `consent_text`,
             `slot_duration_minutes`, `max_advance_days`)
             VALUES (?, ?, 'Manual Booking Test', 'manual@test.test', 'timeslot', 'active',
             '#6366F1', 'UTC', 'en', 'EUR', 1,
             'I agree to the processing of my personal data for booking purposes.',
             30, 60)",
            [self::$tenantId, self::$slug]
        );

        // Service
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `description`, `duration_minutes`, `price`, `is_active`, `sort_order`)
             VALUES (?, ?, 'Test Service', 'A test service', 30, 25.00, 1, 1)",
            [self::$serviceId, self::$tenantId]
        );

        // Staff
        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`, `sort_order`)
             VALUES (?, ?, 'Test Staff', 'staff@manual.test', 1, 1)",
            [self::$staffId, self::$tenantId]
        );

        // Service-staff pivot
        Database::execute(
            'INSERT INTO `service_staff` (`service_id`, `staff_id`) VALUES (?, ?)',
            [self::$serviceId, self::$staffId]
        );

        // Unlinked staff (active but NOT in service_staff pivot for this service)
        self::$unlinkedStaffId = Ulid::generate();
        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`, `sort_order`)
             VALUES (?, ?, 'Unlinked Staff', 'unlinked@manual.test', 1, 2)",
            [self::$unlinkedStaffId, self::$tenantId]
        );

        // Availability: Mon–Fri 09:00–17:00
        for ($day = 0; $day <= 4; $day++) {
            Database::execute(
                "INSERT INTO `availability` (`id`, `tenant_id`, `day_of_week`, `start_time`, `end_time`)
                 VALUES (?, ?, ?, '09:00', '17:00')",
                [Ulid::generate(), self::$tenantId, $day]
            );
        }
    }

    private static function loginOperator(): void
    {
        // Get login page + CSRF token
        $loginPage = self::httpGet('/admin/login', 'operator');
        self::extractCsrf($loginPage['body'], 'operator');

        // Login
        $res = self::httpPost('/admin/login', [
            '_csrf_token' => self::$operatorCsrf,
            'email'       => TestFixtures::OPERATOR_EMAIL,
            'password'    => TestFixtures::OPERATOR_PASSWORD,
        ], 'operator');

        if (preg_match('/vb_session=([^;]+)/', $res['headers'], $m)) {
            self::$operatorCookie = 'vb_session=' . $m[1];
        }

        // Get fresh CSRF from dashboard
        $dashboard = self::httpGet('/admin', 'operator');
        self::extractCsrf($dashboard['body'], 'operator');
    }

    private static function createAndLoginOwner(): void
    {
        self::$ownerEmail = 'test-mb-owner-' . bin2hex(random_bytes(4)) . '@test.test';
        $password = 'OwnerTest123!';
        self::$ownerId = Ulid::generate();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::execute(
            "INSERT INTO `business_users` (`id`, `tenant_id`, `name`, `email`, `password_hash`, `role`, `is_active`, `force_password_change`)
             VALUES (?, ?, 'Test Owner', ?, ?, 'owner', 1, 0)",
            [self::$ownerId, self::$tenantId, self::$ownerEmail, $hash]
        );

        // Login
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
        self::$managerEmail = 'test-mb-mgr-' . bin2hex(random_bytes(4)) . '@test.test';
        $password = 'MgrTest123!';
        self::$managerId = Ulid::generate();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::execute(
            "INSERT INTO `business_users` (`id`, `tenant_id`, `name`, `email`, `password_hash`, `role`, `is_active`, `force_password_change`)
             VALUES (?, ?, 'Test Manager', ?, ?, 'manager', 1, 0)",
            [self::$managerId, self::$tenantId, self::$managerEmail, $hash]
        );

        // Login
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
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);


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

    /**
     * HTTP request with a file-based cookie jar (for isolated sessions like impersonation).
     *
     * @return array{code: int, headers: string, body: string}
     */
    private static function httpRaw(string $method, string $path, array $data, string $cookieJar): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
            CURLOPT_COOKIEJAR      => $cookieJar,
            CURLOPT_COOKIEFILE     => $cookieJar,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
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
}
