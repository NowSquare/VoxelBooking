<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Agent\OperationException;
use App\Application\Bookings\BookingCommands;
use App\Engine\Database;
use App\Engine\TimeSlotCalculator;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;
use Tests\Support\TimeslotBusiness;

/**
 * Which appointment times a business offers, and who can take them.
 *
 * The rules pinned here:
 * - A service without linked staff can be done by any active staff member.
 *   A business without staff books against its own opening hours.
 *   `requires_staff` is presentation metadata and never removes times.
 * - An explicitly chosen staff member must be linked to the service.
 * - The buffer is the gap between any two appointments, whichever was booked first.
 * - The minimum advance period counts hours, so it can reach past tomorrow.
 * - A booking without staff, and approving or moving one, needs one free
 *   eligible staff member; bookings of other staff never block it.
 */
final class TimeslotAvailabilityRulesTest extends TestCase
{
    private ?TimeslotBusiness $business = null;

    protected function setUp(): void
    {
        \Tests\Support\TestEnvironment::boot();
        TestFixtures::provision();
    }

    protected function tearDown(): void
    {
        $this->business?->delete();
    }

    public function testServiceWithoutLinkedStaffIsDoneByAnyActiveStaffMember(): void
    {
        $business = $this->business();
        $alex = $business->staff('Alex');
        $service = $business->service(linkedStaff: []);

        $slots = TimeSlotCalculator::getAvailableSlots($business->row(), self::day(3), $service)['slots'];

        $this->assertNotEmpty($slots, 'Unlinked services stay bookable after an upgrade.');
        $this->assertSame($alex, $slots[0]['staff_id']);
    }

    public function testBusinessWithoutStaffBooksAgainstOpeningHours(): void
    {
        $business = $this->business();
        $service = $business->service(linkedStaff: []);

        $slots = TimeSlotCalculator::getAvailableSlots($business->row(), self::day(3), $service)['slots'];

        $this->assertSame('09:00', $slots[0]['time'] ?? null);
        $this->assertNull($slots[0]['staff_id']);
    }

    public function testCustomerCanBookAServiceWithoutLinkedStaff(): void
    {
        $business = $this->business();
        $alex = $business->staff('Alex');
        $service = $business->service(linkedStaff: []);

        $receipt = BookingCommands::create($business->prospectiveCustomer(), [
            'business_id' => $business->id,
            'service_id' => $service,
            'start_datetime' => self::day(3) . 'T10:00:00',
            'customer' => ['name' => 'Robin Example', 'email' => 'robin-' . strtolower(substr(Ulid::generate(), -6)) . '@example.com'],
            'consent_given' => true,
        ]);

        $this->assertSame('confirmed', $receipt['status']);
        $this->assertSame($alex, Database::query('SELECT staff_id FROM bookings WHERE id = ?', [$receipt['id']])[0]['staff_id']);
    }

    public function testAnyActiveStaffMemberCanBeChosenForAServiceWithoutLinkedStaff(): void
    {
        $business = $this->business();
        $business->staff('Alex');
        $sam = $business->staff('Sam');
        $service = $business->service(linkedStaff: []);

        $slots = TimeSlotCalculator::getAvailableSlots($business->row(), self::day(3), $service, $sam)['slots'];
        $this->assertSame($sam, $slots[0]['staff_id'] ?? null, 'Choosing a staff member for an unlinked service keeps them.');

        $receipt = BookingCommands::create($business->prospectiveCustomer(), [
            'business_id' => $business->id,
            'service_id' => $service,
            'staff_id' => $sam,
            'start_datetime' => self::day(3) . 'T10:00:00',
            'customer' => ['name' => 'Robin Example', 'email' => 'robin-' . strtolower(substr(Ulid::generate(), -6)) . '@example.com'],
        ]);

        $moved = BookingCommands::reschedule($business->owner(), [
            'business_id' => $business->id,
            'booking_id' => $receipt['id'],
            'target' => ['new_date' => self::day(4), 'new_time' => '11:00'],
        ]);
        $this->assertSame($sam, Database::query('SELECT staff_id FROM bookings WHERE id = ?', [$moved['new_booking_id']])[0]['staff_id']);
    }

    public function testExplicitStaffMustBeLinkedToTheService(): void
    {
        $business = $this->business();
        $alex = $business->staff('Alex');
        $sam = $business->staff('Sam');
        $service = $business->service(linkedStaff: [$alex]);

        $this->assertSame([], TimeSlotCalculator::getAvailableSlots($business->row(), self::day(3), $service, $sam)['slots']);
    }

    public function testBufferSeparatesAppointmentsWhicheverWasBookedFirst(): void
    {
        $business = $this->business(['buffer_minutes' => 15]);
        $alex = $business->staff('Alex');
        $service = $business->service(linkedStaff: [$alex], minutes: 60);
        $business->occupy($alex, self::day(3) . ' 10:00:00', self::day(3) . ' 11:00:00', 'confirmed');

        $times = array_column(TimeSlotCalculator::getAvailableSlots($business->row(), self::day(3), $service, $alex)['slots'], 'time');

        $this->assertNotContains('09:00', $times, 'A 09:00 hour would end with no gap before 10:00.');
        $this->assertNotContains('11:00', $times, 'An 11:00 start leaves no gap after 11:00.');
        $this->assertContains('11:30', $times);
    }

    public function testMinimumAdvanceCountsHoursAcrossDays(): void
    {
        $business = $this->business(['min_advance_hours' => 48]);
        $service = $business->service(linkedStaff: []);

        $this->assertSame([], TimeSlotCalculator::getAvailableSlots($business->row(), self::day(1), $service)['slots']);
        $this->assertNotEmpty(TimeSlotCalculator::getAvailableSlots($business->row(), self::day(3), $service)['slots']);
    }

    public function testBookingWithoutStaffIsApprovedWhenAnyEligibleStaffMemberIsFree(): void
    {
        $business = $this->business(['booking_requires_approval' => 1]);
        $alex = $business->staff('Alex');
        $sam = $business->staff('Sam');
        $business->service(linkedStaff: [$alex, $sam]);
        $business->occupy($alex, self::day(3) . ' 10:00:00', self::day(3) . ' 10:30:00', 'confirmed');
        $request = $business->occupy(null, self::day(3) . ' 10:00:00', self::day(3) . ' 10:30:00', 'pending');

        $result = BookingCommands::changeStatus($business->owner(), ['business_id' => $business->id, 'booking_id' => $request, 'status' => 'confirmed']);

        $this->assertSame('confirmed', $result['status'], "Alex's booking does not block a request Sam can take.");
    }

    public function testBookingWithoutStaffConflictsWhenEveryEligibleStaffMemberIsBusy(): void
    {
        $business = $this->business(['booking_requires_approval' => 1]);
        $alex = $business->staff('Alex');
        $sam = $business->staff('Sam');
        $business->service(linkedStaff: [$alex, $sam]);
        $business->occupy($alex, self::day(3) . ' 10:00:00', self::day(3) . ' 10:30:00', 'confirmed');
        $business->occupy($sam, self::day(3) . ' 10:00:00', self::day(3) . ' 10:30:00', 'confirmed');
        $request = $business->occupy(null, self::day(3) . ' 10:00:00', self::day(3) . ' 10:30:00', 'pending');

        try {
            BookingCommands::changeStatus($business->owner(), ['business_id' => $business->id, 'booking_id' => $request, 'status' => 'confirmed']);
            $this->fail('A request no one can take must not be confirmed.');
        } catch (OperationException $conflict) {
            $this->assertSame('inventory_conflict', $conflict->problemCode);
        }
        $this->assertSame('pending', Database::query('SELECT status FROM bookings WHERE id = ?', [$request])[0]['status']);
    }

    public function testStaffedRequestIgnoresBookingsWithoutStaff(): void
    {
        $business = $this->business(['booking_requires_approval' => 1]);
        $alex = $business->staff('Alex');
        $business->service(linkedStaff: [$alex]);
        $business->occupy(null, self::day(3) . ' 10:00:00', self::day(3) . ' 10:30:00', 'confirmed');
        $request = $business->occupy($alex, self::day(3) . ' 10:00:00', self::day(3) . ' 10:30:00', 'pending');

        $result = BookingCommands::changeStatus($business->owner(), ['business_id' => $business->id, 'booking_id' => $request, 'status' => 'confirmed']);

        $this->assertSame('confirmed', $result['status']);
    }

    private function business(array $columns = []): TimeslotBusiness
    {
        return $this->business = TimeslotBusiness::create($columns);
    }

    private static function day(int $offset): string
    {
        return TimeslotBusiness::day($offset);
    }
}
