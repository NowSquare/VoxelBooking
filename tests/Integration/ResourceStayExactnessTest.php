<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Agent\ActorContext;
use App\Agent\SchemaValidator;
use App\Application\Bookings\BookingCommands;
use App\Application\Bookings\BookingDraft;
use App\Application\Bookings\BookingOperations;
use App\Application\Bookings\BookingQueries;
use App\Engine\Database;
use App\Engine\ResourceCalculator;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * A stay is priced in exact cents and described by local calendar dates.
 *
 * The venue sits in Auckland, twelve or thirteen hours ahead of UTC, so a
 * local midnight converted to UTC always lands on the previous day. Any UTC
 * instant leaking into a stay receipt would name the wrong check-in date.
 */
final class ResourceStayExactnessTest extends TestCase
{
    private const TIMEZONE = 'Pacific/Auckland';

    private string $tenantId = TestFixtures::BUSINESS_TENANT_ID;

    /** The shared fixture tenant's settings this class changes, restored afterwards. */
    private array $originalSettings = [];

    /** @var list<string> */
    private array $resourceIds = [];

    protected function setUp(): void
    {
        \Tests\Support\TestEnvironment::boot();
        TestFixtures::provision();
        \Tests\Support\CustomerFixtureState::restore();
        $this->originalSettings = Database::query(
            'SELECT timezone, max_bookings_per_customer_per_day FROM tenants WHERE id = ?',
            [$this->tenantId]
        )[0];
        Database::execute(
            "UPDATE tenants SET booking_pattern = 'resource', timezone = ?, requires_consent = 0, min_advance_hours = 0,
             max_advance_days = 365, booking_requires_approval = 0, max_bookings_per_customer_per_day = 10 WHERE id = ?",
            [self::TIMEZONE, $this->tenantId]
        );
    }

    protected function tearDown(): void
    {
        // The fixture tenant is provisioned once per process; leave it as other classes expect it.
        foreach ($this->resourceIds as $resourceId) {
            Database::execute('DELETE FROM bookings WHERE tenant_id = ? AND resource_id = ?', [$this->tenantId, $resourceId]);
            Database::execute('DELETE FROM seasonal_pricing WHERE tenant_id = ? AND resource_id = ?', [$this->tenantId, $resourceId]);
            Database::execute('DELETE FROM resources WHERE tenant_id = ? AND id = ?', [$this->tenantId, $resourceId]);
        }
        Database::execute("DELETE FROM customers WHERE tenant_id = ? AND email = 'stay@example.com'", [$this->tenantId]);
        Database::execute(
            'UPDATE tenants SET timezone = ?, max_bookings_per_customer_per_day = ? WHERE id = ?',
            [$this->originalSettings['timezone'], $this->originalSettings['max_bookings_per_customer_per_day'], $this->tenantId]
        );
        \Tests\Support\CustomerFixtureState::restore();
    }

    public function test_nightly_rates_add_up_to_exact_cents(): void
    {
        $checkIn = $this->localDate(30);
        $resourceId = $this->resource('70.35');
        $this->season($resourceId, $checkIn, $checkIn, '50.25');

        $quote = ResourceCalculator::checkAvailability($this->tenant(), $resourceId, $checkIn, $this->localDate(33));

        $this->assertTrue($quote['available'], (string) $quote['error']);
        $this->assertSame('190.95', $quote['total']);
        $this->assertSame(['50.25', '70.35', '70.35'], array_column($quote['pricing'], 'price'));
    }

    public function test_rates_that_drift_in_binary_floating_point_stay_exact_over_many_nights(): void
    {
        // 0.1 and 0.2 have no exact binary form; a float running sum drifts away from the cent.
        $checkIn = $this->localDate(20);
        $resourceId = $this->resource('0.10', maxStay: 60);
        $this->season($resourceId, $checkIn, $this->localDate(39), '0.20');

        $quote = ResourceCalculator::checkAvailability($this->tenant(), $resourceId, $checkIn, $this->localDate(70));

        $this->assertTrue($quote['available'], (string) $quote['error']);
        $this->assertSame('7.00', $quote['total'], '20 nights at 0.20 plus 30 nights at 0.10');
    }

    public function test_an_unpriced_resource_has_no_total(): void
    {
        $resourceId = $this->resource(null);

        $quote = ResourceCalculator::checkAvailability($this->tenant(), $resourceId, $this->localDate(30), $this->localDate(32));
        $draft = BookingDraft::prepare($this->tenant(), [
            'resource_id' => $resourceId,
            'check_in' => $this->localDate(30),
            'check_out' => $this->localDate(32),
        ]);

        $this->assertTrue($quote['available'], (string) $quote['error']);
        $this->assertNull($quote['total']);
        $this->assertSame([null, null], array_column($quote['pricing'], 'price'));
        $this->assertNull($draft['display']['total']);
        $this->assertNull($draft['data']['price_total']);
    }

    public function test_a_stay_partly_without_a_price_has_no_total(): void
    {
        // A quote that silently counts an unpriced night as free would undercharge.
        $checkIn = $this->localDate(30);
        $resourceId = $this->resource(null);
        $this->season($resourceId, $checkIn, $checkIn, '80.00');

        $quote = ResourceCalculator::checkAvailability($this->tenant(), $resourceId, $checkIn, $this->localDate(32));

        $this->assertNull($quote['total']);
        $this->assertSame(['80.00', null], array_column($quote['pricing'], 'price'));
    }

    public function test_availability_quote_passes_exact_and_unpriced_nights_through(): void
    {
        $checkIn = $this->localDate(30);
        $checkOut = $this->localDate(32);
        $unpriced = $this->resource(null);
        $this->season($unpriced, $checkIn, $checkIn, '80.05');
        $public = new ActorContext('public', Ulid::generate(), 'prospective_customer', [$this->tenantId], ['public:read', 'public:book'], authMode: 'public');
        $search = static fn (string $resourceId) => \App\Application\Availability\AvailabilityService::search(
            $public,
            ['business_id' => TestFixtures::BUSINESS_TENANT_ID, 'resource_id' => $resourceId, 'check_in' => $checkIn, 'check_out' => $checkOut]
        );

        $partlyPriced = $search($unpriced);
        $priced = $search($this->resource('70.35'));

        // An unpriced night is not a free night, so it must not read "0.00".
        $this->assertSame(['80.05', null], array_column($partlyPriced['quote']['pricing'], 'price'));
        $this->assertNull($partlyPriced['quote']['total']);
        $this->assertSame(['70.35', '70.35'], array_column($priced['quote']['pricing'], 'price'));
        $this->assertSame('140.70', $priced['quote']['total']);
        foreach (\App\Application\Availability\AvailabilityOperations::definitions() as $operation) {
            if ($operation->operationId === 'availability_search') {
                (new SchemaValidator())->validate($operation->outputSchema, $partlyPriced, true);
                (new SchemaValidator())->validate($operation->outputSchema, $priced, true);
            }
        }
    }

    public function test_stay_receipt_preview_and_read_name_local_dates_only(): void
    {
        $checkIn = $this->localDate(25);
        $checkOut = $this->localDate(27);
        $previousDay = $this->localDate(24);
        $resourceId = $this->resource('120.50');
        $input = [
            'business_id' => $this->tenantId,
            'resource_id' => $resourceId,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'guest_count' => 2,
            'customer' => ['name' => 'Stay Guest', 'email' => 'stay@example.com'],
        ];
        $public = new ActorContext('public', Ulid::generate(), 'prospective_customer', [$this->tenantId], ['public:read', 'public:book'], authMode: 'public');

        $preview = BookingOperations::previewCreate($public, $input);
        $receipt = BookingCommands::create($public, $input);
        $read = BookingQueries::get($this->staff(), ['business_id' => $this->tenantId, 'booking_id' => $receipt['id']]);

        foreach (['preview' => $preview, 'receipt' => $receipt, 'read' => $read] as $surface => $result) {
            $this->assertSame($checkIn, $result['check_in'] ?? $result['booking']['check_in'], $surface);
            $this->assertSame($checkOut, $result['check_out'] ?? $result['booking']['check_out'], $surface);
            $this->assertSame(2, $result['nights'] ?? $result['booking']['nights'], $surface);
            $this->assertArrayNotHasKey('start_utc', $result, $surface);
            $this->assertArrayNotHasKey('end_utc', $result, $surface);
            // Policy deadlines are true instants before arrival and may rightly fall on the previous day.
            $stay = array_diff_key($result, array_flip(['customer_cancellation', 'customer_rescheduling', 'cancellation', 'rescheduling']));
            $this->assertStringNotContainsString($previousDay, json_encode($stay, JSON_THROW_ON_ERROR), $surface . ' names the day before check-in');
        }
        $this->assertSame('241.00', $receipt['total']);
        $this->assertArrayNotHasKey('time', $receipt);
        $this->assertArrayNotHasKey('end_time', $receipt);

        $validator = new SchemaValidator();
        $validator->validate(BookingOperations::creationSchema(), $receipt, true);
        $validator->validate(BookingOperations::bookingSchema(), $read, true);
    }

    public function test_timed_receipts_still_require_utc_instants(): void
    {
        $receipt = [
            'id' => 'x', 'business_id' => 'x', 'booking_pattern' => 'timeslot', 'status' => 'confirmed', 'timezone' => self::TIMEZONE,
            'notification_state' => 'none', 'date' => '2027-01-10', 'time' => '09:00', 'end_time' => '10:00',
            'consent_recorded' => false, 'email_sent' => false,
        ];

        $this->expectException(\App\Agent\OperationException::class);
        (new SchemaValidator())->validate(BookingOperations::creationSchema(), $receipt, true);
    }

    private function tenant(): array
    {
        return Database::query('SELECT * FROM tenants WHERE id = ?', [$this->tenantId])[0];
    }

    private function staff(): ActorContext
    {
        return new ActorContext('business_user', TestFixtures::BUSINESS_USER_ID, 'business_owner', [$this->tenantId], ['bookings:read', 'bookings:write']);
    }

    private function localDate(int $daysAhead): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone(self::TIMEZONE)))->modify("+{$daysAhead} days")->format('Y-m-d');
    }

    private function resource(?string $pricePerNight, int $maxStay = 30): string
    {
        $id = Ulid::generate();
        Database::execute(
            "INSERT INTO resources (id, tenant_id, name, capacity, price_per_night, min_stay_nights, max_stay_nights, is_active)
             VALUES (?, ?, 'Harbour Room', 4, ?, 1, ?, 1)",
            [$id, $this->tenantId, $pricePerNight, $maxStay]
        );
        $this->resourceIds[] = $id;

        return $id;
    }

    private function season(string $resourceId, string $from, string $to, string $price): void
    {
        Database::execute(
            "INSERT INTO seasonal_pricing (id, resource_id, tenant_id, start_date, end_date, price_per_night, label)
             VALUES (?, ?, ?, ?, ?, ?, 'Season')",
            [Ulid::generate(), $resourceId, $this->tenantId, $from, $to, $price]
        );
    }
}
