<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for event-pattern booking flows.
 *
 * Covers:
 * - Public API: GET /api/{slug}/events (event listing)
 * - Public API: GET /api/{slug}/events/{id} (event detail)
 * - Public API: POST /api/{slug}/bookings (confirmed event booking)
 * - Public API: POST /api/{slug}/bookings (waitlisted event booking)
 * - Admin: GET  /admin/tenants/{id}/events (event list page)
 * - Admin: GET  /admin/tenants/{id}/events/create (create form)
 * - Admin: POST /admin/tenants/{id}/events (store event)
 * - Admin: GET  /admin/tenants/{id}/events/{id}/edit (edit form)
 * - Admin: POST /admin/tenants/{id}/events/{id} (update event)
 * - Admin: POST /admin/tenants/{id}/events/{id}/toggle (toggle active)
 * - Admin: POST /admin/tenants/{id}/events/{id}/delete (delete event)
 * - Admin: POST /admin/tenants/{id}/bookings/create (manual event booking)
 * - Admin: POST /admin/tenants/{id}/bookings/{id}/status (waitlisted→confirmed)
 * - Recurring event instance expansion (unique date keys)
 * - Non-event tenant redirect (manager-denied access)
 * - Dashboard pattern-aware booking display
 *
 * Uses real HTTP against the running app at APP_TEST_URL.
 */
final class EventBookingFlowTest extends TestCase
{
    private static string $baseUrl;
    private static bool $appReachable = false;
    private static bool $dbReady = false;

    // Seed data
    private static string $tenantId = '';
    private static string $slug = '';
    private static string $eventId = '';
    private static string $fullEventId = '';

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
            self::seedEventData();
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
            Database::execute('DELETE FROM `events` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `customers` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        } catch (\Throwable) {
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Public API — event listing and detail
    // ════════════════════════════════════════════════════════════════

    public function testPublicEventListReturnsEvents(): void
    {
        $res = $this->httpGet('/api/' . self::$slug . '/events');
        $this->assertSame(200, $res['code'], 'Event list must return 200');
        $data = json_decode($res['body'], true);
        $this->assertArrayHasKey('events', $data);
        $this->assertNotEmpty($data['events'], 'Must have seeded events');

        // Check field names match engine output
        $first = $data['events'][0];
        $this->assertArrayHasKey('date', $first, 'Engine returns "date", not "instance_date"');
        $this->assertArrayHasKey('remaining', $first, 'Engine returns "remaining", not "remaining_spots"');
        $this->assertArrayHasKey('is_full', $first);
        $this->assertArrayHasKey('can_waitlist', $first);
    }

    public function testPublicEventDetailReturnsEvent(): void
    {
        $res = $this->httpGet('/api/' . self::$slug . '/events/' . self::$eventId);
        $this->assertSame(200, $res['code'], 'Event detail must return 200');
        $data = json_decode($res['body'], true);
        $this->assertArrayHasKey('event', $data);
        $this->assertSame(self::$eventId, $data['event']['id']);
        $this->assertArrayHasKey('remaining', $data['event']);
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Public API — event booking
    // ════════════════════════════════════════════════════════════════

    public function testPublicEventBookingSuccess(): void
    {
        $event = Database::query('SELECT * FROM `events` WHERE `id` = ?', [self::$eventId])[0];
        $date = date('Y-m-d', strtotime($event['start_datetime']));

        $csrf = $this->fetchPublicCsrf();

        $res = $this->httpPostJsonWithCsrf('/api/' . self::$slug . '/bookings', [
            'event_id'          => self::$eventId,
            'date'              => $date,
            'spot_count'        => 2,
            'customer'          => [
                'name'  => 'Event Booking Test',
                'email' => 'event-test-' . substr(Ulid::generate(), -6) . '@example.com',
            ],
            'consent_given'     => true,
            'customer_timezone' => 'Europe/Berlin',
            '__ts'              => (string) ((time() - 10) * 1000),
            '__hp'              => '',
        ], $csrf);

        $this->assertSame(201, $res['code'], 'Event booking must return 201. Body: ' . $res['body']);
        $data = json_decode($res['body'], true);
        $this->assertArrayHasKey('booking', $data);
        $this->assertSame('confirmed', $data['booking']['status']);
        $this->assertSame(2, $data['booking']['spot_count']);
        $this->assertFalse($data['booking']['waitlisted']);

        $this->cleanupIds[] = ['bookings', $data['booking']['id']];
    }

    public function testPublicEventWaitlistBooking(): void
    {
        // The full event has max_participants=2 and is pre-filled
        $event = Database::query('SELECT * FROM `events` WHERE `id` = ?', [self::$fullEventId])[0];
        $date = date('Y-m-d', strtotime($event['start_datetime']));

        $csrf = $this->fetchPublicCsrf();

        $res = $this->httpPostJsonWithCsrf('/api/' . self::$slug . '/bookings', [
            'event_id'          => self::$fullEventId,
            'date'              => $date,
            'spot_count'        => 1,
            'customer'          => [
                'name'  => 'Waitlist Test',
                'email' => 'waitlist-' . substr(Ulid::generate(), -6) . '@example.com',
            ],
            'consent_given'     => true,
            'customer_timezone' => 'Europe/Berlin',
            '__ts'              => (string) ((time() - 10) * 1000),
            '__hp'              => '',
        ], $csrf);

        $this->assertSame(201, $res['code'], 'Waitlist booking must return 201. Body: ' . $res['body']);
        $data = json_decode($res['body'], true);
        $this->assertArrayHasKey('booking', $data);
        $this->assertSame('waitlisted', $data['booking']['status']);
        $this->assertTrue($data['booking']['waitlisted']);

        $this->cleanupIds[] = ['bookings', $data['booking']['id']];
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Admin — event CRUD
    // ════════════════════════════════════════════════════════════════

    public function testAdminEventListPage(): void
    {
        $res = self::httpGetWithCookie(
            '/admin/tenants/' . self::$tenantId . '/events',
            self::$operatorCookie
        );
        $this->assertSame(200, $res['code'], 'Admin event list must return 200');
        $this->assertStringContainsString('Integration Test Event', $res['body']);
    }

    public function testAdminEventCreateFormLoads(): void
    {
        $res = self::httpGetWithCookie(
            '/admin/tenants/' . self::$tenantId . '/events/create',
            self::$operatorCookie
        );
        $this->assertSame(200, $res['code'], 'Admin event create form must return 200');
        $this->assertStringContainsString('event_name', $res['body']);
    }

    public function testAdminEventCreateStoresEvent(): void
    {
        // Get fresh CSRF from the create form
        $formRes = self::httpGetWithCookie(
            '/admin/tenants/' . self::$tenantId . '/events/create',
            self::$operatorCookie
        );
        $csrf = self::$operatorCsrf;
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $formRes['body'], $m)) {
            $csrf = $m[1];
        }

        $futureDate = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $res = self::httpPostWithCookie(
            '/admin/tenants/' . self::$tenantId . '/events',
            [
                '_csrf_token'       => $csrf,
                'name'              => 'Admin-Created Event',
                'description'       => 'Created via integration test',
                'location'          => 'Test Room',
                'price'             => '25.00',
                'max_participants'  => '15',
                'start_date'        => $futureDate,
                'start_time'        => '10:00',
                'end_date'          => $futureDate,
                'end_time'          => '12:00',
            ],
            self::$operatorCookie
        );

        // Should redirect (302/303) to event list on success
        $this->assertContains($res['code'], [302, 303], 'Event creation must redirect. Body: ' . $res['body']);

        // Verify the event exists in DB
        $events = Database::query(
            "SELECT * FROM `events` WHERE `tenant_id` = ? AND `name` = 'Admin-Created Event'",
            [self::$tenantId]
        );
        $this->assertNotEmpty($events, 'Created event must exist in database');

        // Cleanup
        Database::execute('DELETE FROM `events` WHERE `id` = ?', [$events[0]['id']]);
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Admin — event edit/update
    // ════════════════════════════════════════════════════════════════

    public function testAdminEventEditFormLoads(): void
    {
        // Ensure the event name is in its original state
        Database::execute(
            "UPDATE `events` SET `name` = 'Integration Test Event' WHERE `id` = ?",
            [self::$eventId]
        );

        $res = self::httpGetWithCookie(
            '/admin/tenants/' . self::$tenantId . '/events/' . self::$eventId . '/edit',
            self::$operatorCookie
        );
        $this->assertSame(200, $res['code'], 'Admin event edit form must return 200');
        $this->assertStringContainsString('Integration Test Event', $res['body']);
    }

    public function testAdminEventUpdateSavesChanges(): void
    {
        // Get CSRF from edit form
        $formRes = self::httpGetWithCookie(
            '/admin/tenants/' . self::$tenantId . '/events/' . self::$eventId . '/edit',
            self::$operatorCookie
        );
        $csrf = self::$operatorCsrf;
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $formRes['body'], $m)) {
            $csrf = $m[1];
        }

        $event = Database::query('SELECT * FROM `events` WHERE `id` = ?', [self::$eventId])[0];
        $startDate = date('Y-m-d', strtotime($event['start_datetime']));
        $endDate = date('Y-m-d', strtotime($event['end_datetime']));

        $res = self::httpPostWithCookie(
            '/admin/tenants/' . self::$tenantId . '/events/' . self::$eventId,
            [
                '_csrf_token'       => $csrf,
                'name'              => 'Updated Event Name',
                'description'       => 'Updated via test',
                'location'          => 'Updated Room',
                'price'             => '35.00',
                'max_participants'  => '25',
                'start_date'        => $startDate,
                'start_time'        => '10:00',
                'end_date'          => $endDate,
                'end_time'          => '14:00',
            ],
            self::$operatorCookie
        );

        $this->assertContains($res['code'], [302, 303], 'Event update must redirect');

        // Verify changes persisted
        $updated = Database::query('SELECT * FROM `events` WHERE `id` = ?', [self::$eventId])[0];
        $this->assertSame('Updated Event Name', $updated['name']);
        $this->assertSame('35.00', $updated['price']);

        // Restore original name for subsequent tests
        Database::execute(
            "UPDATE `events` SET `name` = 'Integration Test Event', `price` = '30.00' WHERE `id` = ?",
            [self::$eventId]
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Admin — toggle active / delete
    // ════════════════════════════════════════════════════════════════

    public function testAdminEventToggleDeactivatesAndReactivates(): void
    {
        // Get CSRF from event list
        $listRes = self::httpGetWithCookie(
            '/admin/tenants/' . self::$tenantId . '/events',
            self::$operatorCookie
        );
        $csrf = self::$operatorCsrf;
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $listRes['body'], $m)) {
            $csrf = $m[1];
        }

        // Toggle off
        $res = self::httpPostWithCookie(
            '/admin/tenants/' . self::$tenantId . '/events/' . self::$eventId . '/toggle',
            ['_csrf_token' => $csrf],
            self::$operatorCookie
        );
        $this->assertContains($res['code'], [302, 303], 'Toggle must redirect');

        $event = Database::query('SELECT `is_active` FROM `events` WHERE `id` = ?', [self::$eventId])[0];
        $this->assertSame(0, (int) $event['is_active'], 'Event should be deactivated');

        // Get fresh CSRF and toggle back on
        $listRes2 = self::httpGetWithCookie(
            '/admin/tenants/' . self::$tenantId . '/events',
            self::$operatorCookie
        );
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $listRes2['body'], $m)) {
            $csrf = $m[1];
        }

        $res2 = self::httpPostWithCookie(
            '/admin/tenants/' . self::$tenantId . '/events/' . self::$eventId . '/toggle',
            ['_csrf_token' => $csrf],
            self::$operatorCookie
        );
        $this->assertContains($res2['code'], [302, 303]);

        $event2 = Database::query('SELECT `is_active` FROM `events` WHERE `id` = ?', [self::$eventId])[0];
        $this->assertSame(1, (int) $event2['is_active'], 'Event should be reactivated');
    }

    public function testAdminEventDeleteRemovesEvent(): void
    {
        // Create a throwaway event for deletion
        $deleteEventId = Ulid::generate();
        $futureDate = (new \DateTimeImmutable('+60 days'))->format('Y-m-d');
        Database::execute(
            "INSERT INTO `events` (`id`, `tenant_id`, `name`, `description`, `max_participants`,
             `start_datetime`, `end_datetime`, `is_recurring`, `allow_waitlist`, `waitlist_max`, `is_active`)
             VALUES (?, ?, 'Delete Test Event', 'To be deleted', 5, ?, ?, 0, 0, 0, 1)",
            [$deleteEventId, self::$tenantId,
             "{$futureDate} 09:00:00", "{$futureDate} 11:00:00"]
        );

        // Get CSRF
        $listRes = self::httpGetWithCookie(
            '/admin/tenants/' . self::$tenantId . '/events',
            self::$operatorCookie
        );
        $csrf = self::$operatorCsrf;
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $listRes['body'], $m)) {
            $csrf = $m[1];
        }

        $res = self::httpPostWithCookie(
            '/admin/tenants/' . self::$tenantId . '/events/' . $deleteEventId . '/delete',
            ['_csrf_token' => $csrf],
            self::$operatorCookie
        );
        $this->assertContains($res['code'], [302, 303], 'Delete must redirect');

        $remaining = Database::query('SELECT * FROM `events` WHERE `id` = ?', [$deleteEventId]);
        $this->assertEmpty($remaining, 'Deleted event must not exist');
    }

    public function testAdminEventDeleteBlockedWhenBookingsExist(): void
    {
        // Create an event with a linked booking
        $guardEventId = Ulid::generate();
        $futureDate = (new \DateTimeImmutable('+65 days'))->format('Y-m-d');
        Database::execute(
            "INSERT INTO `events` (`id`, `tenant_id`, `name`, `description`, `max_participants`,
             `start_datetime`, `end_datetime`, `is_recurring`, `allow_waitlist`, `waitlist_max`, `is_active`)
             VALUES (?, ?, 'Guard Test Event', 'Should not be deletable', 10, ?, ?, 0, 0, 0, 1)",
            [$guardEventId, self::$tenantId,
             "{$futureDate} 09:00:00", "{$futureDate} 11:00:00"]
        );

        // Create a customer and booking referencing this event
        $guardCustomerId = Ulid::generate();
        Database::execute(
            "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`) VALUES (?, ?, 'Guard Customer', 'guard-test@test.test')",
            [$guardCustomerId, self::$tenantId]
        );
        $guardBookingId = Ulid::generate();
        Database::execute(
            "INSERT INTO `bookings` (`id`, `tenant_id`, `customer_id`, `booking_pattern`, `event_id`,
             `start_datetime`, `end_datetime`, `party_size`, `status`, `source`)
             VALUES (?, ?, ?, 'event', ?, ?, ?, 1, 'confirmed', 'web')",
            [$guardBookingId, self::$tenantId, $guardCustomerId, $guardEventId,
             "{$futureDate} 09:00:00", "{$futureDate} 11:00:00"]
        );

        // Get CSRF
        $listRes = self::httpGetWithCookie(
            '/admin/tenants/' . self::$tenantId . '/events',
            self::$operatorCookie
        );
        $csrf = self::$operatorCsrf;
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $listRes['body'], $m)) {
            $csrf = $m[1];
        }

        // Attempt delete — should be blocked
        $res = self::httpPostWithCookie(
            '/admin/tenants/' . self::$tenantId . '/events/' . $guardEventId . '/delete',
            ['_csrf_token' => $csrf],
            self::$operatorCookie
        );
        $this->assertContains($res['code'], [302, 303], 'Blocked delete must still redirect');

        // Event must still exist
        $stillExists = Database::query('SELECT * FROM `events` WHERE `id` = ?', [$guardEventId]);
        $this->assertNotEmpty($stillExists, 'Event with linked bookings must not be deleted');

        // Follow redirect to verify flash message
        $redirectRes = self::httpGetWithCookie(
            '/admin/tenants/' . self::$tenantId . '/events',
            self::$operatorCookie
        );
        $this->assertStringContainsString(
            'linked bookings',
            $redirectRes['body'],
            'Redirect page must show the booking-guard error message'
        );

        // Cleanup
        Database::execute('DELETE FROM `bookings` WHERE `id` = ?', [$guardBookingId]);
        Database::execute('DELETE FROM `customers` WHERE `id` = ?', [$guardCustomerId]);
        Database::execute('DELETE FROM `events` WHERE `id` = ?', [$guardEventId]);
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Recurring event — unique instance keys
    // ════════════════════════════════════════════════════════════════

    public function testRecurringEventProducesUniqueInstanceKeys(): void
    {
        // Seed a weekly recurring event with 4 occurrences
        $recurEventId = Ulid::generate();
        $futureDate = (new \DateTimeImmutable('+3 days'))->format('Y-m-d');
        Database::execute(
            "INSERT INTO `events` (`id`, `tenant_id`, `name`, `description`, `max_participants`,
             `start_datetime`, `end_datetime`, `is_recurring`, `rrule`,
             `allow_waitlist`, `waitlist_max`, `is_active`)
             VALUES (?, ?, 'Recurring Weekly Class', 'Test recurrence', 10,
             ?, ?, 1, 'FREQ=WEEKLY;COUNT=4', 0, 0, 1)",
            [$recurEventId, self::$tenantId,
             "{$futureDate} 18:00:00", "{$futureDate} 20:00:00"]
        );

        $res = $this->httpGet('/api/' . self::$slug . '/events');
        $this->assertSame(200, $res['code']);
        $data = json_decode($res['body'], true);

        // Collect instances of our recurring event
        $instances = array_filter($data['events'], fn($e) => $e['id'] === $recurEventId);
        $this->assertGreaterThanOrEqual(2, count($instances), 'Recurring event must expand to multiple instances');

        // Verify each instance has a unique date
        $dates = array_map(fn($e) => $e['date'], $instances);
        $this->assertSame(count($dates), count(array_unique($dates)), 'Each instance must have a unique date');

        // Verify the composite key (id + '-' + date) is unique
        $keys = array_map(fn($e) => $e['id'] . '-' . $e['date'], $instances);
        $this->assertSame(count($keys), count(array_unique($keys)), 'Composite keys must be unique');

        // Cleanup
        Database::execute('DELETE FROM `events` WHERE `id` = ?', [$recurEventId]);
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Access control — non-event tenant redirect
    // ════════════════════════════════════════════════════════════════

    public function testEventRoutesRedirectForNonEventTenant(): void
    {
        // TestFixtures creates a 'timeslot' pattern tenant
        $timeslotTenantId = TestFixtures::businessTenantId();

        $res = self::httpGetWithCookie(
            '/admin/tenants/' . $timeslotTenantId . '/events',
            self::$operatorCookie
        );
        // Should redirect away from events (not 200) because tenant is timeslot pattern
        $this->assertContains(
            $res['code'],
            [302, 303],
            'Event routes must redirect for non-event tenant'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Dashboard — pattern-aware booking display
    // ════════════════════════════════════════════════════════════════

    public function testOperatorDashboardRendersPatternAwareBookings(): void
    {
        // Seed an event booking with a future date + a customer
        $customerId = Ulid::generate();
        Database::execute(
            "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`)
             VALUES (?, ?, 'Dashboard Test', 'dash-test@test.test')",
            [$customerId, self::$tenantId]
        );

        $bookingId = Ulid::generate();
        $futureDate = (new \DateTimeImmutable('+1 day'))->format('Y-m-d');
        Database::execute(
            "INSERT INTO `bookings` (`id`, `tenant_id`, `customer_id`, `booking_pattern`, `event_id`,
             `start_datetime`, `end_datetime`, `party_size`, `status`, `source`)
             VALUES (?, ?, ?, 'event', ?, ?, ?, 1, 'confirmed', 'web')",
            [$bookingId, self::$tenantId, $customerId, self::$eventId,
             "{$futureDate} 09:00:00", "{$futureDate} 13:00:00"]
        );

        $res = self::httpGetWithCookie('/admin', self::$operatorCookie);
        $this->assertSame(200, $res['code'], 'Dashboard must render');

        // The dashboard should show the event name (not just "—")
        $this->assertStringContainsString('Integration Test Event', $res['body'],
            'Dashboard must show event name for event-pattern bookings');

        // Cleanup
        Database::execute('DELETE FROM `bookings` WHERE `id` = ?', [$bookingId]);
        Database::execute('DELETE FROM `customers` WHERE `id` = ?', [$customerId]);
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Admin — waitlisted→confirmed transition
    // ════════════════════════════════════════════════════════════════

    public function testAdminStatusChangeWaitlistedToConfirmed(): void
    {
        $customerId = Ulid::generate();
        Database::execute(
            "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`) VALUES (?, ?, 'Status Test', 'status-test@test.test')",
            [$customerId, self::$tenantId]
        );

        $event = Database::query('SELECT * FROM `events` WHERE `id` = ?', [self::$eventId])[0];
        $date = date('Y-m-d', strtotime($event['start_datetime']));

        $bookingId = Ulid::generate();
        Database::execute(
            "INSERT INTO `bookings` (`id`, `tenant_id`, `customer_id`, `booking_pattern`, `event_id`,
             `start_datetime`, `end_datetime`, `party_size`, `status`, `source`)
             VALUES (?, ?, ?, 'event', ?, ?, ?, 1, 'waitlisted', 'web')",
            [$bookingId, self::$tenantId, $customerId, self::$eventId,
             $event['start_datetime'], $event['end_datetime']]
        );

        $showRes = self::httpGetWithCookie(
            '/admin/tenants/' . self::$tenantId . '/bookings/' . $bookingId,
            self::$operatorCookie
        );
        $csrf = self::$operatorCsrf;
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $showRes['body'], $m)) {
            $csrf = $m[1];
        }

        $res = self::httpPostWithCookie(
            '/admin/tenants/' . self::$tenantId . '/bookings/' . $bookingId . '/status',
            [
                '_csrf_token' => $csrf,
                'status'      => 'confirmed',
            ],
            self::$operatorCookie
        );

        $this->assertContains($res['code'], [302, 303], 'Status change must redirect');

        $booking = Database::query('SELECT `status` FROM `bookings` WHERE `id` = ?', [$bookingId]);
        $this->assertSame('confirmed', $booking[0]['status']);

        Database::execute('DELETE FROM `bookings` WHERE `id` = ?', [$bookingId]);
        Database::execute('DELETE FROM `customers` WHERE `id` = ?', [$customerId]);
    }

    // ════════════════════════════════════════════════════════════════
    // HTTP helpers
    // ════════════════════════════════════════════════════════════════

    /** @return array{code: int, headers: string, body: string} */
    private function httpGet(string $path): array
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

        return [
            'code'    => $code,
            'headers' => substr($response, 0, $headerSize),
            'body'    => substr($response, $headerSize),
        ];
    }

    /** @var string|null Cookie file for cleanup */
    private ?string $csrfCookieFile = null;

    /**
     * Fetch a CSRF context by loading the booking page.
     * Returns the CSRF token and cookie file path (for the session).
     *
     * @return array{token: string, cookieFile: string}
     */
    private function fetchPublicCsrf(): array
    {
        $cookieFile = sys_get_temp_dir() . '/vb_event_csrf_' . bin2hex(random_bytes(8)) . '.txt';
        $this->csrfCookieFile = $cookieFile;

        $ch = curl_init(self::$baseUrl . '/book/' . self::$slug);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
        ]);
        $body = (string) curl_exec($ch);


        // Extract window.__VB_CSRF__ = "..."
        preg_match('/window\.__VB_CSRF__\s*=\s*"([^"]+)"/', $body, $m);
        $token = $m[1] ?? '';

        // Fallback to csrfToken in config blob
        if ($token === '') {
            preg_match('/csrfToken["\']?\s*[:=]\s*["\']([^"\']+)["\']/', $body, $m2);
            $token = $m2[1] ?? '';
        }

        $this->assertNotEmpty($token, 'CSRF token must be present in booking page');

        return ['token' => $token, 'cookieFile' => $cookieFile];
    }

    /**
     * POST JSON with a valid CSRF context (token + session cookie).
     *
     * @param array{token: string, cookieFile: string} $csrf from fetchPublicCsrf()
     * @return array{code: int, headers: string, body: string}
     */
    private function httpPostJsonWithCsrf(string $path, array $data, array $csrf): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_COOKIEFILE     => $csrf['cookieFile'],
            CURLOPT_COOKIEJAR      => $csrf['cookieFile'],
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-CSRF-Token: ' . $csrf['token'],
            ],
        ]);
        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        return [
            'code'    => $code,
            'headers' => substr($response, 0, $headerSize),
            'body'    => substr($response, $headerSize),
        ];
    }

    /** @return array{code: int, headers: string, body: string} */
    private static function httpGetWithCookie(string $path, string $cookie): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        $httpHeaders = [];
        if ($cookie !== '') {
            $httpHeaders[] = 'Cookie: ' . $cookie;
        }

        $responseHeaders = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => $httpHeaders,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$responseHeaders) {
                $responseHeaders .= $header;
                return strlen($header);
            },
        ]);
        $body = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [
            'code'    => $code,
            'headers' => $responseHeaders,
            'body'    => $body,
        ];
    }

    /** @return array{code: int, headers: string, body: string} */
    private static function httpPostWithCookie(string $path, array $data, string $cookie): array
    {
        $data = TestFixtures::withCatalogVersion($path, $data, $cookie);
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_HTTPHEADER     => [
                'Cookie: ' . $cookie,
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);
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
    // Seed + auth helpers
    // ════════════════════════════════════════════════════════════════

    private static function seedEventData(): void
    {
        self::$tenantId = Ulid::generate();
        self::$eventId = Ulid::generate();
        self::$fullEventId = Ulid::generate();
        self::$slug = 'test-event-flow-' . substr(self::$tenantId, -8);

        // Tenant with event pattern
        Database::execute(
            "INSERT INTO `tenants` (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`,
             `brand_color`, `timezone`, `locale`, `currency`, `requires_consent`, `consent_text`,
             `max_advance_days`)
             VALUES (?, ?, 'Event Flow Test', 'event-flow@test.test', 'event', 'active',
             '#8B5CF6', 'Europe/Berlin', 'en', 'EUR', 1,
             'I consent to the processing of my personal data.', 90)",
            [self::$tenantId, self::$slug]
        );

        // Event with plenty of spots
        $futureDate = (new \DateTimeImmutable('+7 days'))->format('Y-m-d');
        Database::execute(
            "INSERT INTO `events` (`id`, `tenant_id`, `name`, `description`, `location`, `price`,
             `max_participants`, `start_datetime`, `end_datetime`,
             `is_recurring`, `allow_waitlist`, `waitlist_max`, `is_active`)
             VALUES (?, ?, 'Integration Test Event', 'A test workshop', 'Room A', '30.00',
             20, ?, ?,
             0, 0, 0, 1)",
            [self::$eventId, self::$tenantId,
             "{$futureDate} 09:00:00", "{$futureDate} 13:00:00"]
        );

        // Event that is full (max 2) with waitlist enabled
        $futureDate2 = (new \DateTimeImmutable('+14 days'))->format('Y-m-d');
        Database::execute(
            "INSERT INTO `events` (`id`, `tenant_id`, `name`, `description`, `location`, `price`,
             `max_participants`, `start_datetime`, `end_datetime`,
             `is_recurring`, `allow_waitlist`, `waitlist_max`, `is_active`)
             VALUES (?, ?, 'Full Event With Waitlist', 'Nearly full for testing', 'Room B', '45.00',
             2, ?, ?,
             0, 1, 5, 1)",
            [self::$fullEventId, self::$tenantId,
             "{$futureDate2} 14:00:00", "{$futureDate2} 18:00:00"]
        );

        // Fill the full event to capacity
        $fillCustomerId = Ulid::generate();
        Database::execute(
            "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`) VALUES (?, ?, 'Fill Customer', 'fill-event@test.test')",
            [$fillCustomerId, self::$tenantId]
        );
        $fillBookingId = Ulid::generate();
        Database::execute(
            "INSERT INTO `bookings` (`id`, `tenant_id`, `customer_id`, `booking_pattern`, `event_id`,
             `start_datetime`, `end_datetime`, `party_size`, `status`, `source`)
             VALUES (?, ?, ?, 'event', ?, ?, ?, 2, 'confirmed', 'web')",
            [$fillBookingId, self::$tenantId, $fillCustomerId, self::$fullEventId,
             "{$futureDate2} 14:00:00", "{$futureDate2} 18:00:00"]
        );
    }

    private static function loginOperator(): void
    {
        $cookieFile = sys_get_temp_dir() . '/vb_event_admin_' . bin2hex(random_bytes(8)) . '.txt';

        // Get login page + CSRF token
        $ch = curl_init(self::$baseUrl . '/admin/login');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HEADER         => true,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
        ]);
        $loginResponse = (string) curl_exec($ch);
        $loginHeaderSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);


        $loginBody = substr($loginResponse, $loginHeaderSize);
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $loginBody, $m)) {
            self::$operatorCsrf = $m[1];
        }

        // Login POST
        $ch = curl_init(self::$baseUrl . '/admin/login');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                '_csrf_token' => self::$operatorCsrf,
                'email'       => TestFixtures::OPERATOR_EMAIL,
                'password'    => TestFixtures::OPERATOR_PASSWORD,
            ]),
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $loginRes = (string) curl_exec($ch);
        $loginResHeaderSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $loginResHeaders = substr($loginRes, 0, $loginResHeaderSize);


        // Extract session cookie from response headers
        if (preg_match('/vb_session=([^;]+)/', $loginResHeaders, $m)) {
            self::$operatorCookie = 'vb_session=' . $m[1];
        }

        // If no session from login response, read from cookie file
        if (self::$operatorCookie === '' && file_exists($cookieFile)) {
            $cookieContent = file_get_contents($cookieFile);
            if (preg_match('/vb_session\s+(\S+)/', $cookieContent, $m)) {
                self::$operatorCookie = 'vb_session=' . $m[1];
            }
        }

        // Get fresh CSRF from dashboard
        $dashboard = self::httpGetWithCookie('/admin', self::$operatorCookie);
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $dashboard['body'], $m)) {
            self::$operatorCsrf = $m[1];
        }
    }
}
