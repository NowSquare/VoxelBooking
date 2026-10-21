<?php

declare(strict_types=1);
namespace Tests\Integration;
use App\Agent\{ActorContext,SchemaValidator};
use App\Application\Availability\{AvailabilityOperations,AvailabilityService};
use App\Application\Privacy\{PrivacyOperations,PrivacyService};
use App\Engine\{CustomerCapability,Database,Outbox};
use PHPUnit\Framework\TestCase;
final class CustomerApplicationOperationsTest extends TestCase
{
    protected function setUp():void {\Tests\Support\TestEnvironment::boot();TestFixtures::provision();\Tests\Support\CustomerFixtureState::restore();}
    public function testDiscoveryContractsMinimizeFieldsAndRejectForeignContext():void
    {
        $actor=new ActorContext('anonymous','interaction','prospective_customer',[TestFixtures::BUSINESS_TENANT_ID],['public:read']);
        foreach(AvailabilityOperations::definitions() as $operation){
            if(!in_array($operation->operationId,['public_business_get','public_catalog_list'],true))continue;
            $result=($operation->handler)($actor,[]);(new SchemaValidator())->validate($operation->outputSchema,$result,true);
            self::assertArrayNotHasKey('notification_email',$result);self::assertArrayNotHasKey('email',$result);
        }
        $this->expectException(\App\Agent\OperationException::class);
        AvailabilityService::business($actor,['business_id'=>'another-business']);
    }
    public function testPrivacyCommandsRequireIndependentGrantAndQueueAcknowledgmentOnce():void
    {
        $tenant=TestFixtures::BUSINESS_TENANT_ID;$secret=CustomerCapability::issuePrivacy($tenant,TestFixtures::CUSTOMER_ID);
        $grant=CustomerCapability::verify($secret,CustomerCapability::PRIVACY,$tenant,TestFixtures::CUSTOMER_ID);$actor=CustomerCapability::actorById($grant['id']);
        foreach(PrivacyOperations::definitions() as $operation){
            $result=($operation->handler)($actor,['business_id'=>$tenant]);(new SchemaValidator())->validate($operation->outputSchema,$result,true);
        }
        $before=(int)Database::query("SELECT COUNT(*) AS n FROM delivery_outbox WHERE topic='customer.privacy_notification'")[0]['n'];
        PrivacyService::requestDeletion($actor,['business_id'=>$tenant]);
        self::assertSame($before,(int)Database::query("SELECT COUNT(*) AS n FROM delivery_outbox WHERE topic='customer.privacy_notification'")[0]['n']);
        $result=Outbox::dispatch(10,['customer.privacy_notification'=>PrivacyService::deliver(...)]);
        self::assertGreaterThanOrEqual(3,$result['delivered']);
        self::assertSame('pending_review',PrivacyService::summary($actor,[])['deletion_status']);
        CustomerCapability::revokeForSubject($tenant,CustomerCapability::PRIVACY,TestFixtures::CUSTOMER_ID);
        $this->expectException(\App\Agent\OperationException::class);PrivacyService::summary($actor,[]);
    }
    public function testManageGrantCannotAccessPrivacyOperation():void
    {
        $tenant=TestFixtures::BUSINESS_TENANT_ID;$secret=CustomerCapability::issueManage($tenant,TestFixtures::BOOKING_ID);
        $grant=CustomerCapability::verify($secret,CustomerCapability::MANAGE,$tenant,TestFixtures::BOOKING_ID);$actor=CustomerCapability::actorById($grant['id']);
        $this->expectException(\App\Agent\OperationException::class);PrivacyService::export($actor,[]);
    }
    public function testPrivacyHistoryAndExportExcludeForeignBookingRelationsAndPageConsents():void
    {
        $tenant=TestFixtures::BUSINESS_TENANT_ID;$secret=CustomerCapability::issuePrivacy($tenant,TestFixtures::CUSTOMER_ID);
        $grant=CustomerCapability::verify($secret,CustomerCapability::PRIVACY,$tenant,TestFixtures::CUSTOMER_ID);$actor=CustomerCapability::actorById($grant['id']);
        Database::pdo()->beginTransaction();
        try{
            $foreign=\App\Engine\Ulid::generate();
            Database::execute("INSERT INTO tenants(id,name,slug,email,booking_pattern,timezone,currency,status) VALUES(?,'Other business',?,'other@example.test','timeslot','UTC','EUR','active')",[$foreign,'privacy-other-'.strtolower($foreign)]);
            Database::execute("INSERT INTO bookings(id,tenant_id,customer_id,booking_pattern,start_datetime,end_datetime,status,notes) VALUES(?,?,?,'timeslot','2030-01-01 10:00','2030-01-01 11:00','confirmed','Foreign secret')",[\App\Engine\Ulid::generate(),$foreign,TestFixtures::CUSTOMER_ID]);
            for($i=0;$i<3;$i++)Database::execute("INSERT INTO bookings(id,tenant_id,customer_id,booking_pattern,start_datetime,end_datetime,status,consent_given_at,consent_text_shown) VALUES(?,?,?,'timeslot','2030-01-02 10:00','2030-01-02 11:00','confirmed',UTC_TIMESTAMP(),'Recorded terms')",[\App\Engine\Ulid::generate(),$tenant,TestFixtures::CUSTOMER_ID]);
            $first=PrivacyService::records($actor,['limit'=>2]);$next=PrivacyService::records($actor,['limit'=>2,'cursor'=>$first['next_cursor']]);
            self::assertCount(2,$first['bookings']);self::assertNotNull($first['next_cursor']);self::assertSame('Recorded terms',$first['bookings'][0]['consent_text_shown']);
            self::assertSame([],array_intersect(array_column($first['bookings'],'id'),array_column($next['bookings'],'id')));
            self::assertStringNotContainsString('Foreign secret',json_encode(\App\Engine\DataExporter::export(TestFixtures::CUSTOMER_ID)));
            foreach(PrivacyOperations::definitions() as $operation)if($operation->operationId==='customer_privacy_records')(new SchemaValidator())->validate($operation->outputSchema,$first,true);
        }finally{Database::pdo()->rollBack();}
    }
}
