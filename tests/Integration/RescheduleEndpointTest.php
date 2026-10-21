<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the admin reschedule POST endpoint.
 *
 * Covers:
 * - Operator guard on cross-tenant route
 * - Business user access on tenant-context route
 * - Non-timeslot pattern with wrong fields → validation error
 * - Pending booking rejection (must be confirmed)
 * - Same-slot rejection
 * - Successful reschedule: booking chain, status, rescheduled_to_id
 * - Rescheduled status is terminal in the simple status dropdown
 */
final class RescheduleEndpointTest extends TestCase
{
    private static bool $appReachable = false;
    private static bool $fixturesReady = false;
    private string $baseUrl;
    private string $cookieJar;

    private const RESCHEDULE_BOOKING_ID = '01TESTRESCHEDULEBOOKING0';
    private const RESCHEDULE_CUSTOMER_ID = '01TESTRESCHEDCUST000000';
    private const RESCHEDULE_SERVICE_ID = '01TESTRESCHEDSVC00000000';
    private const HAPPY_BOOKING_ID = '01TESTRESCHEDHAPPY000000';
    private const RESOURCE_ID      = '01TESTRESCHEDRESRC000000';
    private const SLOT_ID           = '01TESTRESCHEDSLOT0000000';
    private const EVENT_ID          = '01TESTRESCHEDEVNT0000000';

    public static function setUpBeforeClass(): void
    {
        $baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');

        $ch = curl_init($baseUrl . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 5]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);


        if ($code === 0) {
            return;
        }

        self::$appReachable = true;

        // Provision shared fixtures — do NOT swallow errors.
        // If provisioning fails, tests must skip, not produce FK violations.
        TestFixtures::provision();

        try {
            self::seedRescheduleFixtures();
            self::$fixturesReady = true;
        } catch (\Throwable $e) {
            // Fixture seeding failed — tests will skip via setUp() guard.
            // Output the error so CI logs capture the root cause.
            fwrite(STDERR, "RescheduleEndpointTest fixture seeding failed: {$e->getMessage()}\n");
        }
    }

    protected function setUp(): void
    {
        if (!self::$appReachable) {
            $this->markTestSkipped('App not reachable');
        }

        $this->baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'vb_resched_test_') ?: '/tmp/vb_resched_test_cookies';

        // Per-test safety net: ensure all FK targets and availability exist.
        // If setUpBeforeClass seeding failed, this recovers the state.
        // If it already succeeded, these are cheap SELECT-only no-ops.
        if (!self::$fixturesReady) {
            try {
                $tenantId = TestFixtures::BUSINESS_TENANT_ID;
                self::ensureCustomerExists($tenantId);
                self::ensureServiceExists($tenantId);
                self::ensureAvailabilityExists($tenantId);
                self::$fixturesReady = true;
            } catch (\Throwable $e) {
                $this->markTestSkipped('Fixtures could not be recovered: ' . $e->getMessage());
            }
        } else {
            $tenantId = TestFixtures::BUSINESS_TENANT_ID;
            self::ensureCustomerExists($tenantId);
            self::ensureServiceExists($tenantId);
            self::ensureAvailabilityExists($tenantId);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->cookieJar) && file_exists($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Auth guard: business user cannot use cross-tenant reschedule
    // ════════════════════════════════════════════════════════════════

    public function test_business_user_cross_tenant_reschedule_returns_403(): void
    {
        $this->doLoginBusinessUser();
        $r = $this->post('/admin/bookings/' . self::RESCHEDULE_BOOKING_ID . '/reschedule', [
            'new_date' => date('Y-m-d', strtotime('+3 days')),
            'new_time' => '14:00',
        ]);

        $this->assertSame(403, $r['code'], 'Business user should be denied cross-tenant reschedule');
    }

    // ════════════════════════════════════════════════════════════════
    // Status guard: only confirmed bookings can be rescheduled
    // ════════════════════════════════════════════════════════════════

    public function test_reschedule_pending_booking_is_rejected(): void
    {
        // Create a pending booking
        $pendingId = '01TESTRESCHEDPENDING0000';
        $this->insertBooking($pendingId, 'pending', 'timeslot');

        $this->doLoginOperator();
        $r = $this->postWithCsrf('/admin/bookings/' . $pendingId . '/reschedule', [
            'new_date' => date('Y-m-d', strtotime('+3 days')),
            'new_time' => '14:00',
        ]);

        // Should redirect back with error (302, not 200)
        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString($pendingId, $r['location']);
    }

    public function test_reschedule_cancelled_booking_is_rejected(): void
    {
        $cancelledId = '01TESTRESCHEDCANCELLED00';
        $this->insertBooking($cancelledId, 'cancelled', 'timeslot');

        $this->doLoginOperator();
        $r = $this->postWithCsrf('/admin/bookings/' . $cancelledId . '/reschedule', [
            'new_date' => date('Y-m-d', strtotime('+3 days')),
            'new_time' => '14:00',
        ]);

        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString($cancelledId, $r['location']);
    }

    // ════════════════════════════════════════════════════════════════
    // Pattern-aware validation: wrong fields for non-timeslot patterns
    // ════════════════════════════════════════════════════════════════

    public function test_reschedule_resource_booking_with_timeslot_fields_is_rejected(): void
    {
        $resourceId = '01TESTRESCHEDRESOURCE000';
        $this->insertBooking($resourceId, 'confirmed', 'resource');

        $this->doLoginOperator();
        $r = $this->postWithCsrf('/admin/bookings/' . $resourceId . '/reschedule', [
            'new_date' => date('Y-m-d', strtotime('+3 days')),
            'new_time' => '14:00',
        ]);

        // Resource bookings need check_in/check_out, not new_date/new_time
        // So sending timeslot fields results in a validation error redirect
        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString($resourceId, $r['location']);
    }

    public function test_reschedule_capacity_booking_with_timeslot_fields_is_rejected(): void
    {
        $capacityId = '01TESTRESCHEDCAPACITY000';
        $this->insertBooking($capacityId, 'confirmed', 'capacity');

        $this->doLoginOperator();
        $r = $this->postWithCsrf('/admin/bookings/' . $capacityId . '/reschedule', [
            'new_date' => date('Y-m-d', strtotime('+3 days')),
            'new_time' => '14:00',
        ]);

        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString($capacityId, $r['location']);
    }

    public function test_reschedule_event_booking_with_timeslot_fields_is_rejected(): void
    {
        $eventId = '01TESTRESCHEDEVENT000000';
        $this->insertBooking($eventId, 'confirmed', 'event');

        $this->doLoginOperator();
        $r = $this->postWithCsrf('/admin/bookings/' . $eventId . '/reschedule', [
            'new_date' => date('Y-m-d', strtotime('+3 days')),
            'new_time' => '14:00',
        ]);

        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString($eventId, $r['location']);
    }

    // ════════════════════════════════════════════════════════════════
    // Same-slot rejection
    // ════════════════════════════════════════════════════════════════

    public function test_reschedule_to_same_slot_is_rejected(): void
    {
        $sameSlotId = '01TESTRESCHEDSAMESLOT000';
        $startDate = date('Y-m-d', strtotime('+2 days'));
        $this->insertBooking($sameSlotId, 'confirmed', 'timeslot');

        $this->doLoginOperator();
        $r = $this->postWithCsrf('/admin/bookings/' . $sameSlotId . '/reschedule', [
            'new_date' => $startDate,
            'new_time' => '10:00',
        ]);

        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString($sameSlotId, $r['location']);
    }

    // ════════════════════════════════════════════════════════════════
    // Rescheduled status is terminal in simple status dropdown
    // ════════════════════════════════════════════════════════════════

    public function test_status_change_from_rescheduled_is_rejected(): void
    {
        // Set booking to rescheduled status directly
        $rescheduledId = '01TESTRESCHEDTERMINAL000';
        $this->insertBooking($rescheduledId, 'rescheduled', 'timeslot');

        $this->doLoginOperator();
        $r = $this->postWithCsrf('/admin/bookings/' . $rescheduledId . '/status', [
            'status' => 'confirmed',
        ]);

        // Rescheduled→confirmed should be rejected (rescheduled has no outbound transitions)
        $this->assertSame(302, $r['code']);

        // Verify the booking is still rescheduled
        $booking = Database::query("SELECT `status` FROM `bookings` WHERE `id` = ?", [$rescheduledId]);
        $this->assertSame('rescheduled', $booking[0]['status'] ?? null);
    }

    // ════════════════════════════════════════════════════════════════
    // Completed→confirmed recovery (accidental completion reversal)
    // ════════════════════════════════════════════════════════════════

    public function test_completed_booking_can_be_recovered_to_confirmed(): void
    {
        $completedId = '01TESTRESCHEDCMPRECOV000';
        $this->insertBooking($completedId, 'completed', 'timeslot');
        $date=(new \DateTimeImmutable('+30 days'))->modify('next Monday')->format('Y-m-d');
        Database::execute('UPDATE bookings SET service_id=?,start_datetime=?,end_datetime=? WHERE id=?',[self::RESCHEDULE_SERVICE_ID,$date.' 10:00:00',$date.' 10:30:00',$completedId]);

        $this->doLoginOperator();
        $r = $this->postWithCsrf('/admin/bookings/' . $completedId . '/status', [
            'status' => 'confirmed',
        ]);

        // Should redirect back (302) — a successful status change
        $this->assertSame(302, $r['code']);

        // Verify the booking is now confirmed
        $booking = Database::query("SELECT `status` FROM `bookings` WHERE `id` = ?", [$completedId]);
        $this->assertSame('confirmed', $booking[0]['status'] ?? null,
            'Completed booking should be recoverable to confirmed');

        // Verify audit log entry
        $audit = Database::query(
            "SELECT `action` FROM `audit_log` WHERE `entity_type` = 'booking' AND `entity_id` = ? ORDER BY `created_at` DESC LIMIT 1",
            [$completedId]
        );
        $this->assertNotEmpty($audit, 'Status change should create audit log entry');
        $this->assertSame('booking.status_changed', $audit[0]['action']);
    }

    public function test_completed_booking_shows_confirmed_in_status_dropdown(): void
    {
        $completedId = '01TESTRESCHEDCMPUI00000';
        $this->insertBooking($completedId, 'completed', 'timeslot');
        $date=(new \DateTimeImmutable('+30 days'))->modify('next Monday')->format('Y-m-d');
        Database::execute('UPDATE bookings SET service_id=?,start_datetime=?,end_datetime=? WHERE id=?',[self::RESCHEDULE_SERVICE_ID,$date.' 10:00:00',$date.' 10:30:00',$completedId]);

        $this->doLoginOperator();
        $r = $this->get('/admin/bookings/' . $completedId);

        // The booking detail page should include 'confirmed' as an option in the status dropdown
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('value="confirmed"', $r['body'],
            'Completed booking detail page should offer "confirmed" as a recovery option');
    }

    // ════════════════════════════════════════════════════════════════
    // Input validation
    // ════════════════════════════════════════════════════════════════

    public function test_reschedule_with_invalid_date_is_rejected(): void
    {
        $badDateId = '01TESTRESCHEDBADDATE0000';
        $this->insertBooking($badDateId, 'confirmed', 'timeslot');

        $this->doLoginOperator();
        $r = $this->postWithCsrf('/admin/bookings/' . $badDateId . '/reschedule', [
            'new_date' => 'not-a-date',
            'new_time' => '10:00',
        ]);

        $this->assertSame(302, $r['code']);
    }

    public function test_reschedule_with_invalid_time_is_rejected(): void
    {
        $badTimeId = '01TESTRESCHEDBADTIME0000';
        $this->insertBooking($badTimeId, 'confirmed', 'timeslot');

        $this->doLoginOperator();
        $r = $this->postWithCsrf('/admin/bookings/' . $badTimeId . '/reschedule', [
            'new_date' => date('Y-m-d', strtotime('+3 days')),
            'new_time' => 'invalid',
        ]);

        $this->assertSame(302, $r['code']);
    }

    // ════════════════════════════════════════════════════════════════
    // Happy path: successful reschedule
    // ════════════════════════════════════════════════════════════════

    public function test_successful_reschedule_creates_chain_and_preserves_data(): void
    {
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;

        // Clamp origDate to weekday (availability only covers Mon-Fri)
        $origTs = strtotime('+2 days');
        while (date('N', $origTs) >= 6) {
            $origTs = strtotime('+1 day', $origTs);
        }

        // targetDate must be a DIFFERENT weekday from origDate
        $targetTs = strtotime('+1 day', $origTs);
        while (date('N', $targetTs) >= 6) {
            $targetTs = strtotime('+1 day', $targetTs);
        }
        $origDate   = date('Y-m-d', $origTs);
        $targetDate = date('Y-m-d', $targetTs);

        // Reset the happy-path booking to confirmed with custom data
        Database::execute("DELETE FROM `bookings` WHERE `id` = ?", [self::HAPPY_BOOKING_ID]);
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`, `service_id`,
              `start_datetime`, `end_datetime`, `status`, `source`,
              `custom_field_data`, `internal_notes`)
             VALUES (?, ?, 'timeslot', ?, ?,
              '{$origDate} 10:00:00', '{$origDate} 10:30:00',
              'confirmed', 'web',
              '{\"allergies\":\"peanuts\"}', 'VIP customer — needs extra time')",
            [self::HAPPY_BOOKING_ID, $tenantId, self::RESCHEDULE_CUSTOMER_ID, self::RESCHEDULE_SERVICE_ID]
        );

        // Clear any prior reschedule-produced bookings for clean assertion
        Database::execute(
            "DELETE FROM `bookings` WHERE `tenant_id` = ? AND `source` = 'admin' AND `id` != ?",
            [$tenantId, self::HAPPY_BOOKING_ID]
        );

        $this->doLoginOperator();
        $r = $this->postWithCsrf('/admin/bookings/' . self::HAPPY_BOOKING_ID . '/reschedule', [
            'new_date' => $targetDate,
            'new_time' => '11:00',
        ]);

        // Should redirect to the NEW booking's detail page (302)
        $this->assertSame(302, $r['code'], 'Successful reschedule should redirect');
        // Redirect should NOT point back to the original booking
        $this->assertStringNotContainsString(self::HAPPY_BOOKING_ID, $r['location'],
            'Redirect should point to the new booking, not the original');

        // Verify original booking is now rescheduled with rescheduled_to_id
        $original = Database::query(
            "SELECT `status`, `rescheduled_to_id` FROM `bookings` WHERE `id` = ?",
            [self::HAPPY_BOOKING_ID]
        );
        $this->assertSame('rescheduled', $original[0]['status'] ?? null,
            'Original booking should be marked rescheduled');
        $this->assertNotNull($original[0]['rescheduled_to_id'] ?? null,
            'Original booking should have rescheduled_to_id set');

        $newBookingId = $original[0]['rescheduled_to_id'];

        // Verify the new booking exists with correct data
        $newBooking = Database::query(
            "SELECT `status`, `booking_pattern`, `customer_id`, `service_id`,
                    `start_datetime`, `custom_field_data`, `internal_notes`, `source`
             FROM `bookings` WHERE `id` = ?",
            [$newBookingId]
        );
        $this->assertNotEmpty($newBooking, 'New booking should exist');
        $this->assertSame('confirmed', $newBooking[0]['status']);
        $this->assertSame('timeslot', $newBooking[0]['booking_pattern']);
        $this->assertSame(self::RESCHEDULE_CUSTOMER_ID, $newBooking[0]['customer_id']);
        $this->assertSame(self::RESCHEDULE_SERVICE_ID, $newBooking[0]['service_id']);
        $this->assertSame('admin', $newBooking[0]['source']);
        $this->assertStringContainsString($targetDate . ' 11:00', $newBooking[0]['start_datetime']);

        // Verify custom_field_data and internal_notes were preserved
        $this->assertStringContainsString('peanuts', $newBooking[0]['custom_field_data'] ?? '',
            'custom_field_data should be preserved on the new booking');
        $this->assertSame('VIP customer — needs extra time', $newBooking[0]['internal_notes'] ?? null,
            'internal_notes should be preserved on the new booking');

        // Verify audit log entry
        $audit = Database::query(
            "SELECT `action`, `details` FROM `audit_log` WHERE `entity_type` = 'booking' AND `entity_id` = ? ORDER BY `created_at` DESC LIMIT 1",
            [self::HAPPY_BOOKING_ID]
        );
        $this->assertNotEmpty($audit, 'Audit log entry should exist for reschedule');
        $this->assertSame('booking.rescheduled', $audit[0]['action']);
    }

    // ════════════════════════════════════════════════════════════════
    // Happy path: successful resource reschedule
    // ════════════════════════════════════════════════════════════════

    public function test_successful_resource_reschedule_via_admin(): void
    {
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;
        $bookingId = '01TESTRESCHEDRESRCHAPPY0';
        $checkIn = date('Y-m-d', strtotime('+10 days'));
        $checkOut = date('Y-m-d', strtotime('+12 days'));
        $newCheckIn = date('Y-m-d', strtotime('+20 days'));
        $newCheckOut = date('Y-m-d', strtotime('+22 days'));

        self::seedResourceFixture();
        $this->insertPatternBooking($bookingId, 'resource', [
            'resource_id' => self::RESOURCE_ID,
            'start_datetime' => "{$checkIn} 00:00:00",
            'end_datetime' => "{$checkOut} 00:00:00",
            'party_size' => 2,
        ]);

        $this->doLoginOperator();
        $r = $this->postWithCsrf('/admin/bookings/' . $bookingId . '/reschedule', [
            'check_in'  => $newCheckIn,
            'check_out' => $newCheckOut,
        ]);

        $this->assertSame(302, $r['code'], 'Resource reschedule should redirect');
        $this->assertStringNotContainsString($bookingId, $r['location'],
            'Redirect should point to the new booking');

        $original = Database::query(
            "SELECT `status`, `rescheduled_to_id` FROM `bookings` WHERE `id` = ?",
            [$bookingId]
        );
        $this->assertSame('rescheduled', $original[0]['status'] ?? null);
        $this->assertNotNull($original[0]['rescheduled_to_id'] ?? null);

        $newBooking = Database::query(
            "SELECT `status`, `booking_pattern`, `resource_id` FROM `bookings` WHERE `id` = ?",
            [$original[0]['rescheduled_to_id']]
        );
        $this->assertSame('confirmed', $newBooking[0]['status']);
        $this->assertSame('resource', $newBooking[0]['booking_pattern']);
        $this->assertSame(self::RESOURCE_ID, $newBooking[0]['resource_id']);
    }

    // ════════════════════════════════════════════════════════════════
    // Happy path: successful capacity reschedule
    // ════════════════════════════════════════════════════════════════

    public function test_successful_capacity_reschedule_via_admin(): void
    {
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;
        $bookingId = '01TESTRESCHEDCAPHAPPY000';

        // Original date: +10 days (weekday)
        $origDt = new \DateTimeImmutable('+10 days');
        while ((int) $origDt->format('N') >= 6) {
            $origDt = $origDt->modify('+1 day');
        }
        $origDate = $origDt->format('Y-m-d');
        $origDow = ((int) $origDt->format('N')) - 1;

        // Target date: +17 days (weekday)
        $newDt = new \DateTimeImmutable('+17 days');
        while ((int) $newDt->format('N') >= 6) {
            $newDt = $newDt->modify('+1 day');
        }
        $newDate = $newDt->format('Y-m-d');
        $newDow = ((int) $newDt->format('N')) - 1;

        // Ensure slots exist for both DOWs
        $origSlotId = self::SLOT_ID;
        self::seedCapacitySlot($origSlotId, $origDow);
        $targetSlotId = '01TESTRESCHEDSLOT' . str_pad((string) $newDow, 7, '0', STR_PAD_LEFT);
        self::seedCapacitySlot($targetSlotId, $newDow);

        $this->insertPatternBooking($bookingId, 'capacity', [
            'start_datetime' => "{$origDate} 12:00:00",
            'end_datetime' => "{$origDate} 14:00:00",
            'party_size' => 2,
        ]);

        $this->doLoginOperator();
        $r = $this->postWithCsrf('/admin/bookings/' . $bookingId . '/reschedule', [
            'date'    => $newDate,
            'slot_id' => $targetSlotId,
        ]);

        $this->assertSame(302, $r['code'], 'Capacity reschedule should redirect');
        $this->assertStringNotContainsString($bookingId, $r['location']);

        $original = Database::query(
            "SELECT `status`, `rescheduled_to_id` FROM `bookings` WHERE `id` = ?",
            [$bookingId]
        );
        $this->assertSame('rescheduled', $original[0]['status'] ?? null);

        $newBooking = Database::query(
            "SELECT `status`, `booking_pattern` FROM `bookings` WHERE `id` = ?",
            [$original[0]['rescheduled_to_id']]
        );
        $this->assertSame('confirmed', $newBooking[0]['status']);
        $this->assertSame('capacity', $newBooking[0]['booking_pattern']);
    }

    // ════════════════════════════════════════════════════════════════
    // Happy path: successful event reschedule
    // ════════════════════════════════════════════════════════════════

    public function test_successful_event_reschedule_via_admin(): void
    {
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;
        $bookingId = '01TESTRESCHEDEVTHAPPY000';
        $origDate = date('Y-m-d', strtotime('+10 days'));
        $newDate = date('Y-m-d', strtotime('+17 days'));

        self::seedEventFixture();
        $this->insertPatternBooking($bookingId, 'event', [
            'event_id' => self::EVENT_ID,
            'start_datetime' => "{$origDate} 14:00:00",
            'end_datetime' => "{$origDate} 16:00:00",
            'party_size' => 1,
        ]);

        $this->doLoginOperator();
        $r = $this->postWithCsrf('/admin/bookings/' . $bookingId . '/reschedule', [
            'date'     => $newDate,
            'event_id' => self::EVENT_ID,
        ]);

        $this->assertSame(302, $r['code'], 'Event reschedule should redirect');
        $this->assertStringNotContainsString($bookingId, $r['location']);

        $original = Database::query(
            "SELECT `status`, `rescheduled_to_id` FROM `bookings` WHERE `id` = ?",
            [$bookingId]
        );
        $this->assertSame('rescheduled', $original[0]['status'] ?? null);

        $newBooking = Database::query(
            "SELECT `status`, `booking_pattern`, `event_id` FROM `bookings` WHERE `id` = ?",
            [$original[0]['rescheduled_to_id']]
        );
        $this->assertSame('confirmed', $newBooking[0]['status']);
        $this->assertSame('event', $newBooking[0]['booking_pattern']);
        $this->assertSame(self::EVENT_ID, $newBooking[0]['event_id']);
    }

    // ════════════════════════════════════════════════════════════════
    // Fixtures
    // ════════════════════════════════════════════════════════════════

    private static function seedRescheduleFixtures(): void
    {
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;

        // Verify the shared tenant exists — if it doesn't, provision() failed
        // or the DB was reset since last run. Bail early with a clear message.
        $tenant = Database::query(
            'SELECT `id` FROM `tenants` WHERE `id` = ? LIMIT 1',
            [$tenantId]
        );
        if (empty($tenant)) {
            throw new \RuntimeException(
                "Tenant {$tenantId} not found. TestFixtures::provision() must run first."
            );
        }

        \Tests\Support\CustomerFixtureState::restore();
        // Clean prior reschedule test bookings
        Database::execute("DELETE FROM `bookings` WHERE `id` LIKE '01TESTRESCHED%'");
        Database::execute("DELETE FROM `customers` WHERE `id` = ?", [self::RESCHEDULE_CUSTOMER_ID]);
        Database::execute("DELETE FROM `services` WHERE `id` = ?", [self::RESCHEDULE_SERVICE_ID]);

        // Customer
        Database::execute(
            "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`)
             VALUES (?, ?, 'Reschedule Test Customer', 'resched@example.com')",
            [self::RESCHEDULE_CUSTOMER_ID, $tenantId]
        );

        // Service (30-minute duration for slot calculation)
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`, `is_active`, `sort_order`, `requires_staff`)
             VALUES (?, ?, 'Reschedule Test Service', 30, 1, 99, 0)",
            [self::RESCHEDULE_SERVICE_ID, $tenantId]
        );

        // Seed weekday availability (Mon-Fri = day_of_week 0-4)
        // Clean first since TestFixtures::provision() re-creates the tenant
        Database::execute(
            "DELETE FROM `availability` WHERE `tenant_id` = ? AND `staff_id` IS NULL",
            [$tenantId]
        );
        for ($dow = 0; $dow <= 4; $dow++) {
            Database::execute(
                "INSERT INTO `availability` (`id`, `tenant_id`, `day_of_week`, `start_time`, `end_time`, `is_available`)
                 VALUES (?, ?, ?, '08:00', '18:00', 1)",
                ["01TESTRESCHEDAVAIL00000{$dow}", $tenantId, $dow]
            );
        }

        // Confirmed timeslot booking (the main fixture)
        $startDate = date('Y-m-d', strtotime('+2 days'));
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`,
              `start_datetime`, `end_datetime`, `status`, `source`)
             VALUES (?, ?, 'timeslot', ?,
              '{$startDate} 10:00:00', '{$startDate} 10:30:00',
              'confirmed', 'web')",
            [self::RESCHEDULE_BOOKING_ID, $tenantId, self::RESCHEDULE_CUSTOMER_ID]
        );
    }

    private function insertBooking(string $id, string $status, string $pattern): void
    {
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;
        $startDate = date('Y-m-d', strtotime('+2 days'));

        // Ensure customer FK target exists (idempotent — survives any DB state)
        self::ensureCustomerExists($tenantId);

        Database::execute("DELETE FROM `bookings` WHERE `id` = ?", [$id]);
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`,
              `start_datetime`, `end_datetime`, `status`, `source`)
             VALUES (?, ?, ?, ?,
              '{$startDate} 10:00:00', '{$startDate} 10:30:00',
              ?, 'web')",
            [$id, $tenantId, $pattern, self::RESCHEDULE_CUSTOMER_ID, $status]
        );
    }

    /**
     * Insert a pattern-specific booking with custom field overrides.
     */
    private function insertPatternBooking(string $id, string $pattern, array $fields): void
    {
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;
        self::ensureCustomerExists($tenantId);
        Database::execute("DELETE FROM `bookings` WHERE `id` = ?", [$id]);

        $cols = ['`id`', '`tenant_id`', '`booking_pattern`', '`customer_id`', '`status`', '`source`'];
        $vals = [$id, $tenantId, $pattern, self::RESCHEDULE_CUSTOMER_ID, 'confirmed', 'web'];
        $placeholders = ['?', '?', '?', '?', '?', '?'];

        foreach ($fields as $col => $val) {
            $cols[] = "`{$col}`";
            $vals[] = $val;
            $placeholders[] = '?';
        }

        $colStr = implode(', ', $cols);
        $phStr = implode(', ', $placeholders);
        Database::execute("INSERT INTO `bookings` ({$colStr}) VALUES ({$phStr})", $vals);
    }

    /**
     * Ensure the reschedule-test customer row exists.
     *
     * Uses explicit SELECT + INSERT (not INSERT IGNORE) because INSERT IGNORE
     * silently swallows FK violations in some MySQL modes, which would hide
     * the real problem (missing tenant) instead of fixing it.
     */
    private static function ensureCustomerExists(string $tenantId): void
    {
        $exists = Database::query(
            'SELECT `id` FROM `customers` WHERE `id` = ? LIMIT 1',
            [self::RESCHEDULE_CUSTOMER_ID]
        );
        if (empty($exists)) {
            Database::execute(
                "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`)
                 VALUES (?, ?, 'Reschedule Test Customer', 'resched@example.com')",
                [self::RESCHEDULE_CUSTOMER_ID, $tenantId]
            );
        }
    }

    /**
     * Ensure the reschedule-test service row exists.
     *
     * The happy-path reschedule needs a service for slot calculation.
     * Without it, the app can't compute available time slots and the
     * reschedule silently fails (redirects back to original booking).
     */
    private static function ensureServiceExists(string $tenantId): void
    {
        $exists = Database::query(
            'SELECT `id` FROM `services` WHERE `id` = ? LIMIT 1',
            [self::RESCHEDULE_SERVICE_ID]
        );
        if (empty($exists)) {
            Database::execute(
                "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`, `is_active`, `sort_order`)
                 VALUES (?, ?, 'Reschedule Test Service', 30, 1, 99)",
                [self::RESCHEDULE_SERVICE_ID, $tenantId]
            );
        }
    }

    /**
     * Ensure weekday availability rows exist for the test tenant.
     *
     * The reschedule slot calculator requires availability entries.
     * Without them, no valid target slots are found and the reschedule fails.
     */
    private static function ensureAvailabilityExists(string $tenantId): void
    {
        $count = Database::query(
            "SELECT COUNT(*) AS cnt FROM `availability`
             WHERE `tenant_id` = ? AND `staff_id` IS NULL AND `is_available` = 1",
            [$tenantId]
        );
        if (((int) ($count[0]['cnt'] ?? 0)) >= 5) {
            return; // Already has Mon-Fri availability
        }

        // Clear and re-seed Mon-Fri (day_of_week 0-4)
        Database::execute(
            "DELETE FROM `availability` WHERE `tenant_id` = ? AND `staff_id` IS NULL",
            [$tenantId]
        );
        for ($dow = 0; $dow <= 4; $dow++) {
            Database::execute(
                "INSERT INTO `availability` (`id`, `tenant_id`, `day_of_week`, `start_time`, `end_time`, `is_available`)
                 VALUES (?, ?, ?, '08:00', '18:00', 1)",
                ["01TESTRESCHEDAVAIL00000{$dow}", $tenantId, $dow]
            );
        }
    }

    private static function seedResourceFixture(): void
    {
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;
        $existing = Database::query("SELECT `id` FROM `resources` WHERE `id` = ?", [self::RESOURCE_ID]);
        if (!empty($existing)) return;

        Database::execute(
            "INSERT INTO `resources` (`id`, `tenant_id`, `name`, `capacity`, `min_stay_nights`, `max_stay_nights`, `is_active`)
             VALUES (?, ?, 'Admin Reschedule Cabin', 4, 1, 30, 1)",
            [self::RESOURCE_ID, $tenantId]
        );
    }

    private static function seedCapacitySlot(string $slotId, int $dow): void
    {
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;

        // Delete any existing slot with the same unique window (tenant, DOW, times)
        // to avoid unique constraint violations from prior test runs
        Database::execute(
            "DELETE FROM `capacity_slots` WHERE `tenant_id` = ? AND `day_of_week` = ? AND `start_time` = '12:00:00' AND `end_time` = '14:00:00'",
            [$tenantId, $dow]
        );

        // Also delete by ID in case a prior run left an orphan
        Database::execute("DELETE FROM `capacity_slots` WHERE `id` = ?", [$slotId]);

        Database::execute(
            "INSERT INTO `capacity_slots` (`id`, `tenant_id`, `day_of_week`, `start_time`, `end_time`, `max_capacity`, `min_party_size`, `max_party_size`, `is_active`)
             VALUES (?, ?, ?, '12:00:00', '14:00:00', 20, 1, 8, 1)",
            [$slotId, $tenantId, $dow]
        );
    }

    private static function seedEventFixture(): void
    {
        $tenantId = TestFixtures::BUSINESS_TENANT_ID;
        $existing = Database::query("SELECT `id` FROM `events` WHERE `id` = ?", [self::EVENT_ID]);
        if (!empty($existing)) return;

        Database::execute(
            "INSERT INTO `events` (`id`, `tenant_id`, `name`, `max_participants`, `start_datetime`, `end_datetime`, `is_recurring`, `rrule`, `is_active`)
             VALUES (?, ?, 'Admin Reschedule Workshop', 20, '2026-01-01 14:00:00', '2026-01-01 16:00:00', 1, 'FREQ=DAILY;COUNT=365', 1)",
            [self::EVENT_ID, $tenantId]
        );
    }

    // ════════════════════════════════════════════════════════════════
    // HTTP helpers
    // ════════════════════════════════════════════════════════════════

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
     * POST with CSRF token fetched from the booking detail page.
     */
    private function postWithCsrf(string $path, array $data): array
    {
        // Fetch a page to get the CSRF token
        $page = $this->get('/admin/bookings');
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page['body'], $m);
        $data['_csrf_token'] = $m[1] ?? '';

        return $this->post($path, $data);
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


        $body = substr($response, $headerSize);
        $headers = substr($response, 0, $headerSize);
        $location = '';
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
            $location = trim($m[1]);
        }

        return compact('code', 'body', 'location');
    }
}
