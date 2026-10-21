<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Agent\ActorContext;
use App\Application\Bookings\BookingCommands;
use App\Engine\CustomerCapability;
use App\Engine\Database;
use App\Engine\Outbox;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;
use Tests\Support\TimeslotBusiness;

/**
 * Who hears about a booking change, whichever surface made it.
 *
 * The admin screens, the booking page and agents share these rules:
 *
 * | Change                  | Made by  | Customer                    | Business (when enabled)   |
 * |-------------------------|----------|-----------------------------|---------------------------|
 * | New booking             | customer | confirmation                | new booking               |
 * | New booking             | staff    | nothing                     | nothing                   |
 * | Cancellation            | customer | cancellation                | cancellation              |
 * | Cancellation            | staff    | nothing                     | nothing                   |
 * | Reschedule              | either   | reschedule confirmation     | new booking               |
 * | Approval or promotion   | staff    | approved or confirmation    | nothing                   |
 *
 * The business hears only about changes it did not make itself, as the PRD
 * defines staff notifications. Assertions read the mail sink after a real
 * delivery run, so they prove what was sent, not what was queued.
 */
final class BookingNotificationPolicyTest extends TestCase
{
    private ?TimeslotBusiness $business = null;
    private string $service = '';

    protected function setUp(): void
    {
        TestEnvironment::boot();
        TestFixtures::provision();
        $this->business = TimeslotBusiness::create([
            'notify_on_booking' => 1,
            'notify_on_cancellation' => 1,
            'notification_email' => 'desk@example.test',
            'allow_cancellation' => 1,
            'allow_rescheduling' => 1,
            'cancellation_hours_before' => 0,
            'rescheduling_hours_before' => 0,
        ]);
        $this->service = $this->business->service();
    }

    protected function tearDown(): void
    {
        $this->business?->delete();
    }

    public function testCustomerBookingNotifiesCustomerAndBusiness(): void
    {
        $booking = $this->create($this->business->prospectiveCustomer());

        $this->assertSame([['confirmation', 'robin@example.com'], ['staff_notification', 'desk@example.test']], $this->delivered($booking));
    }

    public function testStaffBookingSendsNoEmail(): void
    {
        $booking = $this->create($this->business->owner());

        $this->assertSame([], $this->delivered($booking));
    }

    public function testCustomerCancellationNotifiesCustomerAndBusiness(): void
    {
        $booking = $this->create($this->business->owner());

        BookingCommands::cancel($this->verifiedCustomer($booking), ['business_id' => $this->business->id, 'booking_id' => $booking]);

        $this->assertSame([['cancellation', 'robin@example.com'], ['staff_notification', 'desk@example.test']], $this->delivered($booking));
    }

    public function testStaffCancellationSendsNoEmail(): void
    {
        $booking = $this->create($this->business->owner());

        BookingCommands::cancel($this->business->owner(), ['business_id' => $this->business->id, 'booking_id' => $booking]);

        $this->assertSame([], $this->delivered($booking));
    }

    public function testRescheduleNotifiesCustomerAndBusinessWhoeverMovesIt(): void
    {
        $byStaff = BookingCommands::reschedule($this->business->owner(), [
            'business_id' => $this->business->id,
            'booking_id' => $this->create($this->business->owner()),
            'target' => ['new_date' => TimeslotBusiness::day(5), 'new_time' => '11:00'],
        ]);
        $this->assertSame(
            [['reschedule_confirmation', 'robin@example.com'], ['staff_notification', 'desk@example.test']],
            $this->delivered($byStaff['new_booking_id'])
        );

        $original = $this->create($this->business->owner(), '13:00');
        $byCustomer = BookingCommands::reschedule($this->verifiedCustomer($original), [
            'business_id' => $this->business->id,
            'booking_id' => $original,
            'target' => ['new_date' => TimeslotBusiness::day(5), 'new_time' => '14:00'],
        ]);
        $this->assertSame(
            [['reschedule_confirmation', 'robin@example.com'], ['staff_notification', 'desk@example.test']],
            $this->delivered($byCustomer['new_booking_id'])
        );
    }

    public function testApprovalNotifiesOnlyTheCustomer(): void
    {
        $pending = $this->business->occupy(null, TimeslotBusiness::day(4) . ' 10:00:00', TimeslotBusiness::day(4) . ' 10:30:00', 'pending', 'robin@example.com');

        BookingCommands::changeStatus($this->business->owner(), ['business_id' => $this->business->id, 'booking_id' => $pending, 'status' => 'confirmed']);

        $this->assertSame([['approval_confirmed', 'robin@example.com']], $this->delivered($pending));
    }

    // ── Helpers ──

    private function create(ActorContext $actor, string $time = '10:00'): string
    {
        return BookingCommands::create($actor, [
            'business_id' => $this->business->id,
            'service_id' => $this->service,
            'start_datetime' => TimeslotBusiness::day(4) . 'T' . $time . ':00',
            'customer' => ['name' => 'Robin Example', 'email' => 'robin@example.com'],
        ])['id'];
    }

    /** The customer who opened the booking's own manage link. */
    private function verifiedCustomer(string $bookingId): ActorContext
    {
        $secret = CustomerCapability::issueManage($this->business->id, $bookingId);
        $grant = CustomerCapability::verify($secret, CustomerCapability::MANAGE, $this->business->id, $bookingId);

        return CustomerCapability::actorById($grant['id']);
    }

    /**
     * Run delivery and list the email types and recipients sent for one booking, in send order.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function delivered(string $bookingId): array
    {
        Outbox::dispatch(100);
        $path = TestEnvironment::manifest()['run_root'] . '/mail.jsonl';
        $sent = [];
        foreach (is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
            $message = json_decode($line, true);
            if (($message['booking_id'] ?? null) === $bookingId && ($message['error'] ?? null) === null) {
                $sent[] = [$message['type'], $message['to']];
            }
        }

        return $sent;
    }
}
