<?php

declare(strict_types=1);
namespace Tests\Integration;

use App\Agent\ActorContext;
use App\Agent\OperationException;
use App\Application\System\SystemSettings;
use App\Application\System\JobCoordinator;
use App\Application\System\SystemAudit;
use App\Engine\Database;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

final class SystemOperationsTest extends TestCase
{
    private ActorContext $actor;
    private array $settingsBefore = [];
    private array $sessionBefore = [];
    protected function setUp(): void
    {
        TestFixtures::provision();
        \Tests\Support\CustomerFixtureState::restore();
        \App\Engine\Auth::startSession();
        $this->sessionBefore = $_SESSION;
        $_SESSION['auth_type'] = 'operator';
        $_SESSION['auth_id'] = TestFixtures::OPERATOR_ID;
        foreach(['app_name','smtp_password','cron_token'] as $key){$this->settingsBefore[$key]=Database::query('SELECT value FROM settings WHERE `key`=?',[$key])[0]['value']??null;}
        $this->actor=new ActorContext('operator',TestFixtures::OPERATOR_ID,'operator',[TestFixtures::BUSINESS_TENANT_ID],['system:read','system:manage','jobs:read','jobs:run','audit:read','audit:export','businesses:read'],authMode:'session');
    }
    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBefore;
        foreach($this->settingsBefore as $key=>$value){
            if($value===null){Database::execute('DELETE FROM settings WHERE `key`=?',[$key]);}
            else{Database::upsertSetting($key,$value);}
        }
    }
    public function testSettingsRedactSecretsAndRejectSecretApiFields(): void
    {
        Database::upsertSetting('smtp_password','never-expose-this');
        Database::upsertSetting('cron_token','never-expose-cron');
        $result=SystemSettings::get($this->actor,['section'=>'mail']);
        self::assertTrue($result['credentials_configured']);
        self::assertStringNotContainsString('never-expose',json_encode($result));
        $this->expectException(OperationException::class);
        SystemSettings::update($this->actor,['section'=>'mail','expected_version'=>$result['version'],'changes'=>['smtp_password'=>'attempt']]);
    }
    public function testSettingsRejectStaleAndRefreshCurrentRole(): void
    {
        $before=SystemSettings::get($this->actor,['section'=>'general']);
        SystemSettings::update($this->actor,['section'=>'general','expected_version'=>$before['version'],'changes'=>['app_name'=>'Updated '.Ulid::generate()]]);
        try { SystemSettings::update($this->actor,['section'=>'general','expected_version'=>$before['version'],'changes'=>['app_name'=>'Stale']]); self::fail(); }
        catch(OperationException $e){self::assertSame('version_conflict',$e->problemCode);}
        $forged=new ActorContext('business_user',TestFixtures::BUSINESS_USER_ID,'operator',[TestFixtures::BUSINESS_TENANT_ID],['system:read'],authMode:'session');
        $this->expectException(OperationException::class);
        SystemSettings::get($forged,['section'=>'general']);
    }
    public function testEarlierAuthorizationReadCannotHideCommittedSystemSettings(): void
    {
        if (!function_exists('pcntl_fork')) { $this->markTestSkipped('Requires process control for concurrency evidence.'); }
        $before = SystemSettings::get($this->actor,['section'=>'general']);
        $directory = sys_get_temp_dir().'/voxelbooking-settings-snapshot-'.bin2hex(random_bytes(12));
        mkdir($directory,0700);
        Database::reset();
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                Database::transaction(function () use ($before,$directory): void {
                    Database::query('SELECT id FROM operators WHERE id=?',[TestFixtures::OPERATOR_ID]);
                    touch($directory.'/snapshot');
                    $deadline = microtime(true)+5;
                    while (!is_file($directory.'/committed') && microtime(true)<$deadline) { usleep(1000); }
                    SystemSettings::update($this->actor,['section'=>'general','expected_version'=>$before['version'],'changes'=>['app_name'=>'Stale overwrite']]);
                });
                $result = 'committed';
            } catch (OperationException $error) { $result = $error->problemCode; }
            catch (\Throwable $error) { $result = get_class($error).':'.$error->getMessage(); }
            file_put_contents($directory.'/result',$result);
            exit(0);
        }
        if ($pid < 0) { self::fail('Could not start snapshot worker.'); }
        $deadline = microtime(true)+5;
        while (!is_file($directory.'/snapshot') && microtime(true)<$deadline) { usleep(1000); }
        self::assertFileExists($directory.'/snapshot');
        SystemSettings::update($this->actor,['section'=>'general','expected_version'=>$before['version'],'changes'=>['app_name'=>'Committed settings']]);
        touch($directory.'/committed');
        pcntl_waitpid($pid,$status);
        $result = file_get_contents($directory.'/result');
        foreach (['snapshot','committed','result'] as $file) { @unlink($directory.'/'.$file); }
        rmdir($directory);
        self::assertSame('version_conflict',$result);
        self::assertSame('Committed settings',SystemSettings::get($this->actor,['section'=>'general'])['values']['app_name']);
    }
    public function testMaintenanceRequestIsDurableAndDoesNotRunInsideMutation(): void
    {
        $request=JobCoordinator::request($this->actor,['jobs'=>['outbox_cleanup']]);
        self::assertSame('queued',$request['status']);
        self::assertNull($request['started_at']);
        Database::transaction(function():void {
            $this->expectException(\LogicException::class);
            JobCoordinator::dispatch();
        });
    }
    public function testMaintenanceLockPreventsOverlapAndRecoversAfterRelease(): void
    {
        $pdo=Database::pdo();
        $other=new \PDO('mysql:host='.$_ENV['DB_HOST'].';port='.$_ENV['DB_PORT'].';dbname='.$_ENV['DB_DATABASE'],$_ENV['DB_USERNAME'],$_ENV['DB_PASSWORD'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $lock=JobCoordinator::lockName();
        $s=$other->prepare('SELECT GET_LOCK(?,0)');$s->execute([$lock]);self::assertSame(1,(int)$s->fetchColumn());
        try {
            $request=JobCoordinator::request($this->actor,['jobs'=>['outbox_cleanup']]);
            self::assertSame('busy',JobCoordinator::dispatch()['status']);
            self::assertSame('queued',JobCoordinator::get($this->actor,['id'=>$request['id']])['status']);
        } finally {$s=$other->prepare('SELECT RELEASE_LOCK(?)');$s->execute([$lock]);}
        self::assertSame('ok',JobCoordinator::dispatch()['status']);
        self::assertSame('completed',JobCoordinator::get($this->actor,['id'=>$request['id']])['status']);
    }
    public function testAuditProjectionDoesNotLeakDetailsOrCrossBusiness(): void
    {
        $id=Ulid::generate();$foreign=Ulid::generate();
        Database::execute('INSERT INTO audit_log (id,tenant_id,actor_type,actor_id,action,entity_type,details,ip_address,request_id,created_at) VALUES (?,NULL,?,?,?,?,?,?,?,NOW())',[$id,'operator',TestFixtures::OPERATOR_ID,'system.test','settings',json_encode(['password'=>'secret-raw','name'=>'private name','connection_id'=>'connection-safe','result'=>'success']),'192.0.2.9','correlation-safe']);
        $result=SystemAudit::list($this->actor,['action'=>'system.test']);
        self::assertNotEmpty($result['items']);
        self::assertStringNotContainsString('secret-raw',json_encode($result));
        self::assertStringNotContainsString('private name',json_encode($result));
        self::assertStringNotContainsString('192.0.2.9',json_encode($result));
        self::assertSame('correlation-safe',$result['items'][0]['correlation_id']);
        $this->expectException(OperationException::class);
        SystemAudit::list($this->actor,['business_filter'=>$foreign]);
    }
    public function testSettingsAndJobContractsMatchActualExecutorResults(): void
    {
        $registry=new \App\Agent\OperationRegistry(...\App\Application\System\SystemOperations::definitions());
        $executor=new \App\Agent\Executor($registry);
        foreach(['system_settings_get'=>['section'=>'general'],'jobs_status'=>[],'system_status'=>[],'operator_business_context'=>['business_id'=>TestFixtures::BUSINESS_TENANT_ID],'audit_list'=>['limit'=>2]] as $name=>$input){
            $result=$executor->execute($this->actor,$name,$input);
            self::assertArrayHasKey('data',$result);
        }
        $preview=$executor->preview($this->actor,'jobs_run',['jobs'=>['outbox_cleanup']]);
        \App\Engine\Auth::startSession();
        $_SESSION['auth_type']='operator';$_SESSION['auth_id']=TestFixtures::OPERATOR_ID;
        (new \App\Agent\PreviewStore())->approve($this->actor,basename(parse_url($preview['review_url'],PHP_URL_PATH)));
        $key=Ulid::generate();$args=['preview_handle'=>$preview['preview_handle']];
        $first=$executor->execute($this->actor,'jobs_run',$args,$key);
        $again=$executor->execute($this->actor,'jobs_run',$args,$key);
        self::assertSame($first,$again);
        self::assertSame('queued',$first['data']['status']);
        JobCoordinator::dispatch();
        $done=$executor->execute($this->actor,'job_request_get',['id'=>$first['data']['id']]);
        self::assertSame('completed',$done['data']['status']);
    }
    public function testRevokedRequesterIsCancelledBeforeAnyJobExecutes(): void
    {
        $request=JobCoordinator::request($this->actor,['jobs'=>['outbox_cleanup']]);
        Database::execute('UPDATE system_job_requests SET principal_id=? WHERE id=?',[Ulid::generate(),$request['id']]);
        JobCoordinator::dispatch();
        $result=JobCoordinator::get($this->actor,['id'=>$request['id']]);
        self::assertSame('cancelled',$result['status']);
        self::assertSame('requester_access_revoked',$result['last_error_code']);
        self::assertNull($result['started_at']);
    }
    public function testInterruptedWorkerRetainsCompletedSteps(): void
    {
        $request=JobCoordinator::request($this->actor,['jobs'=>['outbox_cleanup']]);
        Database::execute("UPDATE system_job_requests SET status='running',attempts=1,results_json=? WHERE id=?",[json_encode([['job'=>'outbox_cleanup','status'=>'completed','effects'=>['completed'=>7],'error_code'=>null]]),$request['id']]);
        JobCoordinator::dispatch();
        $result=JobCoordinator::get($this->actor,['id'=>$request['id']]);
        self::assertSame('completed',$result['status']);
        self::assertSame(7,$result['results'][0]['effects']['completed']);
        self::assertSame(2,$result['attempts']);
    }
    public function testReminderRetriesKeepOneLogicalDeliveryAndStableMessageIdentity(): void
    {
        $mailPath=dirname(getenv('VOXELBOOKING_TEST_MANIFEST')).'/mail.jsonl';
        $mailOffset=is_file($mailPath)?count(file($mailPath,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)):0;
        Database::execute("DELETE FROM delivery_outbox WHERE topic='booking.reminder'");
        Database::execute('DELETE FROM reminders WHERE booking_id=?',[TestFixtures::BOOKING_ID]);
        Database::execute("UPDATE bookings SET status='confirmed',start_datetime=?,end_datetime=? WHERE id=?",[gmdate('Y-m-d H:i:s',time()+86400),gmdate('Y-m-d H:i:s',time()+90000),TestFixtures::BOOKING_ID]);
        Database::execute('UPDATE tenants SET send_reminders=1 WHERE id=?',[TestFixtures::BUSINESS_TENANT_ID]);
        $id=Ulid::generate();
        Database::execute('INSERT INTO reminders (id,booking_id,tenant_id,scheduled_at) VALUES (?,?,?,?)',[$id,TestFixtures::BOOKING_ID,TestFixtures::BUSINESS_TENANT_ID,'2020-01-01 00:00:00']);
        \App\Engine\ReminderJob::run();\App\Engine\ReminderJob::run();
        self::assertCount(1,Database::query("SELECT id FROM delivery_outbox WHERE topic='booking.reminder'"));
        \Tests\Support\TestEnvironment::failMail('synthetic failure');
        try {\App\Engine\Outbox::dispatch(100);} finally {\Tests\Support\TestEnvironment::failMail(null);}
        self::assertNull(Database::query('SELECT sent_at FROM reminders WHERE id=?',[$id])[0]['sent_at']);
        Database::execute("UPDATE delivery_outbox SET available_at='2020-01-01' WHERE topic='booking.reminder'");
        \App\Engine\Outbox::dispatch(100);
        self::assertNotNull(Database::query('SELECT sent_at FROM reminders WHERE id=?',[$id])[0]['sent_at']);
        self::assertCount(1,Database::query("SELECT id FROM delivery_outbox WHERE topic='booking.reminder' AND status='delivered'"));
        $messages=array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file(dirname(getenv('VOXELBOOKING_TEST_MANIFEST')).'/mail.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
        $messages=array_values(array_filter(array_slice($messages,$mailOffset),static fn(array $mail):bool=>$mail['type']==='reminder'));
        self::assertCount(2,$messages);
        self::assertNotNull($messages[0]['message_id']);
        self::assertSame($messages[0]['message_id'],$messages[1]['message_id']);
    }

    public function testDemoReminderIntentCannotEscapeAfterDemoModeChanges(): void
    {
        Database::execute('DELETE FROM reminders WHERE booking_id=?',[TestFixtures::BOOKING_ID]);
        $id=Ulid::generate();
        Database::execute('INSERT INTO reminders (id,booking_id,tenant_id,scheduled_at) VALUES (?,?,?,?)',[$id,TestFixtures::BOOKING_ID,TestFixtures::BUSINESS_TENANT_ID,'2020-01-01 00:00:00']);
        $demo=new \ReflectionProperty(\App\Engine\DemoMode::class,'active');
        $before=$demo->getValue();
        try {
            $demo->setValue(null,true);
            \App\Engine\ReminderJob::run();
            $dedupe=hash('sha256','booking.reminder'."\0".TestFixtures::BUSINESS_TENANT_ID."\0".$id);
            $row=Database::query('SELECT payload_cipher FROM delivery_outbox WHERE dedupe_hash=?',[$dedupe])[0];
            $payload=(new \App\Agent\PayloadCipher())->decrypt($row['payload_cipher']);
            self::assertTrue($payload['demo_suppressed']);
            $demo->setValue(null,false);
            $result=\App\Engine\ReminderJob::deliver($payload);
            self::assertFalse($result['sent']);
            self::assertTrue($result['demo_suppressed']);
            self::assertNotNull(Database::query('SELECT sent_at FROM reminders WHERE id=?',[$id])[0]['sent_at']);
        } finally {
            $demo->setValue(null,$before);
        }
    }

}
