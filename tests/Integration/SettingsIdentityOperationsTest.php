<?php

declare(strict_types=1);
namespace Tests\Integration;

use App\Agent\{ActorContext,ConnectionAuthenticator,ConnectionService,Executor,Operations,OperationException,PreviewStore,PrincipalResolver};
use App\Application\Identity\ConnectionState;
use App\Application\Settings\{EmailTemplates,SettingsFields,SettingsService};
use App\Engine\{Database,Ulid};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The same typed operations are exercised through the executor, HTTP and native MCP. */
final class SettingsIdentityOperationsTest extends TestCase
{
    private array $tenant;
    private string $base;
    private string $cookies;
    private array $connections=[];
    private array $session;

    protected function setUp(): void
    {
        TestFixtures::provision();
        \App\Engine\Locale::reset();
        \App\Engine\Locale::init(dirname(__DIR__,2));
        $this->base=rtrim($_ENV['APP_TEST_URL'],'/');
        $this->cookies=tempnam(sys_get_temp_dir(),'agent-settings-');
        $this->tenant=Database::query('SELECT * FROM tenants WHERE id=?',[TestFixtures::BUSINESS_TENANT_ID])[0];
        \App\Engine\Auth::startSession();
        $this->session=$_SESSION;
        $_SESSION=['auth_type'=>'business_user','auth_id'=>TestFixtures::BUSINESS_USER_ID,'auth_tenant_id'=>TestFixtures::BUSINESS_TENANT_ID];
        Database::execute('DELETE FROM rate_limits');
        Database::execute('UPDATE business_users SET role=?,is_active=1 WHERE id=?',['owner',TestFixtures::BUSINESS_USER_ID]);
    }
    protected function tearDown(): void
    {
        $fields=array_values(array_filter(array_keys($this->tenant),static fn($field)=>$field!=='id'));
        Database::execute('UPDATE tenants SET '.implode(',',array_map(static fn($f)=>'`'.$f.'`=?',$fields)).' WHERE id=?',
            [...array_map(fn($f)=>$this->tenant[$f],$fields),TestFixtures::BUSINESS_TENANT_ID]);
        Database::execute('UPDATE business_users SET name=?,role=?,is_active=1 WHERE id=?',['Test Business User','owner',TestFixtures::BUSINESS_USER_ID]);
        foreach($this->connections as $id) { Database::execute('UPDATE agent_connections SET revoked_at=NOW() WHERE id=?',[$id]); }
        $_SESSION=$this->session;
        @unlink($this->cookies);
    }
    private function actor(): ActorContext
    {
        return (new PrincipalResolver())->resolve('business_user',TestFixtures::BUSINESS_USER_ID,[TestFixtures::BUSINESS_TENANT_ID],
            ['business-settings:read','business-settings:manage','account:read','account:manage'],authMode:'session');
    }
    private function connect(array $scopes,string $transport='http'): array
    {
        $service=new ConnectionService(Operations::registry());
        $grant=$service->createGrant($this->actor(),'Settings test',$scopes,[TestFixtures::BUSINESS_TENANT_ID],gmdate('Y-m-d H:i:s',time()+3600),'Synthetic parity verification');
        $this->connections[]=$grant['id'];
        $audience=$this->base.($transport==='mcp'?'/mcp':'/api/agent/v2');
        $credential=$service->issueCredential($grant['id'],$audience,gmdate('Y-m-d H:i:s',time()+1800));
        return ['grant'=>$grant,'token'=>$credential['raw_token'],'actor'=>(new ConnectionAuthenticator())->authenticate($credential['raw_token'],$audience)];
    }
    private function request(string $path,string $method='GET',array $payload=[],?string $token=null,?string $key=null): array
    {
        // One Accept header only: servers disagree on which duplicate wins, and
        // PHP 8.3's built-in server keeps the first, which MCP rejects with 406.
        $accept=$path==='/mcp'?'application/json, text/event-stream':'application/json';
        $headers=['Accept: '.$accept,'Content-Type: application/json'];
        if($token!==null) { $headers[]='Authorization: Bearer '.$token; }
        if($key!==null) { $headers[]='Idempotency-Key: '.$key; }
        if($path==='/mcp') { $headers[]='MCP-Protocol-Version: 2025-11-25'; }
        $responseHeaders=[];
        $curl=curl_init($this->base.$path);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_CUSTOMREQUEST=>$method,
            CURLOPT_HTTPHEADER=>$headers,CURLOPT_COOKIEJAR=>$this->cookies,CURLOPT_COOKIEFILE=>$this->cookies,
            CURLOPT_HEADERFUNCTION=>static function($curl,string $line)use(&$responseHeaders):int {
                if(str_contains($line,':')) { [$k,$v]=explode(':',$line,2);$responseHeaders[strtolower(trim($k))]=trim($v); }
                return strlen($line);
            }]);
        if($method!=='GET') { curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR)); }
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);
        return ['status'=>$status,'headers'=>$responseHeaders,'body'=>$body,'json'=>json_decode($body,true)];
    }
    public static function settingsChanges(): array
    {
        return [
            'general'=>['general',['name'=>'Parity Studio','timezone'=>'Europe/Amsterdam','locale'=>'en','currency'=>'EUR']],
            'branding'=>['branding',['brand_color'=>'#234567','show_powered_by'=>false,'booking_page_heading'=>'Welcome','booking_page_description'=>'An accessible introduction.']],
            'bookingpage'=>['bookingpage',['confirmation_message'=>'See you soon','allow_cancellation'=>true,'cancellation_hours_before'=>4,'require_phone'=>false]],
            'booking'=>['booking',['slot_duration_minutes'=>20,'buffer_minutes'=>10,'min_advance_hours'=>2,'max_advance_days'=>30]],
            'privacy'=>['privacy',['requires_consent'=>true,'privacy_policy_url'=>'https://example.com/privacy','consent_text'=>'I agree','data_retention_months'=>18]],
            'notifications'=>['notifications',['notification_email'=>'team@example.com','notify_on_booking'=>false,'send_reminders'=>true,'reminder_hours_before'=>12]],
            'embed'=>['embed',['embed_button_position'=>'bottom-left','embed_button_label'=>'Reserve','allowed_embed_domains'=>'example.com']],
        ];
    }
    #[DataProvider('settingsChanges')]
    public function testEverySettingsSectionUsesStrictContractsVersionAndReplay(string $section,array $changes): void
    {
        $executor=new Executor(Operations::registry());$actor=$this->actor();
        $before=$executor->execute($actor,'business_'.$section.'_get',[])['data'];
        $arguments=['expected_version'=>$before['version'],'changes'=>$changes];
        $reviewed=in_array($section,['general','privacy'],true);
        if($reviewed) {
            $preview=$executor->preview($actor,'business_'.$section.'_update',$arguments);
            $id=basename(parse_url($preview['review_url'],PHP_URL_PATH));
            (new PreviewStore())->approve($actor,$id);
            $arguments=['preview_handle'=>$preview['preview_handle']];
        }
        $key=Ulid::generate();
        $result=$executor->execute($actor,'business_'.$section.'_update',$arguments,$key);
        self::assertNotNull($result['receipt_id']);
        foreach($changes as $field=>$value) { self::assertSame($value,$result['data']['values'][$field],$section.'.'.$field); }
        self::assertSame($result,$executor->execute($actor,'business_'.$section.'_update',$arguments,$key));
        self::assertSame($result['data'],SettingsService::read($actor,TestFixtures::BUSINESS_TENANT_ID,$section));
        $this->expectException(OperationException::class);
        SettingsService::update($actor,['business_id'=>TestFixtures::BUSINESS_TENANT_ID,'expected_version'=>$before['version'],'changes'=>$changes],$section);
    }
    public function testHttpAndMcpReadSameSettingsAndCredentialBudgetSurvivesIpMiddleware(): void
    {
        $http=$this->connect(['business-settings:read']);
        $mcp=$this->connect(['business-settings:read'],'mcp');
        foreach(SettingsFields::sections() as $section) {
            $response=$this->request('/api/agent/v2/businesses/'.TestFixtures::BUSINESS_TENANT_ID.'/settings/'.$section,token:$http['token']);
            self::assertSame(200,$response['status'],$response['body']);
            self::assertSame('60',$response['headers']['x-ratelimit-limit']);
            $native=$this->request('/mcp','POST',['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>[
                'name'=>'business_'.$section.'_get','arguments'=>new \stdClass()]],$mcp['token']);
            self::assertSame(200,$native['status'],$native['body']);
            self::assertFalse($native['json']['result']['isError']??true,$native['body']);
            self::assertSame($response['json'],$native['json']['result']['structuredContent']);
        }
        Database::execute('UPDATE business_users SET role=? WHERE id=?',['manager',TestFixtures::BUSINESS_USER_ID]);
        $denied=$this->request('/api/agent/v2/businesses/'.TestFixtures::BUSINESS_TENANT_ID.'/settings/general',token:$http['token']);
        self::assertSame(403,$denied['status'],$denied['body']);
        self::assertSame('60',$denied['headers']['x-ratelimit-limit']);
    }
    public function testHttpPreviewSelectsWriteOperationAndRequiresBrowserApproval(): void
    {
        $connection=$this->connect(['business-settings:read','business-settings:manage']);$token=$connection['token'];
        $path='/api/agent/v2/businesses/'.TestFixtures::BUSINESS_TENANT_ID.'/settings/general';
        $before=$this->request($path,token:$token);
        $preview=$this->request($path.'/preview','POST',['expected_version'=>$before['json']['data']['version'],'changes'=>['name'=>'Reviewed via browser']],$token);
        self::assertSame(200,$preview['status'],$preview['body']);
        $commit=['preview_handle'=>$preview['json']['preview_handle']];
        $key=Ulid::generate();
        $unapproved=$this->request($path,'POST',$commit,$token,$key);
        self::assertSame(409,$unapproved['status'],$unapproved['body']);
        self::assertSame('trusted_review_required',$unapproved['json']['code']);
        (new PreviewStore())->approve($this->actor(),basename(parse_url($preview['json']['review_url'],PHP_URL_PATH)));
        $saved=$this->request($path,'POST',$commit,$token,$key);
        self::assertSame(200,$saved['status'],$saved['body']);
        self::assertSame('Reviewed via browser',$saved['json']['data']['values']['name']);
        self::assertSame($saved['json'],$this->request($path,'POST',$commit,$token,$key)['json']);
        $reused=$this->request($path,'POST',$commit,$token,Ulid::generate());
        self::assertSame('preview_consumed',$reused['json']['code'],$reused['body']);
    }
    public function testIdentityAccountAndCurrentConnectionActivityNeverIncludeOtherConnections(): void
    {
        $first=$this->connect(['account:read','account:manage']);$second=$this->connect(['account:read','account:manage']);
        $executor=new Executor(Operations::registry());
        foreach([$first,$second] as $index=>$connection) {
            $before=$executor->execute($connection['actor'],'account_get',[])['data'];
            $saved=$executor->execute($connection['actor'],'account_update',['name'=>'Account fixture '.$index,'expected_version'=>$before['version']],Ulid::generate());
            self::assertSame($index===0?'Account fixture 0':'Account fixture 1',$saved['data']['name']);
        }
        $identity=$executor->execute($first['actor'],'identity_get',[])['data'];
        self::assertSame($first['grant']['id'],$identity['connection_id']);
        $state=$executor->execute($first['actor'],'connection_get',[])['data'];
        self::assertSame($first['grant']['id'],$state['id']);
        self::assertArrayNotHasKey('token_hash',$state);
        $activity=$executor->execute($first['actor'],'connection_activity',['limit'=>1])['data'];
        self::assertCount(1,$activity['items']);
        self::assertSame('agent.account_update',$activity['items'][0]['action']);
        self::assertArrayNotHasKey('details',$activity['items'][0]);
        self::assertNull($activity['next_cursor']);
        $setup=$executor->execute($first['actor'],'connection_setup',[])['data'];
        self::assertTrue($setup['requires_browser']);
        $visible=$executor->execute($first['actor'],'capabilities_get',[])['data'];
        self::assertNotContains('business_general_update',array_column($visible['operations'],'name'));
        $this->expectException(OperationException::class);
        $executor->execute($first['actor'],'account_update',['name'=>'Forged','email'=>'forged@example.com','expected_version'=>str_repeat('0',64)],Ulid::generate());
    }
    public function testReviewedSelfRevocationCommitsItsReceiptAndImmediatelyEndsAccess(): void
    {
        $connection=$this->connect(['account:read']);
        $executor=new Executor(Operations::registry());
        $preview=$executor->preview($connection['actor'],'connection_revoke',[]);
        (new PreviewStore())->approve($this->actor(),basename(parse_url($preview['review_url'],PHP_URL_PATH)));
        $result=$executor->execute($connection['actor'],'connection_revoke',['preview_handle'=>$preview['preview_handle']],Ulid::generate());
        self::assertTrue($result['data']['revoked']);
        self::assertSame(1,(int)Database::query('SELECT COUNT(*) AS n FROM agent_operation_receipts WHERE id=? AND result_cipher IS NOT NULL',[$result['receipt_id']])[0]['n']);
        $denied=$this->request('/api/agent/v2/connection',token:$connection['token']);
        self::assertSame(401,$denied['status'],$denied['body']);
        self::assertStringContainsString('resource_metadata=',$denied['headers']['www-authenticate']);
    }
    public function testTemporaryPasswordCannotEstablishDelegationEvenWithStaleSession(): void
    {
        $actor=$this->actor();
        $before=(int)Database::query('SELECT COUNT(*) AS n FROM agent_connections')[0]['n'];
        Database::execute('UPDATE business_users SET force_password_change=1 WHERE id=?',[TestFixtures::BUSINESS_USER_ID]);
        try {
            // The existing browser remains able to reach its account/password handoff.
            self::assertSame(TestFixtures::BUSINESS_USER_ID,\App\Application\Identity\AccountService::read($actor)['id']);
            try {
                (new ConnectionService(Operations::registry()))->createGrant($actor,'Forbidden',['account:read'],$actor->businessIds,gmdate('Y-m-d H:i:s',time()+600),'Temporary password');
                self::fail('A temporary password established delegation');
            } catch(OperationException $error) {
                self::assertSame('identity_verification_required',$error->problemCode);
            }
            self::assertSame($before,(int)Database::query('SELECT COUNT(*) AS n FROM agent_connections')[0]['n']);
        } finally {
            Database::execute('UPDATE business_users SET force_password_change=0 WHERE id=?',[TestFixtures::BUSINESS_USER_ID]);
        }
    }
    public function testSharedWritesRecheckScopesWithoutTransportPolicy(): void
    {
        $connection=$this->connect(['business-settings:read','account:read']);
        $actor=$connection['actor'];
        $before=SettingsService::read($actor,TestFixtures::BUSINESS_TENANT_ID,'branding');
        $email=EmailTemplates::read($actor,TestFixtures::BUSINESS_TENANT_ID);
        $account=\App\Application\Identity\AccountService::read($actor);
        foreach([
            static fn()=>SettingsService::update($actor,['business_id'=>TestFixtures::BUSINESS_TENANT_ID,'expected_version'=>$before['version'],'changes'=>['booking_page_heading'=>'Denied']],'branding'),
            static fn()=>EmailTemplates::update($actor,['business_id'=>TestFixtures::BUSINESS_TENANT_ID,'expected_version'=>$email['version'],'templates'=>[['type'=>'confirmation','subject'=>'Denied']]]),
            static fn()=>\App\Application\Identity\AccountService::update($actor,['expected_version'=>$account['version'],'name'=>'Denied']),
        ] as $write) {
            try { $write();self::fail('A shared write accepted a read-only connection'); }
            catch(OperationException $error) { self::assertSame('forbidden',$error->problemCode); }
        }
        self::assertSame($before,SettingsService::read($actor,TestFixtures::BUSINESS_TENANT_ID,'branding'));
        self::assertSame($email,EmailTemplates::read($actor,TestFixtures::BUSINESS_TENANT_ID));
        self::assertSame($account,\App\Application\Identity\AccountService::read($actor));
    }
    public function testExpiredOrChangedReviewedSettingsCannotCommitOrCreateReceipt(): void
    {
        $connection=$this->connect(['business-settings:read','business-settings:manage']);
        $executor=new Executor(Operations::registry());$actor=$connection['actor'];
        $before=$executor->execute($actor,'business_general_get',[])['data'];
        $receipts=(int)Database::query('SELECT COUNT(*) AS n FROM agent_operation_receipts WHERE connection_id=?',[$actor->connectionId])[0]['n'];
        foreach(['expired','changed'] as $case) {
            $preview=$executor->preview($actor,'business_general_update',['expected_version'=>$before['version'],'changes'=>['name'=>'Reviewed proposal']]);
            $id=basename(parse_url($preview['review_url'],PHP_URL_PATH));
            (new PreviewStore())->approve($this->actor(),$id);
            if($case==='expired') {
                Database::execute('UPDATE agent_previews SET expires_at=? WHERE id=?',[gmdate('Y-m-d H:i:s',time()-1),$id]);
                try { (new PreviewStore())->approve($this->actor(),$id);self::fail('An expired review was approved'); }
                catch(OperationException $error) { self::assertSame('preview_expired',$error->problemCode); }
            } else {
                Database::execute('UPDATE tenants SET name=? WHERE id=?',['Changed in the human UI',TestFixtures::BUSINESS_TENANT_ID]);
            }
            $response=$this->request('/api/agent/v2/businesses/'.TestFixtures::BUSINESS_TENANT_ID.'/settings/general','POST',
                ['preview_handle'=>$preview['preview_handle']],$connection['token'],Ulid::generate());
            self::assertSame($case==='expired'?410:409,$response['status'],$response['body']);
            self::assertSame($case==='expired'?'preview_expired':'version_conflict',$response['json']['code']);
            self::assertSame($case==='expired'?$before['values']['name']:'Changed in the human UI',
                Database::query('SELECT name FROM tenants WHERE id=?',[TestFixtures::BUSINESS_TENANT_ID])[0]['name']);
            self::assertSame($receipts,(int)Database::query('SELECT COUNT(*) AS n FROM agent_operation_receipts WHERE connection_id=?',[$actor->connectionId])[0]['n']);
            self::assertNull(Database::query('SELECT consumed_at FROM agent_previews WHERE id=?',[$id])[0]['consumed_at']);
        }
    }
    public function testBrowserActivityRetainsOwnRevokedHistoryAndBindsPagination(): void
    {
        $first=$this->connect(['account:read','account:manage']);$other=$this->connect(['account:read','account:manage']);
        $executor=new Executor(Operations::registry());$ids=[];
        for($i=0;$i<3;$i++) {
            $before=$executor->execute($first['actor'],'account_get',[])['data'];
            $ids[]=$executor->execute($first['actor'],'account_update',['name'=>'Activity '.$i,'expected_version'=>$before['version']],Ulid::generate())['receipt_id'];
        }
        (new ConnectionService(Operations::registry()))->revoke($this->actor(),$first['grant']['id']);
        $page=ConnectionState::browserActivity($this->actor(),$first['grant']['id'],['limit'=>2]);
        self::assertCount(2,$page['items']);self::assertNotNull($page['next_cursor']);
        $next=ConnectionState::browserActivity($this->actor(),$first['grant']['id'],['limit'=>2,'cursor'=>$page['next_cursor']]);
        self::assertCount(1,$next['items']);self::assertNull($next['next_cursor']);
        self::assertEqualsCanonicalizing($ids,array_column([...$page['items'],...$next['items']],'receipt_id'));
        foreach($page['items'] as $item) { self::assertArrayNotHasKey('details',$item); }
        try { ConnectionState::browserActivity($this->actor(),$other['grant']['id'],['cursor'=>$page['next_cursor']]);self::fail('Activity cursor crossed connections'); }
        catch(OperationException $error) { self::assertSame('invalid_cursor',$error->problemCode); }
        $operator=(new PrincipalResolver())->resolve('operator',TestFixtures::OPERATOR_ID,$this->actor()->businessIds,[],authMode:'session');
        try { ConnectionState::browserActivity($operator,$first['grant']['id'],[]);self::fail('Another person read private connection history'); }
        catch(OperationException $error) { self::assertSame('not_found',$error->problemCode); }
        \App\Engine\View::init(dirname(__DIR__,2).'/templates');
        $request=new \App\Engine\Request();$request->setAttribute('id',$first['grant']['id']);
        $response=(new \App\Controllers\Admin\ConnectionsController())->activity($request);
        self::assertSame(200,$response->getStatusCode());
        self::assertStringContainsString('Connection activity',$response->getBody());
        self::assertStringContainsString('Update your name',$response->getBody());
        self::assertStringContainsString($ids[0],$response->getBody());
        self::assertStringNotContainsString($first['token'],$response->getBody());
    }
    public function testAllEmailOverridesDefaultsAndSyntheticSamplesUseSharedContracts(): void
    {
        $savedRows=Database::query('SELECT * FROM tenant_email_templates WHERE tenant_id=?',[TestFixtures::BUSINESS_TENANT_ID]);
        $executor=new Executor(Operations::registry());$actor=$this->actor();
        try {
            $before=$executor->execute($actor,'email_templates_get',[])['data'];
            $templates=array_map(static fn($type)=>['type'=>$type,'subject'=>'Hello {customer_name}','heading'=>'Heading','body_intro'=>'At {business_name}','body_outro'=>null,'cta_label'=>'Open','is_enabled'=>true],EmailTemplates::TYPES);
            $saved=$executor->execute($actor,'email_templates_update',['expected_version'=>$before['version'],'templates'=>$templates],Ulid::generate());
            self::assertCount(6,$saved['data']['templates']);
            foreach(EmailTemplates::TYPES as $type) {
                $sample=$executor->execute($actor,'email_template_preview',['type'=>$type])['data'];
                self::assertTrue($sample['synthetic']);
                self::assertSame('Hello Alex Example',$sample['content']['subject']);
                self::assertSame('At Example Studio',$sample['content']['body_intro']);
            }
            $reset=$executor->execute($actor,'email_templates_update',['expected_version'=>$saved['data']['version'],'templates'=>[['type'=>'confirmation','subject'=>null]]],Ulid::generate());
            self::assertNull(array_column($reset['data']['templates'],null,'type')['confirmation']['subject']);
        } finally {
            Database::execute('DELETE FROM tenant_email_templates WHERE tenant_id=?',[TestFixtures::BUSINESS_TENANT_ID]);
            foreach($savedRows as $row) { Database::execute('INSERT INTO tenant_email_templates (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')',array_values($row)); }
        }
    }
}
