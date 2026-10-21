<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Agent\{OperationException, PayloadCipher, PreviewStore, PrincipalResolver, PublicContinuation, SchemaValidator};
use App\Engine\{Database, Ulid};
use PHPUnit\Framework\TestCase;
use Tests\Support\AgentTransports;

/**
 * An event waitlist through the shared operations, without a dedicated tool.
 *
 * The admin waitlist is the bookings list filtered to waitlisted bookings of one
 * event; promotion is the status change from waitlisted to confirmed. These tests
 * prove that mapping: joining with the customer's reviewed consent, listing,
 * the full-event refusal and the customer-only promotion notice, on the Executor,
 * HTTP and MCP.
 */
final class WaitlistOperationsTest extends TestCase
{
    private AgentTransports $transports;
    private string $tenant;
    private string $event;
    private string $date;
    private string $customer;
    private string $seatHolder;

    protected function setUp(): void
    {
        \Tests\Support\TestEnvironment::boot();
        TestFixtures::provision();
        Database::execute("DELETE FROM rate_limits WHERE endpoint_group='agent'");
        Database::execute('DELETE FROM agent_rate_budgets');
        $this->transports = new AgentTransports();
        $this->tenant = Ulid::generate();
        // The business asks to hear about new bookings, so a staff notice on promotion would show up.
        Database::execute(
            "INSERT INTO tenants(id,name,slug,email,booking_pattern,timezone,currency,status,min_advance_hours,max_advance_days,requires_consent,notify_on_booking) VALUES(?,?,?,'waitlist@example.test','event','Europe/Amsterdam','EUR','active',0,365,1,1)",
            [$this->tenant, 'Waitlist workshop', 'waitlist-' . strtolower($this->tenant)]
        );
        $this->date = (new \DateTimeImmutable('+20 days'))->format('Y-m-d');
        $this->event = Ulid::generate();
        Database::execute(
            "INSERT INTO events(id,tenant_id,name,start_datetime,end_datetime,max_participants,allow_waitlist,waitlist_max) VALUES(?,?,'Pottery evening',?,?,2,1,10)",
            [$this->event, $this->tenant, $this->date . ' 16:00:00', $this->date . ' 17:00:00']
        );
        $this->customer = Ulid::generate();
        Database::execute(
            "INSERT INTO customers(id,tenant_id,name,email) VALUES(?,?,'Waiting guest','waiting@example.test')",
            [$this->customer, $this->tenant]
        );
        $this->seatHolder = $this->booking('confirmed', 2);
    }

    protected function tearDown(): void
    {
        Database::execute('UPDATE agent_connections SET revoked_at=UTC_TIMESTAMP() WHERE principal_id=? AND revoked_at IS NULL', [TestFixtures::OPERATOR_ID]);
        Database::execute('DELETE FROM tenants WHERE id=?', [$this->tenant]);
    }

    private function booking(string $status, int $party, ?string $event = null): string
    {
        $id = Ulid::generate();
        Database::execute(
            "INSERT INTO bookings(id,tenant_id,customer_id,booking_pattern,event_id,start_datetime,end_datetime,status,party_size,source,customer_name_snapshot,customer_email_snapshot) VALUES(?,?,?,'event',?,?,?,?,?,'web','Waiting guest','waiting@example.test')",
            [$id, $this->tenant, $this->customer, $event ?? $this->event, $this->date . ' 16:00:00', $this->date . ' 17:00:00', $status, $party]
        );

        return $id;
    }

    private function staff(): array
    {
        $session = (new PrincipalResolver())->resolve(
            'operator',
            TestFixtures::OPERATOR_ID,
            [$this->tenant],
            ['bookings:read', 'bookings:write'],
            authMode: 'session'
        );

        return $this->transports->connect($session, ['bookings:read', 'bookings:write']);
    }

    /** @return list<array{kind: string, audience: string}> */
    private function notices(string $bookingId): array
    {
        $notices = [];
        $cipher = new PayloadCipher();
        foreach (Database::query("SELECT payload_cipher FROM delivery_outbox WHERE topic='booking.notification' AND tenant_id=? ORDER BY created_at,id", [$this->tenant]) as $row) {
            $payload = $cipher->decrypt($row['payload_cipher']);
            if ($payload['booking_id'] === $bookingId) {
                $notices[] = ['kind' => $payload['kind'], 'audience' => $payload['audience']];
            }
        }

        return $notices;
    }

    private function bookingStatus(string $bookingId): string
    {
        return Database::query('SELECT status FROM bookings WHERE id=?', [$bookingId])[0]['status'];
    }

    public function testJoiningAFullEventWaitlistsWithTheCustomersReviewedConsent(): void
    {
        $continuation = (new PublicContinuation())->issue($this->tenant);
        $actor = (new PublicContinuation())->resolve($continuation['continuation'], $this->tenant);
        $input = [
            'business_id' => $this->tenant,
            'event_id' => $this->event,
            'date' => $this->date,
            'spot_count' => 1,
            'customer' => ['name' => 'Hopeful guest', 'email' => 'hopeful@example.test'],
        ];
        $executor = $this->transports->executor;

        // An agent cannot supply consent; only the customer's review can.
        $refused = $executor->preview($actor, 'public_booking_create', $input);
        $refusedId = basename((string) parse_url($refused['review_url'], PHP_URL_PATH));
        $store = new PreviewStore();
        $store->exchangeBrowserHandle($refusedId, \Tests\Support\ReviewLink::secret($refused));
        try {
            $store->approveCustomerBrowser($refusedId, false);
            self::fail('A waitlist entry without consent must not be approved.');
        } catch (OperationException $error) {
            self::assertSame('consent_required', $error->problemCode);
        }

        $preview = $executor->preview($actor, 'public_booking_create', $input);
        $id = basename((string) parse_url($preview['review_url'], PHP_URL_PATH));
        $store->exchangeBrowserHandle($id, \Tests\Support\ReviewLink::secret($preview));
        $store->approveCustomerBrowser($id, true);
        $result = $executor->execute($actor, 'public_booking_create', ['preview_handle' => $preview['preview_handle']], 'waitlist-' . Ulid::generate());
        (new SchemaValidator())->validate($this->transports->registry->get('public_booking_create')->resultSchema(), $result, true);

        self::assertSame('waitlisted', $result['data']['status']);
        self::assertTrue($result['data']['waitlisted']);
        self::assertTrue($result['data']['consent_recorded']);
        // A waitlist place is not a confirmed seat, so the result offers no calendar event.
        self::assertNull($result['data']['calendar']);
        $row = Database::query('SELECT status,source,consent_given_at,consent_text_shown FROM bookings WHERE id=?', [$result['data']['id']])[0];
        self::assertSame(['waitlisted', 'api'], [$row['status'], $row['source']]);
        self::assertNotNull($row['consent_given_at']);
        self::assertNotNull($row['consent_text_shown']);
        self::assertSame('confirmed', $this->bookingStatus($this->seatHolder), 'Joining a waitlist never overbooks the event.');
    }

    public function testWaitlistListsPromotesWithCustomerNoticeAndRefusesAFullEventOnEveryTransport(): void
    {
        $client = $this->staff();
        $waiting = [$this->booking('waitlisted', 1), $this->booking('waitlisted', 1), $this->booking('waitlisted', 1)];
        $otherEvent = Ulid::generate();
        Database::execute(
            "INSERT INTO events(id,tenant_id,name,start_datetime,end_datetime,max_participants,allow_waitlist,waitlist_max) VALUES(?,?,'Glaze class',?,?,1,1,10)",
            [$otherEvent, $this->tenant, $this->date . ' 16:00:00', $this->date . ' 17:00:00']
        );
        $this->booking('waitlisted', 1, $otherEvent);

        // The waitlist: this event's waitlisted bookings, oldest first.
        $list = ['business_id' => $this->tenant, 'status' => 'waitlisted', 'event_id' => $this->event, 'sort' => 'id', 'direction' => 'asc'];
        $listed = $this->transports->executor($client, 'bookings_list', $list)['data'];
        self::assertSame($waiting, array_column($listed['bookings'], 'id'));
        self::assertSame(3, $listed['total']);
        $http = $this->transports->http($client, 'bookings_list', $list);
        self::assertSame(200, $http['status'], json_encode($http['body']));
        self::assertSame($waiting, array_column($http['body']['data']['bookings'], 'id'));
        $native = $this->transports->mcp($client, 'bookings_list', $list);
        self::assertSame($waiting, array_column($native['body']['result']['structuredContent']['data']['bookings'], 'id'));

        // The event is full: promotion is refused before anything changes, on every transport.
        $promote = ['business_id' => $this->tenant, 'booking_id' => $waiting[0], 'status' => 'confirmed'];
        try {
            $this->transports->executor($client, 'booking_status_set', $promote);
            self::fail('A full event must not accept a promotion.');
        } catch (OperationException $error) {
            self::assertSame('inventory_conflict', $error->problemCode);
        }
        $refusedHttp = $this->transports->http($client, 'booking_status_set', $promote);
        self::assertSame([409, 'inventory_conflict'], [$refusedHttp['status'], $refusedHttp['body']['code'] ?? null]);
        $refusedNative = $this->transports->mcp($client, 'booking_status_set', $promote);
        self::assertTrue($refusedNative['body']['result']['isError']);
        self::assertSame('inventory_conflict', $refusedNative['body']['result']['_meta']['com.voxelbooking/problem']['code']);
        self::assertSame('waitlisted', $this->bookingStatus($waiting[0]));
        self::assertSame([], $this->notices($waiting[0]));

        // Two seats open up. Promote one through the Executor and one through HTTP.
        Database::execute("UPDATE bookings SET status='cancelled' WHERE id=?", [$this->seatHolder]);
        $promoted = $this->transports->executor($client, 'booking_status_set', $promote);
        self::assertSame('confirmed', $promoted['data']['status']);
        $second = $this->transports->http($client, 'booking_status_set', ['booking_id' => $waiting[1]] + $promote);
        self::assertSame(200, $second['status'], json_encode($second['body']));
        self::assertSame('confirmed', $second['body']['data']['status']);
        foreach ([$waiting[0], $waiting[1]] as $id) {
            self::assertSame([['kind' => 'promotion', 'audience' => 'customer']], $this->notices($id), 'Promotion emails the customer only.');
        }

        // Full again: the third waits until a seat opens, then MCP promotes it.
        $stillFull = $this->transports->mcp($client, 'booking_status_set', ['booking_id' => $waiting[2]] + $promote);
        self::assertSame('inventory_conflict', $stillFull['body']['result']['_meta']['com.voxelbooking/problem']['code']);
        Database::execute("UPDATE bookings SET status='cancelled' WHERE id=?", [$waiting[0]]);
        $third = $this->transports->mcp($client, 'booking_status_set', ['booking_id' => $waiting[2]] + $promote);
        self::assertFalse($third['body']['result']['isError'], json_encode($third['body']));
        self::assertSame('confirmed', $third['body']['result']['structuredContent']['data']['status']);
        self::assertSame([['kind' => 'promotion', 'audience' => 'customer']], $this->notices($waiting[2]));

        $remaining = $this->transports->executor($client, 'bookings_list', $list)['data'];
        self::assertSame(0, $remaining['total']);
    }
}
