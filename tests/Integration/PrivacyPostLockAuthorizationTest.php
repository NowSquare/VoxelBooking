<?php
declare(strict_types=1);
namespace Tests\Integration;
use App\Agent\{ActorContext,ConnectionAuthenticator,ConnectionService,Endpoints,Operations,OperationException};
use App\Application\Privacy\{PrivacyService,DeletionService};
use App\Engine\{CustomerCapability,Database};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrivacyPostLockAuthorizationTest extends TestCase
{
    protected function setUp():void {\Tests\Support\TestEnvironment::boot();TestFixtures::provision();\Tests\Support\CustomerFixtureState::restore();}
    public static function actions():array {return [['export'],['requestDeletion'],['confirm'],['dismiss']];}
    #[DataProvider('actions')]
    public function testScopeRemovedWhileWriterWaitsCannotCommit(string $action):void
    {
        if(!function_exists('pcntl_fork'))self::markTestSkipped('pcntl required for blocked-writer evidence.');
        $tenant=TestFixtures::BUSINESS_TENANT_ID;$customer=TestFixtures::CUSTOMER_ID;
        $service=new ConnectionService(Operations::registry());$expiry=gmdate('Y-m-d H:i:s',time()+1200);
        $operator=in_array($action,['confirm','dismiss'],true);
        if($operator){
            Database::execute('UPDATE customers SET deletion_requested_at=UTC_TIMESTAMP() WHERE id=?',[$customer]);
            $source=new ActorContext('operator',TestFixtures::OPERATOR_ID,'operator',[$tenant],['privacy-review:read','privacy-review:manage'],authMode:'session');
            $grant=$service->createGrant($source,'Blocked deletion review',$source->scopes,[$tenant],$expiry,'Regression');
            $reduced=['privacy-review:read'];
        }else{
            $secret=CustomerCapability::issuePrivacy($tenant,$customer);
            $cap=CustomerCapability::verify($secret,CustomerCapability::PRIVACY,$tenant,$customer);$source=CustomerCapability::actorById($cap['id']);
            $grant=$service->createCustomerGrant($source,'Blocked privacy writer',$source->scopes,$expiry,'Regression');
            $reduced=['privacy:read'];
        }
        $key=$service->issueCredential($grant['id'],Endpoints::resource(),$expiry);
        $actor=(new ConnectionAuthenticator())->authenticate($key['raw_token'],Endpoints::resource());
        Database::reset();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);$file=tempnam(sys_get_temp_dir(),'vb-privacy-lock-');$pid=pcntl_fork();
        if($pid===0){
            fclose($pair[0]);fread($pair[1],1);$id=Database::query('SELECT CONNECTION_ID() AS id')[0]['id'];fwrite($pair[1],$id."\n");
            try{$class=$operator?DeletionService::class:PrivacyService::class;$class::$action($actor,['business_id'=>$tenant,'customer_id'=>$customer]);$result=['status'=>200];}
            catch(OperationException $error){$result=['status'=>$error->status,'code'=>$error->problemCode];}
            catch(\Throwable $error){$result=['status'=>500,'error'=>$error::class];}
            file_put_contents($file,json_encode($result));fclose($pair[1]);exit(0);
        }
        self::assertGreaterThan(0,$pid);fclose($pair[1]);Database::pdo()->beginTransaction();
        try{
            Database::query('SELECT id FROM tenants WHERE id=? FOR UPDATE',[$tenant]);
            fwrite($pair[0],'1');$child=(int)trim(fgets($pair[0]));$blocked=false;$deadline=microtime(true)+5;
            do{
                foreach(Database::query('SHOW FULL PROCESSLIST') as $row)if((int)$row['Id']===$child&&is_string($row['Info'])&&str_contains($row['Info'],'FROM tenants WHERE id')&&str_contains($row['Info'],'FOR UPDATE')){$blocked=true;break;}
                if(!$blocked)usleep(1000);
            }while(!$blocked&&microtime(true)<$deadline);
            self::assertTrue($blocked,'Writer must pass its first authorization check and wait on the business lock.');
            Database::execute('UPDATE agent_connections SET scopes=? WHERE id=?',[json_encode($reduced),$grant['id']]);Database::pdo()->commit();
        }finally{if(Database::pdo()->inTransaction())Database::pdo()->rollBack();fclose($pair[0]);pcntl_waitpid($pid,$status);}
        $result=json_decode(file_get_contents($file),true);unlink($file);self::assertSame(403,$result['status'],json_encode($result));
        $row=Database::query('SELECT is_anonymized,deletion_requested_at FROM customers WHERE id=?',[$customer])[0];self::assertSame(0,(int)$row['is_anonymized']);
        if($operator)self::assertNotNull($row['deletion_requested_at']);else self::assertNull($row['deletion_requested_at']);
    }
}
