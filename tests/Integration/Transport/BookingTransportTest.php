<?php

declare(strict_types=1);

namespace Tests\Integration\Transport;

use App\Agent\{ActorContext, PreviewStore};
use App\Engine\{CustomerCapability, Database, Ulid};
use Tests\Support\ReviewLink;
use Tests\Support\Transport\TransportTestCase;

/**
 * Staff and customer booking and privacy commands over HTTP v2 and native MCP.
 *
 * Staff commands are approved by the signed-in operator; customer commands by the
 * customer's own review link. Each transport acts on its own booking and customer.
 */
final class BookingTransportTest extends TransportTestCase
{
    private const STAFF_OPERATIONS = ['booking_create', 'booking_get', 'booking_reschedule', 'booking_status_set', 'deletion_confirm', 'deletion_dismiss', 'deletion_review'];
    private const SLOTS = ['http' => '09:00', 'mcp' => '10:00'];
    private const MOVES = ['http' => '11:00', 'mcp' => '12:00'];

    private string $business;
    private string $service;
    private string $date;
    private string $move;
    private ActorContext $session;
    private array $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = $this->tenant('timeslot', [
            'timezone' => 'Europe/Amsterdam', 'min_advance_hours' => 0, 'max_advance_days' => 365, 'requires_consent' => 0,
            'allow_cancellation' => 1, 'cancellation_hours_before' => 0, 'allow_rescheduling' => 1, 'rescheduling_hours_before' => 0,
        ])['id'];
        $this->date = (new \DateTimeImmutable('+35 days'))->format('Y-m-d');
        $this->move = (new \DateTimeImmutable('+42 days'))->format('Y-m-d');
        $this->service = Ulid::generate();
        Database::execute("INSERT INTO services (id,tenant_id,name,duration_minutes,requires_staff,price) VALUES (?,?,'Consultation',30,0,25.50)", [$this->service, $this->business]);
        // The move date is exactly a week later, so one weekday window covers both dates.
        Database::execute("INSERT INTO availability (id,tenant_id,day_of_week,start_time,end_time) VALUES (?,?,?,'08:00','18:00')",
            [Ulid::generate(), $this->business, (int) (new \DateTimeImmutable($this->date))->format('N') - 1]);
        $this->session = $this->operatorSession([$this->business]);
        $this->staff = $this->connect($this->session, self::STAFF_OPERATIONS);
    }

    private function bookingInput(string $transport): array
    {
        return ['business_id' => $this->business, 'service_id' => $this->service, 'date' => $this->date, 'time' => self::SLOTS[$transport],
            'customer' => ['name' => 'Guest ' . $transport, 'email' => 'guest-' . $transport . '-' . strtolower(Ulid::generate()) . '@example.test']];
    }

    /** Setup only: a booking the operator made in the admin. */
    private function seedBooking(string $transport): array
    {
        $data = $this->seedCommand($this->session, 'booking_create', $this->bookingInput($transport))['data'];
        return $data + ['customer_id' => $this->booking($data['id'])['customer_id']];
    }

    private function customerEmail(string $customerId): string
    {
        return Database::query('SELECT email FROM customers WHERE id=?', [$customerId])[0]['email'];
    }

    private function booking(string $id): array
    {
        return Database::query('SELECT id, status, customer_id, rescheduled_to_id, start_datetime FROM bookings WHERE id=?', [$id])[0];
    }

    /** Setup only: a customer command approved through the customer's review link, in process. */
    private function seedCustomerCommand(array $customer, string $operation, array $input): array
    {
        $preview = $this->executor->preview($customer['actor'], $operation, $input);
        $store = new PreviewStore();
        $store->exchangeBrowserHandle(ReviewLink::id($preview), ReviewLink::secret($preview));
        $store->approveCustomerBrowser(ReviewLink::id($preview), true);
        return $this->executor->execute($customer['actor'], $operation, ['preview_handle' => $preview['preview_handle']], 'seed-' . Ulid::generate());
    }

    /** A customer who has asked to be erased, waiting in the business's deletion queue. */
    private function requestedDeletion(string $transport): string
    {
        $customerId = $this->seedBooking($transport)['customer_id'];
        $privacy = $this->connectCustomer($this->business, $customerId, CustomerCapability::PRIVACY);
        $this->seedCustomerCommand($privacy, 'customer_deletion_request', ['business_id' => $this->business]);
        self::assertNotNull(Database::query('SELECT deletion_requested_at FROM customers WHERE id=?', [$customerId])[0]['deletion_requested_at']);
        return $customerId;
    }

    public function testBookingCreateOverHttpAndMcp(): void
    {
        foreach (self::TRANSPORTS as $transport) {
            $input = $this->bookingInput($transport);
            $data = $this->call($transport, $this->staff, 'booking_create', $input)['data'];
            self::assertSame('confirmed', $data['status']);
            self::assertSame([$this->date, self::SLOTS[$transport]], [$data['date'], $data['time']]);
            $stored = $this->booking($data['id']);
            self::assertSame(['confirmed', $this->date . ' ' . self::SLOTS[$transport] . ':00'], [$stored['status'], $stored['start_datetime']], $transport);
            self::assertSame($input['customer']['email'], $this->customerEmail($stored['customer_id']));
        }
    }

    public function testBookingRescheduleOverHttpAndMcp(): void
    {
        foreach (self::TRANSPORTS as $transport) {
            $original = $this->seedBooking($transport);
            $data = $this->call($transport, $this->staff, 'booking_reschedule', ['business_id' => $this->business, 'booking_id' => $original['id'],
                'target' => ['new_date' => $this->move, 'new_time' => self::MOVES[$transport]]])['data'];
            self::assertNotSame($original['id'], $data['id']);
            self::assertSame($this->move . ' ' . self::MOVES[$transport] . ':00', $data['start_datetime']);
            self::assertSame($data['id'], $this->booking($original['id'])['rescheduled_to_id'], $transport);
            self::assertSame('confirmed', $this->booking($data['id'])['status']);
        }
    }

    public function testBookingStatusSetOverHttpAndMcp(): void
    {
        foreach (self::TRANSPORTS as $transport) {
            $booking = $this->seedBooking($transport);
            $data = $this->call($transport, $this->staff, 'booking_status_set', ['business_id' => $this->business, 'booking_id' => $booking['id'], 'status' => 'cancelled'])['data'];
            self::assertSame('cancelled', $data['status']);
            self::assertSame('cancelled', $this->booking($booking['id'])['status'], $transport);
        }
    }

    public function testCustomerBookingRescheduleOverHttpAndMcp(): void
    {
        foreach (self::TRANSPORTS as $transport) {
            $original = $this->seedBooking($transport);
            $customer = $this->connectCustomer($this->business, $original['id'], CustomerCapability::MANAGE);
            $data = $this->call($transport, $customer, 'customer_booking_reschedule', ['business_id' => $this->business,
                'target' => ['new_date' => $this->move, 'new_time' => self::MOVES[$transport]]])['data'];
            self::assertSame($this->move . ' ' . self::MOVES[$transport] . ':00', $data['start_datetime']);
            self::assertSame($data['id'], $this->booking($original['id'])['rescheduled_to_id'], $transport);
        }
    }

    public function testCustomerBookingCancelOverHttpAndMcp(): void
    {
        foreach (self::TRANSPORTS as $transport) {
            $booking = $this->seedBooking($transport);
            $customer = $this->connectCustomer($this->business, $booking['id'], CustomerCapability::MANAGE);
            $data = $this->call($transport, $customer, 'customer_booking_cancel', ['business_id' => $this->business, 'reason' => 'Plans changed (' . $transport . ')'])['data'];
            self::assertSame($booking['id'], $data['id']);
            self::assertSame('cancelled', $data['status']);
            self::assertSame('cancelled', $this->booking($booking['id'])['status'], $transport);
        }
    }

    public function testCustomerPrivacyExportOverHttpAndMcp(): void
    {
        foreach (self::TRANSPORTS as $transport) {
            $booking = $this->seedBooking($transport);
            $privacy = $this->connectCustomer($this->business, $booking['customer_id'], CustomerCapability::PRIVACY);
            $data = $this->call($transport, $privacy, 'customer_privacy_export', ['business_id' => $this->business])['data'];
            self::assertSame($this->customerEmail($booking['customer_id']), $data['customer']['email'], $transport);
            // The export carries the customer's own booking without internal identifiers.
            self::assertSame([$this->booking($booking['id'])['start_datetime']], array_column($data['bookings'], 'start_datetime'));
        }
    }

    public function testCustomerDeletionRequestOverHttpAndMcp(): void
    {
        foreach (self::TRANSPORTS as $transport) {
            $customerId = $this->seedBooking($transport)['customer_id'];
            $privacy = $this->connectCustomer($this->business, $customerId, CustomerCapability::PRIVACY);
            $data = $this->call($transport, $privacy, 'customer_deletion_request', ['business_id' => $this->business])['data'];
            self::assertSame('pending_review', $data['deletion_status'], $transport);
            self::assertNotNull($data['deletion_requested_at']);
            self::assertNotNull(Database::query('SELECT deletion_requested_at FROM customers WHERE id=?', [$customerId])[0]['deletion_requested_at']);
        }
    }

    public function testDeletionDismissOverHttpAndMcp(): void
    {
        foreach (self::TRANSPORTS as $transport) {
            $customerId = $this->requestedDeletion($transport);
            $data = $this->call($transport, $this->staff, 'deletion_dismiss', ['business_id' => $this->business, 'customer_id' => $customerId, 'reason' => 'Customer kept their account (' . $transport . ')'])['data'];
            self::assertSame(['customer_id' => $customerId, 'dismissed' => true], $data);
            $stored = Database::query('SELECT deletion_requested_at, is_anonymized FROM customers WHERE id=?', [$customerId])[0];
            self::assertNull($stored['deletion_requested_at'], $transport);
            self::assertSame(0, (int) $stored['is_anonymized']);
        }
    }

    public function testDeletionConfirmOverHttpAndMcp(): void
    {
        foreach (self::TRANSPORTS as $transport) {
            $customerId = $this->requestedDeletion($transport);
            $data = $this->call($transport, $this->staff, 'deletion_confirm', ['business_id' => $this->business, 'customer_id' => $customerId])['data'];
            self::assertSame($customerId, $data['customer_id']);
            self::assertTrue($data['anonymized']);
            self::assertSame(1, $data['bookings_affected']);
            self::assertSame(1, (int) Database::query('SELECT is_anonymized FROM customers WHERE id=?', [$customerId])[0]['is_anonymized'], $transport);
        }
    }
}
