<?php

declare(strict_types=1);
namespace Tests\Integration;
use App\Agent\{ActorContext,ConnectionAuthenticator,ConnectionService,Endpoints,Operations,OperationException};
use App\Application\Media\MediaService;
use App\Engine\Database;
use PHPUnit\Framework\TestCase;
final class MediaGrantSecurityTest extends TestCase
{
    protected function setUp():void {\Tests\Support\TestEnvironment::boot();TestFixtures::provision();\Tests\Support\CustomerFixtureState::restore();Database::execute("UPDATE business_users SET role='owner',is_active=1 WHERE id=?",[TestFixtures::BUSINESS_USER_ID]);}
    private function connection(string $name):array
    {
        $service=new ConnectionService(Operations::registry());$expiry=gmdate('Y-m-d H:i:s',time()+1800);
        $source=new ActorContext('business_user',TestFixtures::BUSINESS_USER_ID,'business_owner',[TestFixtures::BUSINESS_TENANT_ID],['media:manage','services:manage','services:read','bookings:read','bookings:write'],authMode:'session');
        $grant=$service->createGrant($source,$name,$source->scopes,$source->businessIds,$expiry,'Security regression');
        $key=$service->issueCredential($grant['id'],Endpoints::resource(),$expiry);
        return ['actor'=>(new ConnectionAuthenticator())->authenticate($key['raw_token'],Endpoints::resource()),'key'=>$key['raw_token'],'id'=>$grant['id']];
    }
    public function testMediaIsBoundToSourceConnectionAndRevocation():void
    {
        $one=$this->connection('Media source');$two=$this->connection('Separate connection');$tenant=TestFixtures::BUSINESS_TENANT_ID;
        $grant=MediaService::prepare($one['actor'],$tenant,'service');
        Database::execute('UPDATE agent_media_grants SET path=?,content_hash=? WHERE id=?',['uploads/test-fixture/cover/security.png',hash('sha256','synthetic'),$grant['media_id']]);
        try {MediaService::consume($two['actor'],$tenant,$grant['media_id'],'service');self::fail('Other connection consumed source grant');}
        catch(OperationException $error){self::assertContains($error->status,[401,403,404]);}
        Database::execute('UPDATE agent_connections SET revoked_at=UTC_TIMESTAMP() WHERE id=?',[$one['id']]);
        try {MediaService::consume($one['actor'],$tenant,$grant['media_id'],'service');self::fail('Revoked source consumed media');}
        catch(OperationException $error){self::assertSame(401,$error->status);}
        self::assertNull(Database::query('SELECT consumed_at FROM agent_media_grants WHERE id=?',[$grant['media_id']])[0]['consumed_at']);
    }
    public function testCleanupNeverDeletesAlreadyConsumedMedia():void
    {
        $one=$this->connection('Cleanup source');$tenant=TestFixtures::BUSINESS_TENANT_ID;$grant=MediaService::prepare($one['actor'],$tenant,'service');
        Database::execute('UPDATE agent_media_grants SET path=?,content_hash=? WHERE id=?',['uploads/test-fixture/cover/consumed.png',hash('sha256','synthetic'),$grant['media_id']]);
        MediaService::consume($one['actor'],$tenant,$grant['media_id'],'service');
        Database::execute('UPDATE agent_media_grants SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE id=?',[$grant['media_id']]);
        $before=(int)Database::query("SELECT COUNT(*) AS n FROM delivery_outbox WHERE topic='media.delete'")[0]['n'];
        MediaService::cleanup();
        self::assertSame($before,(int)Database::query("SELECT COUNT(*) AS n FROM delivery_outbox WHERE topic='media.delete'")[0]['n']);
        self::assertNotNull(Database::query('SELECT consumed_at FROM agent_media_grants WHERE id=?',[$grant['media_id']])[0]['consumed_at']);
    }
    public function testHttpCreatePreviewResolvesWriteWhenReadSharesPath():void
    {
        $connection=$this->connection('HTTP preview');$base=rtrim($_ENV['APP_TEST_URL'],'/');
        $date=(new \DateTimeImmutable('+29 days'))->format('Y-m-d');$day=(int)(new \DateTimeImmutable($date))->format('N')-1;
        $service=\App\Engine\Ulid::generate();
        Database::execute('INSERT INTO services(id,tenant_id,name,duration_minutes,requires_staff) VALUES(?,?,?,30,0)',[$service,TestFixtures::BUSINESS_TENANT_ID,'Preview service']);
        Database::execute("INSERT INTO availability(id,tenant_id,day_of_week,start_time,end_time) VALUES(?,?,?,'08:00','18:00')",[\App\Engine\Ulid::generate(),TestFixtures::BUSINESS_TENANT_ID,$day]);
        $curl=curl_init($base.'/api/agent/v2/businesses/'.TestFixtures::BUSINESS_TENANT_ID.'/bookings/preview');
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$connection['key']],CURLOPT_POSTFIELDS=>json_encode(['service_id'=>$service,'date'=>$date,'time'=>'08:00','customer'=>['name'=>'Preview customer','email'=>'preview@example.test']]),CURLOPT_TIMEOUT=>15]);
        $body=(string)curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);
        self::assertSame(200,$status,$body);$result=json_decode($body,true,flags:JSON_THROW_ON_ERROR);self::assertArrayHasKey('preview_handle',$result);
        self::assertSame(0,(int)Database::query("SELECT COUNT(*) AS n FROM bookings WHERE tenant_id=? AND customer_email_snapshot='preview@example.test'",[TestFixtures::BUSINESS_TENANT_ID])[0]['n']);
    }
    public function testCleanupRereadsConsumptionAfterWaitingOnConcurrentCommit():void
    {
        if(!function_exists('pcntl_fork'))self::markTestSkipped('pcntl required for real cleanup interleaving.');
        $one=$this->connection('Concurrent cleanup');$tenant=TestFixtures::BUSINESS_TENANT_ID;$grant=MediaService::prepare($one['actor'],$tenant,'service');
        Database::execute('UPDATE agent_media_grants SET path=?,content_hash=?,expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=?',['uploads/test-fixture/cover/interleave.png',hash('sha256','synthetic'),$grant['media_id']]);
        $before=(int)Database::query("SELECT COUNT(*) AS n FROM delivery_outbox WHERE topic='media.delete'")[0]['n'];
        Database::reset();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);$resultFile=tempnam(sys_get_temp_dir(),'vb-media-race-');$pid=pcntl_fork();
        if($pid===0){
            fclose($pair[0]);fread($pair[1],1);$connection=Database::query('SELECT CONNECTION_ID() AS id')[0]['id'];fwrite($pair[1],$connection."\n");
            try{MediaService::cleanup();file_put_contents($resultFile,'ok');}catch(\Throwable $error){file_put_contents($resultFile,$error::class);}fclose($pair[1]);exit(0);
        }
        self::assertGreaterThan(0,$pid);fclose($pair[1]);
        Database::pdo()->beginTransaction();
        try{
            Database::query('SELECT id FROM tenants WHERE id=? FOR UPDATE',[$tenant]);
            // A validated media attachment is committing while expiry cleanup reads its old state.
            Database::execute('UPDATE agent_media_grants SET consumed_at=UTC_TIMESTAMP() WHERE id=?',[$grant['media_id']]);
            fwrite($pair[0],'1');$childConnection=(int)trim(fgets($pair[0]));$observed=false;$deadline=microtime(true)+5;
            do{
                foreach(Database::query('SHOW FULL PROCESSLIST') as $process){if((int)$process['Id']===$childConnection && is_string($process['Info']) && (str_contains($process['Info'],'FOR UPDATE')||str_contains($process['Info'],'DELETE FROM agent_media_grants'))){$observed=true;break;}}
                if(!$observed)usleep(10000);
            }while(!$observed && microtime(true)<$deadline);
            self::assertTrue($observed,'The cleanup worker must reach its blocked database operation before release.');
            Database::pdo()->commit();
        }finally{if(Database::pdo()->inTransaction())Database::pdo()->rollBack();fclose($pair[0]);pcntl_waitpid($pid,$status);}
        self::assertSame('ok',file_get_contents($resultFile));unlink($resultFile);
        self::assertSame($before,(int)Database::query("SELECT COUNT(*) AS n FROM delivery_outbox WHERE topic='media.delete'")[0]['n']);
        self::assertNotNull(Database::query('SELECT consumed_at FROM agent_media_grants WHERE id=?',[$grant['media_id']])[0]['consumed_at']);
    }

}
