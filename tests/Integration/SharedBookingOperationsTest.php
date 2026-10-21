<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Agent\ActorContext;
use App\Application\Bookings\BookingCommands;
use App\Engine\Database;
use PHPUnit\Framework\TestCase;

final class SharedBookingOperationsTest extends TestCase
{
    protected function setUp(): void
    {
        \Tests\Support\TestEnvironment::boot();
        TestFixtures::provision();\Tests\Support\CustomerFixtureState::restore();
    }

    private function staff(): ActorContext
    {
        return new ActorContext('business_user', TestFixtures::BUSINESS_USER_ID, 'business_owner', [TestFixtures::BUSINESS_TENANT_ID], ['bookings:read', 'bookings:write']);
    }

    public function testCancellationJoinsTransactionAndDoesNotDoubleDecrement(): void
    {
        Database::execute("UPDATE bookings SET status = 'confirmed', start_datetime = DATE_ADD(NOW(), INTERVAL 3 DAY) WHERE id = ?", [TestFixtures::BOOKING_ID]);
        Database::execute('UPDATE customers SET booking_count = 2 WHERE id = ?', [TestFixtures::CUSTOMER_ID]);
        Database::pdo()->beginTransaction();
        try {
            $input = ['business_id' => TestFixtures::BUSINESS_TENANT_ID, 'booking_id' => TestFixtures::BOOKING_ID];
            $first = BookingCommands::cancel($this->staff(), $input);
            self::assertTrue(Database::pdo()->inTransaction());
            self::assertSame('cancelled', $first['status']);
            BookingCommands::cancel($this->staff(), $input);
            self::assertSame(1, (int) Database::query('SELECT booking_count FROM customers WHERE id = ?', [TestFixtures::CUSTOMER_ID])[0]['booking_count']);
        } finally {
            Database::pdo()->rollBack();
        }
        self::assertSame('confirmed', Database::query('SELECT status FROM bookings WHERE id = ?', [TestFixtures::BOOKING_ID])[0]['status']);
    }

    public function testVerifiedBookingActorCannotCancelAnotherBooking(): void
    {
        $actor = new ActorContext('customer', TestFixtures::CUSTOMER_ID, 'verified_customer', [TestFixtures::BUSINESS_TENANT_ID], ['bookings:write'], bookingId: 'another-booking', customerId: TestFixtures::CUSTOMER_ID);
        $this->expectException(\App\Agent\OperationException::class);
        BookingCommands::cancel($actor, ['business_id' => TestFixtures::BUSINESS_TENANT_ID, 'booking_id' => TestFixtures::BOOKING_ID]);
    }

    public function testFourPatternsShareCreationSnapshotsAndConsentRules(): void
    {
        $tenant = TestFixtures::BUSINESS_TENANT_ID;
        $date = (new \DateTimeImmutable('+5 days', new \DateTimeZone('UTC')))->format('Y-m-d');
        $day = (int) (new \DateTimeImmutable($date))->format('N') - 1;
        Database::execute("UPDATE tenants SET requires_consent = 1, min_advance_hours = 0, max_advance_days = 365, booking_requires_approval = 0, max_bookings_per_customer_per_day = 10 WHERE id = ?", [$tenant]);
        Database::execute("INSERT INTO services (id,tenant_id,name,duration_minutes,requires_staff,price) VALUES ('shared-service',?,'Consultation',30,0,25.00)", [$tenant]);
        Database::execute("INSERT INTO availability (id,tenant_id,day_of_week,start_time,end_time) VALUES ('shared-hours',?,?,'08:00','18:00')", [$tenant,$day]);
        Database::execute("INSERT INTO resources (id,tenant_id,name,capacity,price_per_night) VALUES ('shared-resource',?,'Room',4,50.00)", [$tenant]);
        Database::execute("INSERT INTO capacity_slots (id,tenant_id,day_of_week,start_time,end_time,max_capacity) VALUES ('shared-capacity',?,?,'18:00','20:00',8)", [$tenant,$day]);
        Database::execute("INSERT INTO events (id,tenant_id,name,start_datetime,end_datetime,max_participants) VALUES ('shared-event',?,'Workshop',?, ?,4)", [$tenant,$date.' 20:00:00',$date.' 21:00:00']);
        $inputs = [
            'timeslot' => ['service_id'=>'shared-service','date'=>$date,'time'=>'09:00'],
            'resource' => ['resource_id'=>'shared-resource','check_in'=>$date,'check_out'=>(new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d'),'guest_count'=>2],
            'capacity' => ['slot_id'=>'shared-capacity','date'=>$date,'party_size'=>2],
            'event' => ['event_id'=>'shared-event','date'=>$date,'spot_count'=>2],
        ];
        $actor = new ActorContext('public',\App\Engine\Ulid::generate(), 'prospective_customer',[$tenant],['public:read','public:book'],authMode:'public');
        foreach ($inputs as $pattern => $input) {
            Database::execute('UPDATE tenants SET booking_pattern = ? WHERE id = ?',[$pattern,$tenant]);
            $availability=\App\Application\Availability\AvailabilityService::search($actor,$input+['business_id'=>$tenant]);
            foreach(\App\Application\Availability\AvailabilityOperations::definitions() as $operation)if($operation->operationId==='availability_search')(new \App\Agent\SchemaValidator())->validate($operation->outputSchema,$availability,true);
            $receipt = BookingCommands::create($actor, $input + ['business_id'=>$tenant,'customer'=>['name'=>'Submitted Name','email'=>'customer@example.com','phone'=>''],'consent_given'=>true]);
            self::assertSame($pattern, $receipt['booking_pattern']);
            self::assertSame('confirmed', $receipt['status']);
            self::assertStringContainsString('#access=', $receipt['manage_url']);
            $stored = Database::query('SELECT * FROM bookings WHERE id = ?',[$receipt['id']])[0];
            self::assertSame('Submitted Name',$stored['customer_name_snapshot']);
            self::assertSame($receipt['total']??null,$stored['price_total']);
            self::assertSame('EUR',$stored['price_currency']);
            self::assertNotNull($stored['consent_given_at']);
            self::assertSame('web',$stored['source']);
            (new \App\Agent\SchemaValidator())->validate(\App\Application\Bookings\BookingOperations::creationSchema(),$receipt,true);
            self::assertTrue(\App\Application\Bookings\BookingNotifications::deliver(['tenant_id'=>$tenant,'booking_id'=>$receipt['id'],'kind'=>'created','audience'=>'customer'])['sent']);
        }
        self::assertSame('Test Customer',Database::query('SELECT name FROM customers WHERE id = ?',[TestFixtures::CUSTOMER_ID])[0]['name']);
    }

    public function testEventPromotionRechecksInventoryAndPreservesWaitlistedState(): void
    {
        $tenant = TestFixtures::BUSINESS_TENANT_ID;
        $date = (new \DateTimeImmutable('+8 days'))->format('Y-m-d');
        Database::execute("UPDATE tenants SET booking_pattern = 'event' WHERE id = ?", [$tenant]);
        Database::execute("INSERT INTO events (id,tenant_id,name,start_datetime,end_datetime,max_participants) VALUES ('promotion-event',?,'Full event',?, ?,2)", [$tenant,$date.' 09:00:00',$date.' 10:00:00']);
        foreach (['promotion-full' => ['confirmed',2], 'promotion-wait' => ['waitlisted',1]] as $id => [$status,$size]) {
            Database::execute('INSERT INTO bookings (id,tenant_id,booking_pattern,event_id,customer_id,start_datetime,end_datetime,status,party_size) VALUES (?,?,?,?,?,?,?,?,?)', [$id,$tenant,'event','promotion-event',TestFixtures::CUSTOMER_ID,$date.' 09:00:00',$date.' 10:00:00',$status,$size]);
        }
        try {
            BookingCommands::changeStatus($this->staff(), ['business_id'=>$tenant,'booking_id'=>'promotion-wait','status'=>'confirmed']);
            self::fail('Full event must not permit promotion.');
        } catch (\App\Agent\OperationException $error) {
            self::assertSame('inventory_conflict', $error->problemCode);
        }
        self::assertSame('waitlisted',Database::query("SELECT status FROM bookings WHERE id = 'promotion-wait'")[0]['status']);
    }

    public function testEveryPatternReceiptAndListingMatchDeclaredContracts(): void
    {
        $definitions=\App\Application\Bookings\BookingOperations::definitions();
        $validator=new \App\Agent\SchemaValidator();
        $list=\App\Application\Bookings\BookingQueries::listing($this->staff(),['business_id'=>TestFixtures::BUSINESS_TENANT_ID]);
        foreach($definitions as $definition) if($definition->operationId==='bookings_list') $validator->validate($definition->outputSchema,$list,true);
        self::assertArrayNotHasKey('manage_url',$list['bookings'][0]);
        self::assertArrayNotHasKey('token_hash',$list['bookings'][0]);
        self::assertGreaterThan(0,$list['total']);
    }

    public function testRequiredCustomAnswersAreValidatedBeforeCreatingCustomer(): void
    {
        Database::execute('UPDATE tenants SET custom_fields = ? WHERE id = ?',[json_encode([['name'=>'size','type'=>'select','required'=>true,'options'=>['small','large']]]),TestFixtures::BUSINESS_TENANT_ID]);
        $tenant=Database::query('SELECT * FROM tenants WHERE id = ?',[TestFixtures::BUSINESS_TENANT_ID])[0];
        foreach([[],['size'=>'invented'],['size'=>'small','unknown'=>'value']] as $answers) {
            try { \App\Application\Bookings\BookingFields::answers($tenant,$answers); self::fail('Invalid answers accepted'); }
            catch(\App\Agent\OperationException $error) { self::assertSame('validation_failed',$error->problemCode); }
        }
        self::assertSame(['size'=>'large'],\App\Application\Bookings\BookingFields::answers($tenant,['size'=>'large']));
    }

    public function testRescheduleRetainsOriginalOnConflictAndMovesOnlyExecutingGrant(): void
    {
        $tenantId=TestFixtures::BUSINESS_TENANT_ID;
        $date=(new \DateTimeImmutable('+10 days'))->format('Y-m-d'); $day=(int)(new \DateTimeImmutable($date))->format('N')-1;
        Database::execute("UPDATE tenants SET booking_pattern='capacity',min_advance_hours=0,max_advance_days=365,rescheduling_hours_before=0,allow_rescheduling=1 WHERE id=?",[$tenantId]);
        Database::execute("INSERT INTO capacity_slots(id,tenant_id,day_of_week,start_time,end_time,max_capacity) VALUES('move-slot',?,?,'10:00','11:00',2)",[$tenantId,$day]);
        Database::execute("UPDATE bookings SET booking_pattern='capacity',start_datetime=?,end_datetime=?,status='confirmed',party_size=1 WHERE id=?",[(new \DateTimeImmutable('+3 days'))->format('Y-m-d').' 09:00:00',(new \DateTimeImmutable('+3 days'))->format('Y-m-d').' 10:00:00',TestFixtures::BOOKING_ID]);
        $secret=\App\Engine\CustomerCapability::issueManage($tenantId,TestFixtures::BOOKING_ID);
        $grant=\App\Engine\CustomerCapability::verify($secret,\App\Engine\CustomerCapability::MANAGE,$tenantId,TestFixtures::BOOKING_ID);
        $actor=\App\Engine\CustomerCapability::actorById($grant['id']);
        $result=BookingCommands::reschedule($actor,['business_id'=>$tenantId,'booking_id'=>TestFixtures::BOOKING_ID,'target'=>['date'=>$date,'slot_id'=>'move-slot']]);
        self::assertSame('rescheduled',Database::query('SELECT status FROM bookings WHERE id=?',[TestFixtures::BOOKING_ID])[0]['status']);
        self::assertSame($result['new_booking_id'],\App\Engine\CustomerCapability::actorById($grant['id'])->bookingId);
        self::assertNull(\App\Engine\CustomerCapability::verify($secret,\App\Engine\CustomerCapability::MANAGE,$tenantId,TestFixtures::BOOKING_ID));
        self::assertNotNull(\App\Engine\CustomerCapability::verify($secret,\App\Engine\CustomerCapability::MANAGE,$tenantId,$result['new_booking_id']));
        self::assertSame('move-slot',Database::query('SELECT capacity_slot_id FROM bookings WHERE id=?',[$result['new_booking_id']])[0]['capacity_slot_id']);
    }

    public function testUnverifiedBookingCannotOverwriteAnExistingCustomerProfile(): void
    {
        $id = \App\Engine\CustomerService::findOrCreate(TestFixtures::BUSINESS_TENANT_ID, 'Imposter', 'customer@example.com', '+123');
        self::assertSame(TestFixtures::CUSTOMER_ID, $id);
        self::assertSame('Test Customer', Database::query('SELECT name FROM customers WHERE id = ?', [$id])[0]['name']);
    }
    public function testNativeCustomerRescheduleRetryReturnsSameReceiptAfterGrantMoves():void
    {
        $tenant=TestFixtures::BUSINESS_TENANT_ID;$date=(new \DateTimeImmutable('+18 days'))->format('Y-m-d');$day=(int)(new \DateTimeImmutable($date))->format('N')-1;
        Database::execute("UPDATE tenants SET booking_pattern='capacity',rescheduling_hours_before=0 WHERE id=?",[$tenant]);
        Database::execute("UPDATE bookings SET booking_pattern='capacity',party_size=1 WHERE id=?",[TestFixtures::BOOKING_ID]);
        Database::execute("INSERT INTO capacity_slots(id,tenant_id,day_of_week,start_time,end_time,max_capacity) VALUES('native-move-slot',?,?,'16:00','17:00',2)",[$tenant,$day]);
        $secret=\App\Engine\CustomerCapability::issueManage($tenant,TestFixtures::BOOKING_ID);
        $grant=\App\Engine\CustomerCapability::verify($secret,\App\Engine\CustomerCapability::MANAGE,$tenant,TestFixtures::BOOKING_ID);
        $source=\App\Engine\CustomerCapability::actorById($grant['id']);$registry=\App\Agent\Operations::registry();$connections=new \App\Agent\ConnectionService($registry);
        $connection=$connections->createCustomerGrant($source,'Rescheduling test',['bookings:read','bookings:reschedule'],gmdate('Y-m-d H:i:s',time()+1800),'booking.manage');
        $credential=$connections->issueCredential($connection['id'],'https://example.com/mcp',$connection['expires_at']);
        $actor=(new \App\Agent\ConnectionAuthenticator())->authenticate($credential['raw_token'],'https://example.com/mcp');
        $executor=new \App\Agent\Executor($registry);
        $preview=$executor->preview($actor,'customer_booking_reschedule',['business_id'=>$tenant,'target'=>['date'=>$date,'slot_id'=>'native-move-slot']]);
        // Simulate the already-verified browser approval. Production tools cannot set this field.
        Database::execute("UPDATE agent_previews SET approved_at=UTC_TIMESTAMP(),approval_source='customer_browser' WHERE handle_hash=?",[hash('sha256',$preview['preview_handle'])]);
        $args=['preview_handle'=>$preview['preview_handle']];$first=$executor->execute($actor,'customer_booking_reschedule',$args,'native-move-retry-001');
        $again=$executor->execute($actor,'customer_booking_reschedule',$args,'native-move-retry-001');
        self::assertSame($first,$again);self::assertNotNull($first['receipt_id']);
        self::assertSame($first['data']['id'],(new \App\Agent\ConnectionAuthenticator())->refresh($actor)->bookingId);
        self::assertSame($first['data']['id'],Database::query('SELECT rescheduled_to_id FROM bookings WHERE id=?',[TestFixtures::BOOKING_ID])[0]['rescheduled_to_id']);
        try {$executor->execute($actor,'customer_booking_reschedule',$args,'native-move-retry-002');self::fail('Consumed preview was reused');}
        catch(\App\Agent\OperationException $error){self::assertContains($error->problemCode,['preview_consumed','preview_changed']);}
    }

}
