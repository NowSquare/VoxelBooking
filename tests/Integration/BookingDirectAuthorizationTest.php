<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Agent\{ActorContext,ConnectionAuthenticator,ConnectionService,Endpoints,Operations,OperationException};
use App\Application\Availability\AvailabilityService;
use App\Application\Bookings\{BookingCommands,BookingQueries};
use App\Engine\Database;
use PHPUnit\Framework\TestCase;

final class BookingDirectAuthorizationTest extends TestCase
{
    protected function setUp():void
    {
        \Tests\Support\TestEnvironment::boot();TestFixtures::provision();\Tests\Support\CustomerFixtureState::restore();
        Database::execute("UPDATE business_users SET role='owner',is_active=1 WHERE id=?",[TestFixtures::BUSINESS_USER_ID]);
        Database::pdo()->beginTransaction();
    }
    protected function tearDown():void {if(Database::pdo()->inTransaction())Database::pdo()->rollBack();}
    private function bearer():ActorContext
    {
        $scopes=['bookings:read','bookings:write','bookings:export','availability:read'];
        $actor=new ActorContext('business_user',TestFixtures::BUSINESS_USER_ID,'business_owner',[TestFixtures::BUSINESS_TENANT_ID],$scopes,authMode:'session');
        $connections=new ConnectionService(Operations::registry());$expiry=gmdate('Y-m-d H:i:s',time()+1800);
        $grant=$connections->createGrant($actor,'Retained booking context',$scopes,$actor->businessIds,$expiry,'Regression');
        $key=$connections->issueCredential($grant['id'],Endpoints::resource(),$expiry);
        return (new ConnectionAuthenticator())->authenticate($key['raw_token'],Endpoints::resource());
    }
    private function denied(callable $action):void
    {
        try{$action();self::fail('Retained authority was accepted');}catch(OperationException $error){self::assertContains($error->status,[401,403,404]);}
    }
    public function testRetainedBearerCannotReadPreviewOrMutateAfterRevocation():void
    {
        $actor=$this->bearer();Database::execute('UPDATE agent_connections SET revoked_at=UTC_TIMESTAMP() WHERE id=?',[$actor->connectionId]);
        foreach([
            fn()=>BookingQueries::get($actor,['booking_id'=>TestFixtures::BOOKING_ID]),
            fn()=>BookingQueries::listing($actor,[]),
            fn()=>BookingQueries::export($actor,[]),
            fn()=>AvailabilityService::business($actor,[]),
            fn()=>AvailabilityService::catalog($actor,[]),
            fn()=>BookingCommands::cancel($actor,['booking_id'=>TestFixtures::BOOKING_ID]),
        ] as $action)$this->denied($action);
        self::assertSame('confirmed',Database::query('SELECT status FROM bookings WHERE id=?',[TestFixtures::BOOKING_ID])[0]['status']);
    }
    public function testCurrentScopeReductionReplacesRetainedScopes():void
    {
        $actor=$this->bearer();Database::execute('UPDATE agent_connections SET scopes=? WHERE id=?',[json_encode(['bookings:read']),$actor->connectionId]);
        self::assertSame(TestFixtures::BOOKING_ID,BookingQueries::get($actor,['booking_id'=>TestFixtures::BOOKING_ID])['id']);
        $this->denied(fn()=>BookingQueries::export($actor,[]));
        $this->denied(fn()=>BookingCommands::cancel($actor,['booking_id'=>TestFixtures::BOOKING_ID]));
    }
    public function testSuppliedOperatorRoleDoesNotPromoteBusinessIdentity():void
    {
        $actor=new ActorContext('business_user',TestFixtures::BUSINESS_USER_ID,'operator',[TestFixtures::BUSINESS_TENANT_ID],['bookings:read','bookings:export'],authMode:'session');
        $this->denied(fn()=>BookingQueries::operatorListing($actor,[]));
        $this->denied(fn()=>BookingQueries::export($actor,[],true));
    }
    public function testDirectCustomerAndProspectiveWritesRespectActionScopes():void
    {
        $tenant=TestFixtures::BUSINESS_TENANT_ID;
        $secret=\App\Engine\CustomerCapability::issueManage($tenant,TestFixtures::BOOKING_ID);
        $grant=\App\Engine\CustomerCapability::verify($secret,\App\Engine\CustomerCapability::MANAGE,$tenant,TestFixtures::BOOKING_ID);
        $source=\App\Engine\CustomerCapability::actorById($grant['id']);$service=new ConnectionService(Operations::registry());$expiry=gmdate('Y-m-d H:i:s',time()+1200);
        $connection=$service->createCustomerGrant($source,'Read only booking',['bookings:read'],$expiry,'Regression');
        $key=$service->issueCredential($connection['id'],Endpoints::resource(),$expiry);$actor=(new ConnectionAuthenticator())->authenticate($key['raw_token'],Endpoints::resource());
        $this->denied(fn()=>BookingCommands::cancel($actor,['booking_id'=>TestFixtures::BOOKING_ID]));
        $this->denied(fn()=>BookingCommands::reschedule($actor,['booking_id'=>TestFixtures::BOOKING_ID,'target'=>[]]));
        $public=new ActorContext('public',\App\Engine\Ulid::generate(),'prospective_customer',[$tenant],['public:read'],authMode:'public');
        $this->denied(fn()=>BookingCommands::create($public,[]));
        $cancelGrant=$service->createCustomerGrant($source,'Cancel only booking',['bookings:cancel'],$expiry,'Regression');
        $cancelKey=$service->issueCredential($cancelGrant['id'],Endpoints::resource(),$expiry);
        $cancelActor=(new ConnectionAuthenticator())->authenticate($cancelKey['raw_token'],Endpoints::resource());
        $input=['business_id'=>$tenant,'booking_id'=>TestFixtures::BOOKING_ID,'status'=>'cancelled'];
        self::assertSame('cancelled',\App\Application\Bookings\BookingOperations::previewStatus($cancelActor,$input)['to_status']);
        self::assertSame('cancelled',BookingCommands::cancel($cancelActor,$input)['status']);
        $this->denied(fn()=>BookingQueries::get($cancelActor,['booking_id'=>TestFixtures::BOOKING_ID]));
    }
}
