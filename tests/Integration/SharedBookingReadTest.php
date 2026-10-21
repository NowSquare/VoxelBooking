<?php

declare(strict_types=1);
namespace Tests\Integration;
use App\Agent\{ActorContext,OperationException,SchemaValidator};
use App\Application\Availability\{AvailabilityService,AvailabilityOperations};
use App\Application\Bookings\{BookingQueries,BookingOperations};
use App\Engine\{Database,Ulid};
use PHPUnit\Framework\TestCase;
final class SharedBookingReadTest extends TestCase
{
    protected function setUp():void {\Tests\Support\TestEnvironment::boot();TestFixtures::provision();\Tests\Support\CustomerFixtureState::restore();Database::pdo()->beginTransaction();}
    protected function tearDown():void {if(Database::pdo()->inTransaction())Database::pdo()->rollBack();}
    private function publicActor():ActorContext {return new ActorContext('anonymous',bin2hex(random_bytes(8)),'prospective_customer',[TestFixtures::BUSINESS_TENANT_ID],['public:read'],authMode:'public');}
    public function testEventDateMonthAndCursorRemainBoundToTheRequestedOccurrences():void
    {
        $tenant=TestFixtures::BUSINESS_TENANT_ID;$date=(new \DateTimeImmutable('first day of next month'))->format('Y-m-d');
        Database::execute("UPDATE tenants SET booking_pattern='event' WHERE id=?",[$tenant]);
        $id=Ulid::generate();Database::execute("INSERT INTO events(id,tenant_id,name,start_datetime,end_datetime,max_participants,is_recurring,rrule) VALUES(?,?,'Recurring',?,?,10,1,'FREQ=DAILY;COUNT=40')",[$id,$tenant,$date.' 10:00:00',$date.' 11:00:00']);
        $first=AvailabilityService::search($this->publicActor(),['event_id'=>$id,'month'=>substr($date,0,7),'limit'=>2]);
        self::assertCount(2,$first['choices']);self::assertSame($date,$first['choices'][0]['date']);self::assertNotNull($first['next_cursor']);
        $second=AvailabilityService::search($this->publicActor(),['event_id'=>$id,'month'=>substr($date,0,7),'limit'=>2,'cursor'=>$first['next_cursor']]);
        self::assertSame((new \DateTimeImmutable($date))->modify('+2 days')->format('Y-m-d'),$second['choices'][0]['date']);
        $exact=AvailabilityService::search($this->publicActor(),['date'=>$date]);
        foreach($exact['choices'] as $choice)self::assertSame($date,$choice['date']);
        self::assertContains($id,array_column($exact['choices'],'id'));
        foreach(AvailabilityOperations::definitions() as $operation)if($operation->operationId==='availability_search')(new SchemaValidator())->validate($operation->outputSchema,$second,true);
        $this->expectException(OperationException::class);AvailabilityService::search($this->publicActor(),['date'=>$date,'cursor'=>$first['next_cursor']]);
    }
    public function testPublicCatalogCursorSupportsNewAnonymousRequestsAndRejectsChangedKind():void
    {
        $tenant=TestFixtures::BUSINESS_TENANT_ID;
        for($i=0;$i<3;$i++)Database::execute('INSERT INTO services(id,tenant_id,name,duration_minutes) VALUES(?,?,?,30)',[Ulid::generate(),$tenant,'Cursor service '.$i]);
        $first=AvailabilityService::catalog($this->publicActor(),['kind'=>'services','limit'=>1]);
        $next=AvailabilityService::catalog($this->publicActor(),['kind'=>'services','limit'=>1,'cursor'=>$first['next_cursor']]);
        self::assertNotSame($first['items'][0]['id'],$next['items'][0]['id']);
        $this->expectException(OperationException::class);AvailabilityService::catalog($this->publicActor(),['kind'=>'staff','cursor'=>$first['next_cursor']]);
    }
    public function testCalendarPagesPreserveNamesClosuresAndAllRecords():void
    {
        $tenant=TestFixtures::BUSINESS_TENANT_ID;$date='2030-01-07';$service=Ulid::generate();
        Database::execute("INSERT INTO services(id,tenant_id,name,duration_minutes,color) VALUES(?,?,'Calendar service',30,'#123456')",[$service,$tenant]);
        Database::execute('DELETE FROM availability WHERE tenant_id=?',[$tenant]);
        Database::execute("INSERT INTO availability(id,tenant_id,day_of_week,start_time,end_time,is_available) VALUES(?,?,0,'09:00','18:00',0)",[Ulid::generate(),$tenant]);
        for($i=0;$i<105;$i++)Database::execute("INSERT INTO bookings(id,tenant_id,customer_id,booking_pattern,service_id,start_datetime,end_datetime,status,source,customer_name_snapshot) VALUES(?,?,?,'timeslot',?,? ,?,'confirmed','admin','Recorded customer')",[Ulid::generate(),$tenant,TestFixtures::CUSTOMER_ID,$service,$date.' 10:00:00',$date.' 10:30:00']);
        $actor=new ActorContext('business_user',TestFixtures::BUSINESS_USER_ID,'business_owner',[$tenant],['bookings:read']);
        $input=['from'=>$date,'to'=>$date,'limit'=>100];$first=BookingQueries::calendar($actor,$input);
        self::assertSame(105,$first['total']);self::assertCount(100,$first['bookings']);self::assertSame([],$first['available_weekdays']);self::assertSame('Recorded customer',$first['bookings'][0]['customer']['name']);self::assertSame('#123456',$first['bookings'][0]['service_color']);
        $second=BookingQueries::calendar($actor,$input+['cursor'=>$first['next_cursor']]);self::assertCount(5,$second['bookings']);self::assertNull($second['next_cursor']);
        foreach(BookingOperations::definitions() as $operation)if($operation->operationId==='calendar_get')(new SchemaValidator())->validate($operation->outputSchema,$first,true);
    }
    public function testOperatorReadAndCsvShareGrantedBusinessFiltersAndStableSort():void
    {
        $tenant=TestFixtures::BUSINESS_TENANT_ID;$foreign=Ulid::generate();
        Database::execute("INSERT INTO tenants(id,name,slug,email,booking_pattern,timezone,currency,status) VALUES(?,'Unrelated business',?,'other@example.test','timeslot','UTC','EUR','active')",[$foreign,'other-'.strtolower($foreign)]);
        Database::execute("UPDATE bookings SET customer_name_snapshot='=DANGEROUS()',customer_email_snapshot='customer@example.com' WHERE id=?",[TestFixtures::BOOKING_ID]);
        $actor=new ActorContext('operator',TestFixtures::OPERATOR_ID,'operator',[$tenant],['bookings:read','bookings:export']);
        $input=['sort'=>'customer','direction'=>'asc','search'=>TestFixtures::BOOKING_ID];
        $native=BookingQueries::operatorListing($actor,$input);$browser=BookingQueries::browserPage($actor,$input,true);
        self::assertSame(array_column($native['bookings'],'id'),array_column($browser['bookings'],'id'));self::assertSame([$tenant],array_unique(array_column($native['bookings'],'business_id')));
        $csv=BookingQueries::export($actor,$input,true);self::assertStringContainsString("'=DANGEROUS()",$csv['content']);self::assertStringNotContainsString('Unrelated business',$csv['content']);
        foreach(BookingOperations::definitions() as $operation){if($operation->operationId==='operator_bookings_list')(new SchemaValidator())->validate($operation->outputSchema,$native,true);if($operation->operationId==='operator_bookings_export')(new SchemaValidator())->validate($operation->outputSchema,$csv,true);}
        $customer=new ActorContext('customer',TestFixtures::CUSTOMER_ID,'verified_customer',[$tenant],['bookings:read']);
        $this->expectException(OperationException::class);BookingQueries::operatorListing($customer,[]);
    }
    public function testCalendarShowsOccupiedLegacyRowsAndStaysOverlappingTheWindow():void
    {
        $tenant=TestFixtures::BUSINESS_TENANT_ID;$ids=[];
        foreach(['legacy'=>['rescheduled',null],'replaced'=>['rescheduled',TestFixtures::BOOKING_ID],'stay'=>['confirmed',null]] as $label=>[$status,$successor]){
            $id=Ulid::generate();$ids[$label]=$id;
            Database::execute("INSERT INTO bookings(id,tenant_id,customer_id,booking_pattern,start_datetime,end_datetime,status,rescheduled_to_id) VALUES(?,?,?,'resource','2030-01-05 15:00','2030-01-09 11:00',?,?)",[$id,$tenant,TestFixtures::CUSTOMER_ID,$status,$successor]);
        }
        $actor=new ActorContext('business_user',TestFixtures::BUSINESS_USER_ID,'business_owner',[$tenant],['bookings:read']);
        $calendar=BookingQueries::calendar($actor,['from'=>'2030-01-07','to'=>'2030-01-07']);
        self::assertContains($ids['legacy'],array_column($calendar['bookings'],'id'));
        self::assertContains($ids['stay'],array_column($calendar['bookings'],'id'));
        self::assertNotContains($ids['replaced'],array_column($calendar['bookings'],'id'));
    }

}
