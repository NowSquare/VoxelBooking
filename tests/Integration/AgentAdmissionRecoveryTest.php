<?php

declare(strict_types=1);
namespace Tests\Integration;

use App\Agent\{ConnectionService,Executor,Operations,PrincipalResolver,RateBudget};
use App\Application\Identity\AccountService;
use App\Engine\{Database,Ulid};
use PHPUnit\Framework\TestCase;

final class AgentAdmissionRecoveryTest extends TestCase
{
    protected function setUp(): void { TestFixtures::provision(); }

    /** Independent processes, independent connections, one release gate. */
    private function race(int $count,callable $work): array
    {
        Database::reset();$children=[];
        for($i=0;$i<$count;$i++) {
            $sockets=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
            $file=tempnam(sys_get_temp_dir(),'vb-admission-');$pid=pcntl_fork();
            if($pid===0) {
                fclose($sockets[0]);fread($sockets[1],1);fclose($sockets[1]);
                try { $result=['data'=>$work($i)]; }
                catch(\Throwable $error) { $result=['error'=>$error::class,'code'=>$error instanceof \App\Agent\OperationException?$error->problemCode:'failed']; }
                file_put_contents($file,json_encode($result,JSON_THROW_ON_ERROR));exit(0);
            }
            self::assertGreaterThan(0,$pid);fclose($sockets[1]);$children[]=[$pid,$sockets[0],$file];
        }
        foreach($children as [$pid,$socket,$file]) { fwrite($socket,'1');fclose($socket); }
        $results=[];
        foreach($children as [$pid,$socket,$file]) {
            pcntl_waitpid($pid,$status);self::assertSame(0,pcntl_wexitstatus($status));
            $result=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR);unlink($file);
            self::assertArrayNotHasKey('error',$result,json_encode($result));$results[]=$result['data'];
        }
        return $results;
    }
    private function actor(): \App\Agent\ActorContext
    {
        return (new PrincipalResolver())->resolve('business_user',TestFixtures::BUSINESS_USER_ID,
            [TestFixtures::BUSINESS_TENANT_ID],['account:read','account:manage'],authMode:'session');
    }
    public function testConcurrentCredentialAdmissionCannotExceedFixedWindow(): void
    {
        $identity=Ulid::generate();$now=time();
        $results=$this->race(10,static function()use($identity,$now):int {
            $accepted=0;
            for($request=0;$request<10;$request++) {
                $result=(new RateBudget())->consume(['credential:'.$identity=>60,'principal:'.$identity=>120],$now);
                $accepted+=(int)$result['allowed'];
            }
            return $accepted;
        });
        self::assertSame(60,array_sum($results));
        $denied=(new RateBudget())->consume(['credential:'.$identity=>60,'principal:'.$identity=>120],$now);
        self::assertFalse($denied['allowed']);self::assertSame(0,$denied['remaining']);
        self::assertGreaterThanOrEqual(1,$denied['retry_after']);self::assertLessThanOrEqual(60,$denied['retry_after']);
    }
    public function testSimultaneousIdenticalCommandsHaveOneReceiptAndExpiredReplayCannotRepeat(): void
    {
        $actor=$this->actor();$before=AccountService::read($actor);$key=Ulid::generate();
        $input=['name'=>'Concurrent profile fixture','expected_version'=>$before['version']];
        try {
            $results=$this->race(2,static fn()=>(new Executor(Operations::registry()))->execute($actor,'account_update',$input,$key));
            self::assertSame($results[0],$results[1]);
            $id=$results[0]['receipt_id'];
            self::assertSame(1,(int)Database::query("SELECT COUNT(*) AS n FROM audit_log WHERE entity_type='operation' AND entity_id=?",[$id])[0]['n']);
            Database::execute('UPDATE agent_operation_receipts SET replay_until=?,result_cipher=NULL WHERE id=?',[gmdate('Y-m-d H:i:s',time()-1),$id]);
            try { (new Executor(Operations::registry()))->execute($actor,'account_update',$input,$key);self::fail('Expired replay executed a second mutation'); }
            catch(\App\Agent\OperationException $error) { self::assertSame('replay_expired',$error->problemCode); }
            $recovered=(new Executor(Operations::registry()))->execute($actor,'operation_recover',['operation'=>'account_update','original_idempotency_key'=>$key])['data'];
            self::assertSame($id,$recovered['id']);self::assertFalse($recovered['replay_available']);
        } finally {
            Database::execute('UPDATE business_users SET name=? WHERE id=?',[$before['name'],TestFixtures::BUSINESS_USER_ID]);
        }
    }
    public function testLimiterStorageFailureReturnsControlledHttpFailureBeforeMutation(): void
    {
        $actor=$this->actor();$before=AccountService::read($actor);$base=rtrim($_ENV['APP_TEST_URL'],'/');
        $service=new ConnectionService(Operations::registry());
        $connection=$service->createGrant($actor,'Admission test',['account:read','account:manage'],[TestFixtures::BUSINESS_TENANT_ID],gmdate('Y-m-d H:i:s',time()+600),'Synthetic test');
        $key=$service->issueCredential($connection['id'],$base.'/api/agent/v2',gmdate('Y-m-d H:i:s',time()+300));
        Database::execute('DELETE FROM rate_limits');
        Database::execute('RENAME TABLE agent_rate_budgets TO fixture_unavailable_rate_budgets');
        try {
            $headers=[];$curl=curl_init($base.'/api/agent/v2/account');
            curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_POST=>true,
                CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$key['raw_token'],'Idempotency-Key: '.Ulid::generate()],
                CURLOPT_POSTFIELDS=>json_encode(['name'=>'Must not commit','expected_version'=>$before['version']]),
                CURLOPT_HEADERFUNCTION=>static function($curl,string $line)use(&$headers):int {
                    if(str_contains($line,':')) { [$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v); }return strlen($line);
                }]);
            $body=curl_exec($curl);
            self::assertSame(503,curl_getinfo($curl,CURLINFO_HTTP_CODE),$body);
            self::assertSame('rate_limiter_unavailable',json_decode($body,true)['code']);
            self::assertGreaterThan(0,(int)$headers['retry-after']);
            self::assertSame($before['name'],AccountService::read($actor)['name']);
        } finally {
            Database::execute('RENAME TABLE fixture_unavailable_rate_budgets TO agent_rate_budgets');
            $service->revoke($actor,$connection['id']);
        }
    }
    public function testExhaustedCredentialReturnsHttp429AcrossForwardedAddresses(): void
    {
        $actor=$this->actor();$base=rtrim($_ENV['APP_TEST_URL'],'/');
        $service=new ConnectionService(Operations::registry());
        $connection=$service->createGrant($actor,'Quota fixture',['account:read'],$actor->businessIds,gmdate('Y-m-d H:i:s',time()+600),'Synthetic quota check');
        $key=$service->issueCredential($connection['id'],$base.'/api/agent/v2',gmdate('Y-m-d H:i:s',time()+300));
        Database::execute('DELETE FROM rate_limits');
        try {
            // Cover the adjacent window too so crossing a minute cannot make this
            // transport assertion flaky. The concurrent admission test uses real processes.
            foreach([time(),time()+60] as $now) {
                for($i=0;$i<60;$i++) { (new RateBudget())->consume(['credential:'.$key['id']=>60],$now); }
            }
            foreach(['192.0.2.1','198.51.100.2'] as $address) {
                $headers=[];$curl=curl_init($base.'/api/agent/v2/account');
                curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,
                    CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key['raw_token'],'X-Forwarded-For: '.$address],
                    CURLOPT_HEADERFUNCTION=>static function($curl,string $line)use(&$headers):int {
                        if(str_contains($line,':')) { [$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v); }return strlen($line);
                    }]);
                $body=curl_exec($curl);
                self::assertSame(429,curl_getinfo($curl,CURLINFO_HTTP_CODE),$body);
                self::assertSame('rate_limited',json_decode($body,true)['code']);
                self::assertSame('60',$headers['x-ratelimit-limit']);self::assertSame('0',$headers['x-ratelimit-remaining']);
                self::assertGreaterThanOrEqual(1,(int)$headers['retry-after']);self::assertLessThanOrEqual(60,(int)$headers['retry-after']);
            }
        } finally { $service->revoke($actor,$connection['id']); }
    }
    public function testConnectionPaginationRetainsOlderConnectionsWithoutCrossAccountCursors(): void
    {
        $actor=$this->actor();$service=new ConnectionService(Operations::registry());$ids=[];
        try {
            for($i=0;$i<22;$i++) {
                $grant=$service->createGrant($actor,'Page '.$i,['account:read'],[TestFixtures::BUSINESS_TENANT_ID],gmdate('Y-m-d H:i:s',time()+600),'Synthetic page');
                $ids[]=$grant['id'];
            }
            $page=$service->page($actor);self::assertCount(20,$page['items']);self::assertNotNull($page['next_cursor']);
            $operator=(new PrincipalResolver())->resolve('operator',TestFixtures::OPERATOR_ID,[TestFixtures::BUSINESS_TENANT_ID],['account:read'],authMode:'session');
            try { $service->page($operator,$page['next_cursor']);self::fail('Another account reused a connection cursor'); }
            catch(\App\Agent\OperationException $error) { self::assertSame('invalid_cursor',$error->problemCode); }
            $seen=array_column($page['items'],'id');
            while($page['next_cursor']!==null) { $page=$service->page($actor,$page['next_cursor']);$seen=[...$seen,...array_column($page['items'],'id')]; }
            self::assertSame([] ,array_diff($ids,$seen));self::assertSame(count($seen),count(array_unique($seen)));
        } finally {
            foreach($ids as $id) { Database::execute('DELETE FROM agent_connections WHERE id=?',[$id]); }
        }
    }
}
