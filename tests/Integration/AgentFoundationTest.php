<?php

declare(strict_types=1);
namespace Tests\Integration;
use App\Agent\{ActorContext,ConnectionAuthenticator,ConnectionService,Executor,OperationDefinition,OperationException,OperationRegistry,PrincipalResolver,RateBudget};
use App\Engine\{CustomerCapability,Database,Ulid};
use PHPUnit\Framework\TestCase;

final class AgentFoundationTest extends TestCase
{
    protected function setUp(): void { TestFixtures::provision(); \Tests\Support\CustomerFixtureState::restore(); }
    private function registry(): OperationRegistry
    {
        return new OperationRegistry(new OperationDefinition('fixture_create','Fixture','Synthetic transaction test',['type'=>'object','required'=>['business_id','name'],'properties'=>['business_id'=>['type'=>'string'],'name'=>['type'=>'string']],'additionalProperties'=>false],['type'=>'object','required'=>['id'],'properties'=>['id'=>['type'=>'string']]],['services:manage'],'owner',false,false,'none',['timeslot'],'fixture',['C12'],['A13'],'POST','/fixture',static function ($actor,$input): array {
            $id=Ulid::generate(); Database::execute('INSERT INTO services (id,tenant_id,name,duration_minutes) VALUES (?,?,?,30)',[$id,$input['business_id'],$input['name']]); return ['id'=>$id];
        }));
    }
    private function actor(): ActorContext
    {
        return (new PrincipalResolver())->resolve('business_user',TestFixtures::BUSINESS_USER_ID,[TestFixtures::BUSINESS_TENANT_ID],['services:manage'],authMode:'session');
    }
    public function testMysqlMigrationAndAtomicLimiterMatch(): void
    {
        $key = Ulid::generate(); $limiter = new RateBudget();
        self::assertTrue($limiter->consume([$key=>1])['allowed']);
        self::assertFalse($limiter->consume([$key=>1])['allowed']);
    }
    public function testSameIdempotencyKeyCommitsOneRecordAndReturnsSameReceipt(): void
    {
        $registry=$this->registry(); $executor=new Executor($registry); $args=['business_id'=>TestFixtures::BUSINESS_TENANT_ID,'name'=>'Replay fixture']; $key=Ulid::generate();
        $first=$executor->execute($this->actor(),'fixture_create',$args,$key);
        $second=$executor->execute($this->actor(),'fixture_create',$args,$key);
        self::assertSame($first,$second);
        self::assertSame(1,(int)Database::query('SELECT COUNT(*) AS n FROM services WHERE id = ?',[$first['data']['id']])[0]['n']);
        $this->expectException(OperationException::class);
        $executor->execute($this->actor(),'fixture_create',[...$args,'name'=>'Changed'],$key);
    }
    public function testRevocationRoleChangesIdentityChangesAndAudienceTakeEffect(): void
    {
        $service=new ConnectionService($this->registry()); $grant=$service->createGrant($this->actor(),'Test connection',['services:manage'],[TestFixtures::BUSINESS_TENANT_ID],gmdate('Y-m-d H:i:s',time()+3600),'Synthetic tests');
        $key=$service->issueCredential($grant['id'],'https://fixture.invalid/mcp',gmdate('Y-m-d H:i:s',time()+600));
        $auth=new ConnectionAuthenticator();
        self::assertSame('business_owner',$auth->authenticate($key['raw_token'],'https://fixture.invalid/mcp')->role);
        Database::execute("UPDATE business_users SET role = 'manager' WHERE id = ?",[TestFixtures::BUSINESS_USER_ID]);
        self::assertSame('business_manager',$auth->authenticate($key['raw_token'],'https://fixture.invalid/mcp')->role);
        Database::execute("UPDATE business_users SET role = 'owner' WHERE id = ?",[TestFixtures::BUSINESS_USER_ID]);
        try { $auth->authenticate($key['raw_token'],'https://another.invalid/mcp'); self::fail('Wrong resource accepted'); } catch (OperationException $e) { self::assertSame(401,$e->status); }
        $service->revoke($this->actor(),$grant['id']);
        $this->expectException(OperationException::class); $auth->authenticate($key['raw_token'],'https://fixture.invalid/mcp');
    }
    public function testCustomerConnectionNeverOutlivesItsSourceCapability(): void
    {
        $raw = CustomerCapability::issueManage(TestFixtures::BUSINESS_TENANT_ID, TestFixtures::BOOKING_ID);
        $source = CustomerCapability::verify($raw, CustomerCapability::MANAGE, TestFixtures::BUSINESS_TENANT_ID, TestFixtures::BOOKING_ID);
        $actor = CustomerCapability::actorById($source['id']);
        $service = new ConnectionService($this->registry());
        $grant = $service->createCustomerGrant($actor, 'Customer test', ['bookings:read'], gmdate('Y-m-d H:i:s', time()+600), 'Read this booking');
        $key = $service->issueCredential($grant['id'], 'https://fixture.invalid/mcp', gmdate('Y-m-d H:i:s', time()+300));
        $delegated = (new ConnectionAuthenticator())->authenticate($key['raw_token'], 'https://fixture.invalid/mcp');
        self::assertSame(TestFixtures::BOOKING_ID, $delegated->bookingId);
        self::assertSame(['bookings:read'], $delegated->scopes);
        Database::execute('UPDATE customer_capabilities SET revoked_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $source['id']]);
        $this->expectException(OperationException::class);
        (new ConnectionAuthenticator())->refresh($delegated);
    }
}
