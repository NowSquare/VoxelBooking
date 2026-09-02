<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the self-service reschedule public API endpoint.
 *
 * POST /api/{slug}/bookings/{id}/reschedule
 *
 * Covers:
 * - CSRF enforcement (session token, or the Origin header for the embed iframe)
 * - Status guard (only confirmed bookings)
 * - Tenant toggle (allow_rescheduling=0)
 * - Time-gate rejection
 * - Same-slot rejection
 * - Pattern-aware validation (wrong fields for non-timeslot patterns)
 * - Happy path: booking chain, status, rescheduled_to_id, audit log
 * - Custom field + notes preservation on the new booking
 * - Consent inheritance from original booking
 */
final class SelfServiceRescheduleTest extends TestCase
{
    private static bool $appReachable = false;
    private string $baseUrl;
    private string $cookieJar;

    // Fixture IDs — deterministic ULIDs for self-service reschedule tests
    private const CUSTOMER_ID  = '01TESTSSRESCHEDCUST0000';
    private const SERVICE_ID   = '01TESTSSRESCHEDSVC00000';
    private const BOOKING_ID   = '01TESTSSRESCHEDBOOKING0';
    private const TENANT_ID    = '01TESTTENANT000000000000'; // from TestFixtures
    private const RESOURCE_ID  = '01TESTSSRESCHEDRESRC000';
    private const CAP_SLOT_ID  = '01TESTSSRESCHEDCAPSLOT0';
    private const EVENT_ID     = '01TESTSSRESCHEDEVNT0000';
    private const EMBED_BOOKING_ID = '01TESTSSRESCHEDEMBED000';

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
            self::seedFixtures();
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
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'vb_ss_resched_') ?: '/tmp/vb_ss_resched_cookies';

        // Clear rate limits to prevent throttling across test methods
        try {
            Database::execute("DELETE FROM `rate_limits`");
        } catch (\Throwable) {
            // best-effort
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->cookieJar) && file_exists($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // CSRF enforcement
    // ════════════════════════════════════════════════════════════════

    public function test_reschedule_without_csrf_returns_403(): void
    {
        $r = $this->postJson("/api/test-fixture/bookings/" . self::BOOKING_ID . "/reschedule", [
            'new_date' => date('Y-m-d', strtotime('+3 days')),
            'new_time' => '14:00',
        ], ''); // no CSRF token

        $this->assertSame(403, $r['code']);
        $data = json_decode($r['body'], true);
        $this->assertSame('csrf_mismatch', $data['error'] ?? null);
    }

    public function test_reschedule_rejects_foreign_origin(): void
    {
        $r = $this->postJson("/api/test-fixture/bookings/" . self::BOOKING_ID . "/reschedule", [
            'new_date' => date('Y-m-d', strtotime('+3 days')),
            'new_time' => '14:00',
        ], '', ['Origin: https://evil.example']);

        $this->assertSame(403, $r['code']);
        $data = json_decode($r['body'], true);
        $this->assertSame('csrf_mismatch', $data['error'] ?? null);
    }

    /**
     * The embed iframe runs cross-site, so browsers withhold its cookies.
     * It has no session and no token. The browser still attaches an Origin
     * header to the POST, and a matching Origin is proof enough.
     */
    public function test_reschedule_with_same_origin_and_no_session_succeeds(): void
    {
        $targetTs = strtotime('+3 days');
        while (date('N', $targetTs) >= 6) {
            $targetTs = strtotime('+1 day', $targetTs);
        }
        $targetDate = date('Y-m-d', $targetTs);
        $origDate = date('Y-m-d', strtotime('+2 days'));

        Database::execute("DELETE FROM `bookings` WHERE `id` = ?", [self::EMBED_BOOKING_ID]);
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`, `service_id`,
              `start_datetime`, `end_datetime`, `status`, `source`)
             VALUES (?, ?, 'timeslot', ?, ?,
              '{$origDate} 11:00:00', '{$origDate} 11:30:00',
              'confirmed', 'web')",
            [self::EMBED_BOOKING_ID, self::TENANT_ID, self::CUSTOMER_ID, self::SERVICE_ID]
        );
        self::ensureAvailability();

        $r = $this->postJson("/api/test-fixture/bookings/" . self::EMBED_BOOKING_ID . "/reschedule", [
            'new_date' => $targetDate,
            'new_time' => '15:00',
        ], '', ['Origin: ' . $this->baseUrl]);

        $this->assertSame(200, $r['code'], 'Embedded reschedule must succeed without a session. Body: ' . $r['body']);
        $data = json_decode($r['body'], true);
        $this->assertTrue($data['rescheduled'] ?? false);

        $original = Database::query("SELECT `status` FROM `bookings` WHERE `id` = ?", [self::EMBED_BOOKING_ID]);
        $this->assertSame('rescheduled', $original[0]['status'] ?? null);
    }

    // ════════════════════════════════════════════════════════════════
    // Status guard: only confirmed bookings can be rescheduled
    // ════════════════════════════════════════════════════════════════

    public function test_reschedule_cancelled_booking_returns_409(): void
    {
        $cancelledId = '01TESTSSRESCHEDCANCELL0';
        $this->insertBooking($cancelledId, 'cancelled', 'timeslot');

        $r = $this->postJsonWithCsrf("/api/test-fixture/bookings/{$cancelledId}/reschedule", [
            'new_date' => date('Y-m-d', strtotime('+3 days')),
            'new_time' => '14:00',
        ]);

        $this->assertSame(409, $r['code']);
        $data = json_decode($r['body'], true);
        $this->assertSame('not_confirmed', $data['error'] ?? null);
    }

    public function test_reschedule_pending_booking_returns_409(): void
    {
        $pendingId = '01TESTSSRESCHEDPENDING0';
        $this->insertBooking($pendingId, 'pending', 'timeslot');

        $r = $this->postJsonWithCsrf("/api/test-fixture/bookings/{$pendingId}/reschedule", [
            'new_date' => date('Y-m-d', strtotime('+3 days')),
            'new_time' => '14:00',
        ]);

        $this->assertSame(409, $r['code']);
        $data = json_decode($r['body'], true);
        $this->assertSame('not_confirmed', $data['error'] ?? null);
    }

    // ════════════════════════════════════════════════════════════════
    // Tenant toggle: rescheduling disabled
    // ════════════════════════════════════════════════════════════════

    public function test_reschedule_when_disabled_returns_403(): void
    {
        // Temporarily disable rescheduling
        Database::execute(
            'UPDATE `tenants` SET `allow_rescheduling` = 0 WHERE `id` = ?',
            [self::TENANT_ID]
        );

        try {
            $r = $this->postJsonWithCsrf("/api/test-fixture/bookings/" . self::BOOKING_ID . "/reschedule", [
                'new_date' => date('Y-m-d', strtotime('+3 days')),
                'new_time' => '14:00',
            ]);

            $this->assertSame(403, $r['code']);
            $data = json_decode($r['body'], true);
            $this->assertSame('rescheduling_disabled', $data['error'] ?? null);
        } finally {
            Database::execute(
                'UPDATE `tenants` SET `allow_rescheduling` = 1 WHERE `id` = ?',
                [self::TENANT_ID]
            );
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Time gate: booking starts too soon
    // ════════════════════════════════════════════════════════════════

    public function test_reschedule_past_time_gate_returns_409(): void
    {
        // Create a booking starting in 1 hour (inside 24h gate)
        $soonId = '01TESTSSRESCHEDSOON0000';
        $soonStart = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $soonEnd   = date('Y-m-d H:i:s', strtotime('+1 hour 30 minutes'));

        Database::execute("DELETE FROM `bookings` WHERE `id` = ?", [$soonId]);
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`, `service_id`,
              `start_datetime`, `end_datetime`, `status`, `source`)
             VALUES (?, ?, 'timeslot', ?, ?,
              ?, ?, 'confirmed', 'web')",
            [$soonId, self::TENANT_ID, self::CUSTOMER_ID, self::SERVICE_ID, $soonStart, $soonEnd]
        );

        $r = $this->postJsonWithCsrf("/api/test-fixture/bookings/{$soonId}/reschedule", [
            'new_date' => date('Y-m-d', strtotime('+5 days')),
            'new_time' => '14:00',
        ]);

        $this->assertSame(409, $r['code']);
        $data = json_decode($r['body'], true);
        $this->assertSame('time_gate', $data['error'] ?? null);
    }

    // ════════════════════════════════════════════════════════════════
    // Same-slot rejection
    // ════════════════════════════════════════════════════════════════

    public function test_reschedule_to_same_slot_returns_422(): void
    {
        // The fixture booking is at +2 days, 10:00
        $startDate = date('Y-m-d', strtotime('+2 days'));

        $r = $this->postJsonWithCsrf("/api/test-fixture/bookings/" . self::BOOKING_ID . "/reschedule", [
            'new_date' => $startDate,
            'new_time' => '10:00',
        ]);

        $this->assertSame(422, $r['code']);
        $data = json_decode($r['body'], true);
        $this->assertSame('same_slot', $data['error'] ?? null);
    }

    // ════════════════════════════════════════════════════════════════
    // Pattern-aware validation: wrong fields for resource pattern
    // ════════════════════════════════════════════════════════════════

    public function test_reschedule_resource_booking_with_timeslot_fields_returns_422(): void
    {
        $resourceId = '01TESTSSRESCHEDRESOURC0';
        $this->insertBooking($resourceId, 'confirmed', 'resource');

        // Sending timeslot fields (new_date/new_time) to a resource booking should
        // return a validation error, not a pattern rejection — resource bookings
        // need check_in/check_out fields instead.
        $r = $this->postJsonWithCsrf("/api/test-fixture/bookings/{$resourceId}/reschedule", [
            'new_date' => date('Y-m-d', strtotime('+3 days')),
            'new_time' => '14:00',
        ]);

        $this->assertSame(422, $r['code']);
        $data = json_decode($r['body'], true);
        $this->assertSame('invalid_date', $data['error'] ?? null);
    }

    // ════════════════════════════════════════════════════════════════
    // Happy path: successful reschedule
    // ════════════════════════════════════════════════════════════════

    public function test_successful_reschedule_creates_chain_and_preserves_data(): void
    {
        // Determine a valid target date (weekday, +3 days, clamped to avoid weekends)
        $targetTs = strtotime('+3 days');
        while (date('N', $targetTs) >= 6) {
            $targetTs = strtotime('+1 day', $targetTs);
        }
        $targetDate = date('Y-m-d', $targetTs);

        // Reset the fixture booking with custom data
        $origDate = date('Y-m-d', strtotime('+2 days'));

        // Nuclear cleanup: remove ALL bookings for this tenant so no prior
        // test (within this class or from another suite sharing this tenant)
        // can leave a conflicting slot.
        Database::execute(
            "DELETE FROM `bookings` WHERE `tenant_id` = ?",
            [self::TENANT_ID]
        );
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`, `service_id`,
              `start_datetime`, `end_datetime`, `status`, `source`,
              `custom_field_data`, `notes`,
              `consent_given_at`, `consent_text_shown`)
             VALUES (?, ?, 'timeslot', ?, ?,
              '{$origDate} 10:00:00', '{$origDate} 10:30:00',
              'confirmed', 'web',
              '{\"allergies\":\"peanuts\"}', 'Window seat preferred',
              '2026-04-01 10:00:00', 'I agree to the processing of my personal data.')",
            [self::BOOKING_ID, self::TENANT_ID, self::CUSTOMER_ID, self::SERVICE_ID]
        );

        // Ensure availability exists for target date
        self::ensureAvailability();

        $r = $this->postJsonWithCsrf("/api/test-fixture/bookings/" . self::BOOKING_ID . "/reschedule", [
            'new_date' => $targetDate,
            'new_time' => '14:00',
        ]);

        $this->assertSame(200, $r['code'], 'Successful reschedule should return 200. Body: ' . $r['body']);
        $data = json_decode($r['body'], true);
        $this->assertTrue($data['rescheduled'] ?? false, 'Response should indicate rescheduled=true');
        $this->assertNotEmpty($data['new_booking_id'] ?? null, 'Response should include new_booking_id');

        $newBookingId = $data['new_booking_id'];

        // Verify original booking is now rescheduled with rescheduled_to_id
        $original = Database::query(
            "SELECT `status`, `rescheduled_to_id` FROM `bookings` WHERE `id` = ?",
            [self::BOOKING_ID]
        );
        $this->assertSame('rescheduled', $original[0]['status'] ?? null,
            'Original booking should be marked rescheduled');
        $this->assertSame($newBookingId, $original[0]['rescheduled_to_id'] ?? null,
            'Original should link to new booking via rescheduled_to_id');

        // Verify the new booking
        $newBooking = Database::query(
            "SELECT `status`, `booking_pattern`, `customer_id`, `service_id`,
                    `start_datetime`, `custom_field_data`, `notes`, `source`
             FROM `bookings` WHERE `id` = ?",
            [$newBookingId]
        );
        $this->assertNotEmpty($newBooking, 'New booking should exist');
        $this->assertSame('confirmed', $newBooking[0]['status']);
        $this->assertSame('timeslot', $newBooking[0]['booking_pattern']);
        $this->assertSame(self::CUSTOMER_ID, $newBooking[0]['customer_id']);
        $this->assertSame(self::SERVICE_ID, $newBooking[0]['service_id']);
        $this->assertSame('web', $newBooking[0]['source'], 'Self-service reschedule source should be web');
        $this->assertStringContainsString($targetDate . ' 14:00', $newBooking[0]['start_datetime']);

        // Custom field data preserved
        $this->assertStringContainsString('peanuts', $newBooking[0]['custom_field_data'] ?? '',
            'custom_field_data should be preserved on the new booking');

        // Notes preserved
        $this->assertSame('Window seat preferred', $newBooking[0]['notes'] ?? null,
            'notes should be preserved on the new booking');

        // Verify audit log entry
        $audit = Database::query(
            "SELECT `action`, `details` FROM `audit_log`
             WHERE `entity_type` = 'booking' AND `entity_id` = ?
             ORDER BY `created_at` DESC LIMIT 1",
            [self::BOOKING_ID]
        );
        $this->assertNotEmpty($audit, 'Audit log entry should exist for reschedule');
        $this->assertSame('booking.rescheduled', $audit[0]['action']);

        // Verify audit log details include actor_type = customer
        $details = json_decode($audit[0]['details'] ?? '{}', true);
        $this->assertSame('customer', $details['actor_type'] ?? null,
            'Audit log should record actor_type as customer for self-service reschedule');
    }

    public function test_reschedule_returns_new_booking_details(): void
    {
        // Determine a valid target date
        $targetTs = strtotime('+4 days');
        while (date('N', $targetTs) >= 6) {
            $targetTs = strtotime('+1 day', $targetTs);
        }
        $targetDate = date('Y-m-d', $targetTs);

        // Create a fresh booking for this test
        $bookingId = '01TESTSSRESCHEDDETAILS0';
        $origDate = date('Y-m-d', strtotime('+2 days'));
        Database::execute("DELETE FROM `bookings` WHERE `id` = ?", [$bookingId]);
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`, `service_id`,
              `start_datetime`, `end_datetime`, `status`, `source`)
             VALUES (?, ?, 'timeslot', ?, ?,
              '{$origDate} 10:00:00', '{$origDate} 10:30:00',
              'confirmed', 'web')",
            [$bookingId, self::TENANT_ID, self::CUSTOMER_ID, self::SERVICE_ID]
        );

        self::ensureAvailability();

        $r = $this->postJsonWithCsrf("/api/test-fixture/bookings/{$bookingId}/reschedule", [
            'new_date' => $targetDate,
            'new_time' => '15:00',
        ]);

        $this->assertSame(200, $r['code'], 'Body: ' . $r['body']);
        $data = json_decode($r['body'], true);

        $this->assertArrayHasKey('new_booking', $data, 'Response should include new_booking object');
        $this->assertSame($targetDate, $data['new_booking']['date'] ?? null);
        $this->assertSame('15:00', $data['new_booking']['time'] ?? null);
    }

    // ════════════════════════════════════════════════════════════════
    // Consent integrity: no fabrication for admin-created bookings
    // ════════════════════════════════════════════════════════════════

    public function test_reschedule_admin_booking_does_not_fabricate_consent(): void
    {
        // Determine a valid target date
        $targetTs = strtotime('+5 days');
        while (date('N', $targetTs) >= 6) {
            $targetTs = strtotime('+1 day', $targetTs);
        }
        $targetDate = date('Y-m-d', $targetTs);

        // Create an admin-created booking WITHOUT consent evidence
        $bookingId = '01TESTSSRESCHEDNOCONSNT';
        $origDate = date('Y-m-d', strtotime('+2 days'));
        Database::execute("DELETE FROM `bookings` WHERE `id` = ?", [$bookingId]);
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`, `service_id`,
              `start_datetime`, `end_datetime`, `status`, `source`,
              `consent_given_at`, `consent_text_shown`)
             VALUES (?, ?, 'timeslot', ?, ?,
              '{$origDate} 10:00:00', '{$origDate} 10:30:00',
              'confirmed', 'admin',
              NULL, NULL)",
            [$bookingId, self::TENANT_ID, self::CUSTOMER_ID, self::SERVICE_ID]
        );

        self::ensureAvailability();

        $r = $this->postJsonWithCsrf("/api/test-fixture/bookings/{$bookingId}/reschedule", [
            'new_date' => $targetDate,
            'new_time' => '16:00',
        ]);

        $this->assertSame(200, $r['code'], 'Admin booking reschedule should succeed. Body: ' . $r['body']);
        $data = json_decode($r['body'], true);
        $newBookingId = $data['new_booking_id'] ?? null;
        $this->assertNotEmpty($newBookingId);

        // Verify the new booking has NO consent evidence (not fabricated)
        $newBooking = Database::query(
            "SELECT `consent_given_at`, `consent_text_shown` FROM `bookings` WHERE `id` = ?",
            [$newBookingId]
        );
        $this->assertNotEmpty($newBooking, 'New booking should exist');
        $this->assertNull($newBooking[0]['consent_given_at'],
            'Rescheduled admin booking must NOT have fabricated consent_given_at');
        $this->assertNull($newBooking[0]['consent_text_shown'],
            'Rescheduled admin booking must NOT have fabricated consent_text_shown');
    }

    // ════════════════════════════════════════════════════════════════
    // Resource reschedule: happy path
    // ════════════════════════════════════════════════════════════════

    public function test_successful_resource_reschedule_via_public_api(): void
    {
        self::seedResourceFixture();

        $bookingId = '01TESTSSRESCHEDRESBOOK0';
        $checkIn   = date('Y-m-d', strtotime('+10 days'));
        $checkOut  = date('Y-m-d', strtotime('+12 days'));

        Database::execute("DELETE FROM `bookings` WHERE `id` = ?", [$bookingId]);
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`, `resource_id`,
              `start_datetime`, `end_datetime`, `status`, `source`, `party_size`)
             VALUES (?, ?, 'resource', ?, ?,
              '{$checkIn} 00:00:00', '{$checkOut} 00:00:00',
              'confirmed', 'web', 2)",
            [$bookingId, self::TENANT_ID, self::CUSTOMER_ID, self::RESOURCE_ID]
        );

        $newCheckIn  = date('Y-m-d', strtotime('+20 days'));
        $newCheckOut = date('Y-m-d', strtotime('+22 days'));

        // Get CSRF from the manage page for THIS booking
        $csrf = $this->getCsrfForBooking($bookingId);

        $r = $this->postJson("/api/test-fixture/bookings/{$bookingId}/reschedule", [
            'check_in'  => $newCheckIn,
            'check_out' => $newCheckOut,
        ], $csrf);

        $this->assertSame(200, $r['code'], 'Resource reschedule should succeed. Body: ' . $r['body']);
        $data = json_decode($r['body'], true);
        $this->assertTrue($data['rescheduled'] ?? false);
        $this->assertNotEmpty($data['new_booking_id'] ?? null);

        // Verify new_booking includes check_in/check_out
        $this->assertSame($newCheckIn, $data['new_booking']['check_in'] ?? null,
            'Response new_booking should include check_in');
        $this->assertSame($newCheckOut, $data['new_booking']['check_out'] ?? null,
            'Response new_booking should include check_out');

        // Verify DB chain
        $original = Database::query(
            "SELECT `status`, `rescheduled_to_id` FROM `bookings` WHERE `id` = ?",
            [$bookingId]
        );
        $this->assertSame('rescheduled', $original[0]['status'] ?? null);

        $newBooking = Database::query(
            "SELECT `status`, `booking_pattern`, `resource_id` FROM `bookings` WHERE `id` = ?",
            [$original[0]['rescheduled_to_id']]
        );
        $this->assertSame('confirmed', $newBooking[0]['status']);
        $this->assertSame('resource', $newBooking[0]['booking_pattern']);
        $this->assertSame(self::RESOURCE_ID, $newBooking[0]['resource_id']);
    }

    // ════════════════════════════════════════════════════════════════
    // Capacity reschedule: happy path
    // ════════════════════════════════════════════════════════════════

    public function test_successful_capacity_reschedule_via_public_api(): void
    {
        // Target date: +17 days (weekday)
        $newDt = new \DateTimeImmutable('+17 days');
        while ((int) $newDt->format('N') >= 6) {
            $newDt = $newDt->modify('+1 day');
        }
        $newDate = $newDt->format('Y-m-d');
        $newDow = ((int) $newDt->format('N')) - 1;

        // Original date: +10 days (weekday)
        $origDt = new \DateTimeImmutable('+10 days');
        while ((int) $origDt->format('N') >= 6) {
            $origDt = $origDt->modify('+1 day');
        }
        $origDate = $origDt->format('Y-m-d');
        $origDow  = ((int) $origDt->format('N')) - 1;

        // Seed slots for both DOWs
        self::seedCapacitySlot(self::CAP_SLOT_ID, $origDow);
        $targetSlotId = '01TESTSSRESSLOT' . str_pad((string) $newDow, 8, '0', STR_PAD_LEFT);
        $targetSlotId = self::seedCapacitySlot($targetSlotId, $newDow);

        $bookingId = '01TESTSSRESCHEDCAPBOOK0';
        Database::execute("DELETE FROM `bookings` WHERE `id` = ?", [$bookingId]);
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`,
              `start_datetime`, `end_datetime`, `status`, `source`, `party_size`)
             VALUES (?, ?, 'capacity', ?,
              '{$origDate} 06:00:00', '{$origDate} 07:30:00',
              'confirmed', 'web', 2)",
            [$bookingId, self::TENANT_ID, self::CUSTOMER_ID]
        );

        $csrf = $this->getCsrfForBooking($bookingId);

        $r = $this->postJson("/api/test-fixture/bookings/{$bookingId}/reschedule", [
            'date'    => $newDate,
            'slot_id' => $targetSlotId,
        ], $csrf);

        $this->assertSame(200, $r['code'], 'Capacity reschedule should succeed. Body: ' . $r['body']);
        $data = json_decode($r['body'], true);
        $this->assertTrue($data['rescheduled'] ?? false);

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
    // Event reschedule: happy path + P1 regression guard
    // ════════════════════════════════════════════════════════════════

    public function test_event_reschedule_without_date_returns_422(): void
    {
        self::seedEventFixture();

        $bookingId = '01TESTSSRESCHEDEVTNODT0';
        $origDate  = date('Y-m-d', strtotime('+10 days'));
        Database::execute("DELETE FROM `bookings` WHERE `id` = ?", [$bookingId]);
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`, `event_id`,
              `start_datetime`, `end_datetime`, `status`, `source`, `party_size`)
             VALUES (?, ?, 'event', ?, ?,
              '{$origDate} 14:00:00', '{$origDate} 16:00:00',
              'confirmed', 'web', 1)",
            [$bookingId, self::TENANT_ID, self::CUSTOMER_ID, self::EVENT_ID]
        );

        $csrf = $this->getCsrfForBooking($bookingId);

        // POST without date — must fail with invalid_date
        $r = $this->postJson("/api/test-fixture/bookings/{$bookingId}/reschedule", [
            'event_id' => self::EVENT_ID,
        ], $csrf);

        $this->assertSame(422, $r['code'], 'Event reschedule without date should return 422');
        $data = json_decode($r['body'], true);
        $this->assertSame('invalid_date', $data['error'] ?? null,
            'Missing date should trigger invalid_date error');
    }

    public function test_successful_event_reschedule_via_public_api(): void
    {
        self::seedEventFixture();

        $bookingId = '01TESTSSRESCHEDEVTBOOK0';
        $origDate  = date('Y-m-d', strtotime('+10 days'));
        $newDate   = date('Y-m-d', strtotime('+17 days'));

        Database::execute("DELETE FROM `bookings` WHERE `id` = ?", [$bookingId]);
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`, `event_id`,
              `start_datetime`, `end_datetime`, `status`, `source`, `party_size`)
             VALUES (?, ?, 'event', ?, ?,
              '{$origDate} 14:00:00', '{$origDate} 16:00:00',
              'confirmed', 'web', 1)",
            [$bookingId, self::TENANT_ID, self::CUSTOMER_ID, self::EVENT_ID]
        );

        $csrf = $this->getCsrfForBooking($bookingId);

        // POST with both date and event_id
        $r = $this->postJson("/api/test-fixture/bookings/{$bookingId}/reschedule", [
            'date'     => $newDate,
            'event_id' => self::EVENT_ID,
        ], $csrf);

        $this->assertSame(200, $r['code'], 'Event reschedule should succeed. Body: ' . $r['body']);
        $data = json_decode($r['body'], true);
        $this->assertTrue($data['rescheduled'] ?? false);
        $this->assertNotEmpty($data['new_booking_id'] ?? null);

        // Verify event_name is in the response
        $this->assertNotEmpty($data['new_booking']['event_name'] ?? null,
            'Response new_booking should include event_name');

        // Verify DB chain
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

    private static function seedFixtures(): void
    {
        $tenantId = self::TENANT_ID;

        // Nuclear cleanup: delete ALL bookings for this tenant to eliminate
        // cross-class contamination from other test suites (RescheduleEndpointTest,
        // AdminRoutesTest, etc.) that share the same test-fixture tenant and may
        // leave bookings at conflicting time slots.
        Database::execute("DELETE FROM `bookings` WHERE `tenant_id` = ?", [$tenantId]);
        Database::execute("DELETE FROM `customers` WHERE `id` = ?", [self::CUSTOMER_ID]);
        Database::execute("DELETE FROM `services` WHERE `id` = ?", [self::SERVICE_ID]);

        // Customer
        Database::execute(
            "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`)
             VALUES (?, ?, 'SS Reschedule Customer', 'ss-resched@example.com')",
            [self::CUSTOMER_ID, $tenantId]
        );

        // Service (30-minute duration)
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`, `is_active`, `sort_order`)
             VALUES (?, ?, 'SS Reschedule Service', 30, 1, 99)",
            [self::SERVICE_ID, $tenantId]
        );

        self::ensureAvailability();

        // Confirmed timeslot booking (the main fixture)
        $startDate = date('Y-m-d', strtotime('+2 days'));
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`, `service_id`,
              `start_datetime`, `end_datetime`, `status`, `source`)
             VALUES (?, ?, 'timeslot', ?, ?,
              '{$startDate} 10:00:00', '{$startDate} 10:30:00',
              'confirmed', 'web')",
            [self::BOOKING_ID, $tenantId, self::CUSTOMER_ID, self::SERVICE_ID]
        );
    }

    private static function ensureAvailability(): void
    {
        $tenantId = self::TENANT_ID;

        // Seed weekday availability (Mon-Fri = day_of_week 0-4) if not present
        $existing = Database::query(
            "SELECT COUNT(*) AS cnt FROM `availability` WHERE `tenant_id` = ? AND `staff_id` IS NULL",
            [$tenantId]
        );

        if ((int) ($existing[0]['cnt'] ?? 0) < 5) {
            Database::execute(
                "DELETE FROM `availability` WHERE `tenant_id` = ? AND `staff_id` IS NULL",
                [$tenantId]
            );
            for ($dow = 0; $dow <= 4; $dow++) {
                Database::execute(
                    "INSERT INTO `availability` (`id`, `tenant_id`, `day_of_week`, `start_time`, `end_time`, `is_available`)
                     VALUES (?, ?, ?, '08:00', '18:00', 1)",
                    ["01TESTSSRESCHEDAVAIL0{$dow}0", $tenantId, $dow]
                );
            }
        }
    }

    private function insertBooking(string $id, string $status, string $pattern): void
    {
        $startDate = date('Y-m-d', strtotime('+2 days'));

        Database::execute("DELETE FROM `bookings` WHERE `id` = ?", [$id]);
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`,
              `start_datetime`, `end_datetime`, `status`, `source`)
             VALUES (?, ?, ?, ?,
              '{$startDate} 10:00:00', '{$startDate} 10:30:00',
              ?, 'web')",
            [$id, self::TENANT_ID, $pattern, self::CUSTOMER_ID, $status]
        );
    }

    private static function seedResourceFixture(): void
    {
        $existing = Database::query("SELECT `id` FROM `resources` WHERE `id` = ?", [self::RESOURCE_ID]);
        if (!empty($existing)) return;

        Database::execute(
            "INSERT INTO `resources` (`id`, `tenant_id`, `name`, `capacity`, `min_stay_nights`, `max_stay_nights`, `is_active`)
             VALUES (?, ?, 'SS Reschedule Cabin', 4, 1, 30, 1)",
            [self::RESOURCE_ID, self::TENANT_ID]
        );
    }

    private static function seedCapacitySlot(string $slotId, int $dow): string
    {
        // Check by the actual unique constraint to avoid collisions
        $existing = Database::query(
            "SELECT `id` FROM `capacity_slots` WHERE `tenant_id` = ? AND `day_of_week` = ? AND `start_time` = '06:00:00' AND `end_time` = '07:30:00'",
            [self::TENANT_ID, $dow]
        );
        if (!empty($existing)) return $existing[0]['id'];

        Database::execute(
            "INSERT INTO `capacity_slots` (`id`, `tenant_id`, `day_of_week`, `start_time`, `end_time`, `max_capacity`, `min_party_size`, `max_party_size`, `is_active`)
             VALUES (?, ?, ?, '06:00:00', '07:30:00', 20, 1, 8, 1)",
            [$slotId, self::TENANT_ID, $dow]
        );
        return $slotId;
    }

    private static function seedEventFixture(): void
    {
        $existing = Database::query("SELECT `id` FROM `events` WHERE `id` = ?", [self::EVENT_ID]);
        if (!empty($existing)) return;

        Database::execute(
            "INSERT INTO `events` (`id`, `tenant_id`, `name`, `max_participants`, `start_datetime`, `end_datetime`, `is_recurring`, `rrule`, `is_active`, `allow_waitlist`, `waitlist_max`)
             VALUES (?, ?, 'SS Reschedule Workshop', 20, '2026-01-01 14:00:00', '2026-01-01 16:00:00', 1, 'FREQ=DAILY;COUNT=365', 1, 0, 0)",
            [self::EVENT_ID, self::TENANT_ID]
        );
    }

    // ════════════════════════════════════════════════════════════════
    // HTTP helpers
    // ════════════════════════════════════════════════════════════════

    /**
     * Obtain a CSRF token by visiting the manage page.
     */
    private function getCsrfToken(): string
    {
        return $this->getCsrfForBooking(self::BOOKING_ID);
    }

    /**
     * Obtain a CSRF token for a specific booking's manage page.
     * Uses a fresh cookie jar session to avoid CSRF token reuse issues.
     */
    private function getCsrfForBooking(string $bookingId): string
    {
        $r = $this->get("/book/test-fixture/manage/{$bookingId}");
        if (preg_match('/window\.\x5f\x5fVB_CSRF\x5f\x5f\s*=\s*"([a-f0-9]+)"/', $r['body'], $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * POST JSON with CSRF token.
     */
    private function postJsonWithCsrf(string $path, array $data): array
    {
        $csrf = $this->getCsrfToken();
        return $this->postJson($path, $data, $csrf);
    }

    /**
     * POST JSON to the given path.
     *
     * Extra headers let a test mimic the embed iframe, which sends
     * an Origin header and nothing else.
     */
    private function postJson(string $path, array $data, string $csrfToken, array $extraHeaders = []): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($csrfToken !== '') {
            $headers[] = 'X-CSRF-Token: ' . $csrfToken;
        }
        $headers = array_merge($headers, $extraHeaders);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
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
        return compact('code', 'body');
    }

    /**
     * @return array{code: int, body: string}
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
        return compact('code', 'body');
    }
}
