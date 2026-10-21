<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;
use Tests\Support\PublicBookingClient;

/**
 * The booking page explains every conflict in the customer's own terms.
 *
 * The shared operation layer reports conflicts as problem codes. The public
 * booking API turns each one into the specific translated message the booking
 * page shows in place, and sends `errors` only when a field needs correcting.
 * An empty `errors` collection once sent customers back to the details step
 * with no explanation, so every response here asserts its absence.
 *
 * Uses real HTTP against the isolated application at APP_TEST_URL.
 */
final class PublicBookingConflictTest extends TestCase
{
    private static string $setupError = '';

    /** @var array<string, array<string, string>> pattern => seeded ids */
    private static array $seed = [];

    /** @var array<string, mixed> */
    private static array $messages = [];

    public static function setUpBeforeClass(): void
    {
        try {
            require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
            \Tests\Support\TestEnvironment::boot();
            TestFixtures::provision();
            self::$messages = require dirname(__DIR__, 2) . '/lang/en/booking.php';
            self::seedTimeslot();
            self::seedResource();
            self::seedCapacity();
            self::seedEvent();
        } catch (\Throwable $error) {
            self::$setupError = 'Provisioning failed: ' . $error->getMessage();
        }
    }

    protected function setUp(): void
    {
        // The isolated harness always provides the application and database,
        // so a fixture problem is a failure, never a silent skip.
        if (self::$setupError !== '') {
            $this->fail(self::$setupError);
        }
        // Each case is one customer's attempt, not a burst from one address.
        Database::execute('DELETE FROM `rate_limits`');
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$seed as $ids) {
            try {
                Database::execute('DELETE FROM `bookings` WHERE `tenant_id` = ?', [$ids['tenant_id']]);
                Database::execute('DELETE FROM `customers` WHERE `tenant_id` = ?', [$ids['tenant_id']]);
                Database::execute('DELETE FROM `tenants` WHERE `id` = ?', [$ids['tenant_id']]);
            } catch (\Throwable) {
            }
        }
    }

    // ── Timeslot ──

    public function testTakenTimeslotOffersRefreshedTimesWithItsOwnReason(): void
    {
        $response = $this->book('timeslot', [
            'service_id' => self::$seed['timeslot']['service_id'],
            'start_datetime' => self::day(7) . 'T10:00:00',
        ]);

        $this->assertConflict($response, 409, 'slot_unavailable', self::$messages['api']['slot_unavailable']);
        $this->assertNotEmpty($response['json']['alternatives'] ?? [], 'A taken time offers other times on the same day.');
    }

    public function testTimeslotOnABlockedDayExplainsTheConflictWithoutAlternatives(): void
    {
        $response = $this->book('timeslot', [
            'service_id' => self::$seed['timeslot']['service_id'],
            'start_datetime' => self::day(8) . 'T10:00:00',
        ]);

        $this->assertConflict($response, 409, 'slot_unavailable', self::$messages['api']['slot_unavailable']);
        $this->assertSame([], $response['json']['alternatives'] ?? []);
    }

    public function testDailyLimitExplainsItself(): void
    {
        $email = 'daily-limit-' . strtolower(substr(Ulid::generate(), -8)) . '@example.com';
        $first = $this->book('timeslot', [
            'service_id' => self::$seed['timeslot']['service_id'],
            'start_datetime' => self::day(9) . 'T11:00:00',
        ], $email);
        $this->assertSame(201, $first['code'], $first['body']);

        $second = $this->book('timeslot', [
            'service_id' => self::$seed['timeslot']['service_id'],
            'start_datetime' => self::day(9) . 'T13:00:00',
        ], $email);

        $this->assertConflict($second, 422, 'max_bookings_exceeded', self::$messages['api']['max_bookings_exceeded']);
    }

    public function testFieldCorrectionsAreTheOnlyResponsesWithErrors(): void
    {
        $response = $this->book('timeslot', [
            'service_id' => self::$seed['timeslot']['service_id'],
            'start_datetime' => self::day(10) . 'T10:00:00',
            'customer' => ['name' => '', 'email' => 'not-an-address'],
        ]);

        $this->assertSame(422, $response['code'], $response['body']);
        $this->assertSame(self::$messages['errors']['correct_fields'], $response['json']['message']);
        $this->assertSame(self::$messages['errors']['required_field'], $response['json']['errors']['customer.name'] ?? null);
        $this->assertSame(self::$messages['errors']['invalid_field'], $response['json']['errors']['customer.email'] ?? null);
    }

    // ── Resource ──

    public function testBookedRoomNamesTheReason(): void
    {
        $response = $this->book('resource', [
            'resource_id' => self::$seed['resource']['resource_id'],
            'check_in' => self::day(10),
            'check_out' => self::day(12),
            'guest_count' => 2,
        ]);

        $this->assertConflict($response, 409, 'already_booked', self::$messages['resource']['error_already_booked']);
    }

    public function testShortStayNamesTheMinimum(): void
    {
        $response = $this->book('resource', [
            'resource_id' => self::$seed['resource']['resource_id'],
            'check_in' => self::day(20),
            'check_out' => self::day(21),
            'guest_count' => 1,
        ]);

        $this->assertSame('min_stay_violation', $response['json']['error'] ?? null, $response['body']);
        $this->assertSame(self::$messages['resource']['error_min_stay_violation'], $response['json']['message']);
        $this->assertArrayNotHasKey('errors', $response['json']);
    }

    public function testTooManyGuestsNamesTheRoomLimit(): void
    {
        $response = $this->book('resource', [
            'resource_id' => self::$seed['resource']['resource_id'],
            'check_in' => self::day(20),
            'check_out' => self::day(22),
            'guest_count' => 3,
        ]);

        $this->assertSame('capacity_exceeded', $response['json']['error'] ?? null, $response['body']);
        $this->assertSame(self::$messages['resource']['error_capacity_exceeded'], $response['json']['message']);
        $this->assertArrayNotHasKey('errors', $response['json']);
    }

    // ── Capacity ──

    public function testFullTableSlotNamesTheRemainingCapacity(): void
    {
        $response = $this->book('capacity', [
            'slot_id' => self::$seed['capacity']['slot_ids'][(int) (new \DateTimeImmutable(self::day(7)))->format('N') - 1],
            'date' => self::day(7),
            'party_size' => 2,
        ]);

        $this->assertConflict($response, 409, 'capacity_exceeded', self::$messages['api']['capacity_exceeded']);
    }

    public function testPartyBelowMinimumNamesTheMinimum(): void
    {
        $response = $this->book('capacity', [
            'slot_id' => self::$seed['capacity']['slot_ids'][(int) (new \DateTimeImmutable(self::day(8)))->format('N') - 1],
            'date' => self::day(8),
            'party_size' => 1,
        ]);

        $this->assertSame('party_too_small', $response['json']['error'] ?? null, $response['body']);
        $this->assertSame(self::$messages['api']['party_too_small'], $response['json']['message']);
        $this->assertArrayNotHasKey('errors', $response['json']);
    }

    // ── Event ──

    public function testFullEventWithoutWaitlistSaysSo(): void
    {
        $response = $this->book('event', ['event_id' => self::$seed['event']['full_id'], 'date' => self::day(7), 'spot_count' => 1]);

        $this->assertConflict($response, 409, 'event_full', self::$messages['api']['event_full']);
    }

    public function testFullWaitlistSaysSo(): void
    {
        $response = $this->book('event', ['event_id' => self::$seed['event']['waitlist_id'], 'date' => self::day(8), 'spot_count' => 1]);

        $this->assertConflict($response, 409, 'waitlist_full', self::$messages['api']['waitlist_full']);
    }

    public function testSpotLimitsNameTheirNumbers(): void
    {
        $tooMany = $this->book('event', ['event_id' => self::$seed['event']['limited_id'], 'date' => self::day(9), 'spot_count' => 5]);
        $this->assertSame('spot_count_too_many', $tooMany['json']['error'] ?? null, $tooMany['body']);
        $this->assertSame(str_replace(':max', '4', self::$messages['api']['spot_count_too_many']), $tooMany['json']['message']);
        $this->assertArrayNotHasKey('errors', $tooMany['json']);

        $tooFew = $this->book('event', ['event_id' => self::$seed['event']['limited_id'], 'date' => self::day(9), 'spot_count' => 1]);
        $this->assertSame('spot_count_too_few', $tooFew['json']['error'] ?? null, $tooFew['body']);
        $this->assertSame(str_replace(':min', '2', self::$messages['api']['spot_count_too_few']), $tooFew['json']['message']);
        $this->assertArrayNotHasKey('errors', $tooFew['json']);
    }

    // ── Helpers ──

    /**
     * @param array{code: int, body: string, json: array<string, mixed>} $response
     */
    private function assertConflict(array $response, int $status, string $code, string $message): void
    {
        $this->assertSame($status, $response['code'], $response['body']);
        $this->assertSame($code, $response['json']['error'] ?? null, $response['body']);
        $this->assertSame($message, $response['json']['message'] ?? null, $response['body']);
        $this->assertNotSame(self::$messages['api']['booking_failed'], $response['json']['message'] ?? null);
        $this->assertArrayNotHasKey('errors', $response['json'], 'A conflict carries no field corrections.');
    }

    /**
     * @return array{code: int, body: string, json: array<string, mixed>}
     */
    private function book(string $pattern, array $selection, ?string $email = null): array
    {
        return PublicBookingClient::fromEnvironment()->book(self::$seed[$pattern]['slug'], $selection + [
            'customer' => [
                'name' => 'Conflict Example',
                'email' => $email ?? 'conflict-' . strtolower(substr(Ulid::generate(), -8)) . '@example.com',
            ],
            'consent_given' => true,
        ]);
    }

    private static function day(int $offset): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify("+{$offset} days")->format('Y-m-d');
    }

    private static function tenant(string $pattern, array $columns = []): array
    {
        $tenantId = Ulid::generate();
        $slug = 'conflict-' . $pattern . '-' . strtolower(substr($tenantId, -8));
        $row = $columns + [
            'id' => $tenantId,
            'slug' => $slug,
            'name' => 'Conflict ' . ucfirst($pattern),
            'email' => $slug . '@example.test',
            'booking_pattern' => $pattern,
            'status' => 'active',
            'timezone' => 'UTC',
            'locale' => 'en',
            'currency' => 'EUR',
            'requires_consent' => 0,
            'min_advance_hours' => 0,
            'max_advance_days' => 60,
            'max_bookings_per_customer_per_day' => 1,
        ];
        $names = '`' . implode('`, `', array_keys($row)) . '`';
        $marks = implode(', ', array_fill(0, count($row), '?'));
        Database::execute("INSERT INTO `tenants` ({$names}) VALUES ({$marks})", array_values($row));

        return ['tenant_id' => $tenantId, 'slug' => $slug];
    }

    private static function occupy(string $tenantId, array $columns): void
    {
        $customerId = Ulid::generate();
        Database::execute(
            "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`) VALUES (?, ?, 'Earlier Guest', ?)",
            [$customerId, $tenantId, 'earlier-' . strtolower(substr($customerId, -8)) . '@example.test']
        );
        $row = $columns + ['id' => Ulid::generate(), 'tenant_id' => $tenantId, 'customer_id' => $customerId, 'source' => 'web'];
        $names = '`' . implode('`, `', array_keys($row)) . '`';
        $marks = implode(', ', array_fill(0, count($row), '?'));
        Database::execute("INSERT INTO `bookings` ({$names}) VALUES ({$marks})", array_values($row));
    }

    private static function seedTimeslot(): void
    {
        $ids = self::tenant('timeslot');
        $ids['service_id'] = Ulid::generate();
        $staffId = Ulid::generate();
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`, `price`, `is_active`, `sort_order`)
             VALUES (?, ?, 'Consultation', 60, 40.00, 1, 1)",
            [$ids['service_id'], $ids['tenant_id']]
        );
        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`, `sort_order`) VALUES (?, ?, 'Alex', ?, 1, 1)",
            [$staffId, $ids['tenant_id'], $ids['slug'] . '-alex@example.test']
        );
        Database::execute('INSERT INTO `service_staff` (`service_id`, `staff_id`) VALUES (?, ?)', [$ids['service_id'], $staffId]);
        for ($day = 0; $day <= 6; $day++) {
            Database::execute(
                "INSERT INTO `availability` (`id`, `tenant_id`, `day_of_week`, `start_time`, `end_time`) VALUES (?, ?, ?, '09:00', '17:00')",
                [Ulid::generate(), $ids['tenant_id'], $day]
            );
        }
        self::occupy($ids['tenant_id'], [
            'booking_pattern' => 'timeslot',
            'service_id' => $ids['service_id'],
            'staff_id' => $staffId,
            'start_datetime' => self::day(7) . ' 10:00:00',
            'end_datetime' => self::day(7) . ' 11:00:00',
            'status' => 'confirmed',
        ]);
        Database::execute(
            "INSERT INTO `blocked_dates` (`id`, `tenant_id`, `start_date`, `end_date`, `reason`) VALUES (?, ?, ?, ?, 'Closed')",
            [Ulid::generate(), $ids['tenant_id'], self::day(8), self::day(8)]
        );
        self::$seed['timeslot'] = $ids;
    }

    private static function seedResource(): void
    {
        $ids = self::tenant('resource');
        $ids['resource_id'] = Ulid::generate();
        Database::execute(
            "INSERT INTO `resources` (`id`, `tenant_id`, `name`, `capacity`, `price_per_night`, `min_stay_nights`, `max_stay_nights`, `sort_order`, `is_active`)
             VALUES (?, ?, 'Cabin', 2, 100.00, 2, 14, 1, 1)",
            [$ids['resource_id'], $ids['tenant_id']]
        );
        self::occupy($ids['tenant_id'], [
            'booking_pattern' => 'resource',
            'resource_id' => $ids['resource_id'],
            'start_datetime' => self::day(10) . ' 00:00:00',
            'end_datetime' => self::day(12) . ' 00:00:00',
            'party_size' => 2,
            'status' => 'confirmed',
        ]);
        self::$seed['resource'] = $ids;
    }

    private static function seedCapacity(): void
    {
        $ids = self::tenant('capacity');
        $ids['slot_ids'] = [];
        for ($day = 0; $day <= 6; $day++) {
            $slotId = Ulid::generate();
            Database::execute(
                "INSERT INTO `capacity_slots` (`id`, `tenant_id`, `day_of_week`, `start_time`, `end_time`, `max_capacity`, `min_party_size`, `max_party_size`, `label`, `is_active`)
                 VALUES (?, ?, ?, '19:00:00', '21:00:00', 4, 2, 4, 'Dinner', 1)",
                [$slotId, $ids['tenant_id'], $day]
            );
            $ids['slot_ids'][$day] = $slotId;
        }
        $fullDay = (int) (new \DateTimeImmutable(self::day(7)))->format('N') - 1;
        self::occupy($ids['tenant_id'], [
            'booking_pattern' => 'capacity',
            'capacity_slot_id' => $ids['slot_ids'][$fullDay],
            'start_datetime' => self::day(7) . ' 19:00:00',
            'end_datetime' => self::day(7) . ' 21:00:00',
            'party_size' => 3,
            'status' => 'confirmed',
        ]);
        self::$seed['capacity'] = $ids;
    }

    private static function seedEvent(): void
    {
        $ids = self::tenant('event');
        $events = [
            'full_id' => [7, 2, 0, 0, 1, null],
            'waitlist_id' => [8, 2, 1, 1, 1, null],
            'limited_id' => [9, 20, 0, 0, 2, 4],
        ];
        foreach ($events as $key => [$offset, $seats, $allowWaitlist, $waitlistMax, $minSpots, $maxSpots]) {
            $ids[$key] = Ulid::generate();
            Database::execute(
                "INSERT INTO `events` (`id`, `tenant_id`, `name`, `price`, `max_participants`, `start_datetime`, `end_datetime`,
                 `is_recurring`, `allow_waitlist`, `waitlist_max`, `min_spot_count`, `max_spot_count`, `is_active`)
                 VALUES (?, ?, 'Workshop', '30.00', ?, ?, ?, 0, ?, ?, ?, ?, 1)",
                [$ids[$key], $ids['tenant_id'], $seats, self::day($offset) . ' 14:00:00', self::day($offset) . ' 16:00:00',
                 $allowWaitlist, $waitlistMax, $minSpots, $maxSpots]
            );
        }
        foreach (['full_id' => 7, 'waitlist_id' => 8] as $key => $offset) {
            self::occupy($ids['tenant_id'], [
                'booking_pattern' => 'event',
                'event_id' => $ids[$key],
                'start_datetime' => self::day($offset) . ' 14:00:00',
                'end_datetime' => self::day($offset) . ' 16:00:00',
                'party_size' => 2,
                'status' => 'confirmed',
            ]);
        }
        self::occupy($ids['tenant_id'], [
            'booking_pattern' => 'event',
            'event_id' => $ids['waitlist_id'],
            'start_datetime' => self::day(8) . ' 14:00:00',
            'end_datetime' => self::day(8) . ' 16:00:00',
            'party_size' => 1,
            'status' => 'waitlisted',
        ]);
        self::$seed['event'] = $ids;
    }
}
