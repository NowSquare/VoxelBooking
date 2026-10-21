<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Agent\{OperationException,PrincipalResolver,SchemaValidator};
use App\Application\Integrations\{WebhookHttp,WebhookOperations,WebhookService};
use App\Engine\{Database,Outbox};
use PHPUnit\Framework\TestCase;

final class WebhookOperationsTest extends TestCase
{
    private array $ids=[];
    protected function setUp(): void { TestFixtures::provision(); }
    protected function tearDown(): void
    {
        foreach($this->ids as $id) {
            Database::execute('DELETE o FROM delivery_outbox o JOIN webhook_deliveries d ON d.outbox_id=o.id WHERE d.subscription_id=?',[$id]);
            Database::execute('DELETE FROM webhook_deliveries WHERE subscription_id=?',[$id]);
            Database::execute('DELETE FROM webhook_subscriptions WHERE id=?',[$id]);
        }
    }
    private function actor(): \App\Agent\ActorContext
    {
        return (new PrincipalResolver())->resolve('business_user',TestFixtures::BUSINESS_USER_ID,
            [TestFixtures::BUSINESS_TENANT_ID],['webhooks:read','webhooks:manage'],authMode:'session');
    }
    private function create(): array
    {
        $row=WebhookService::create($this->actor(),['business_id'=>TestFixtures::BUSINESS_TENANT_ID,
            'name'=>'Test receiver','endpoint'=>'https://receiver.example/events','event_types'=>WebhookService::EVENTS]);
        $this->ids[]=$row['id'];return $row;
    }
    private function publish(string $status='confirmed'): void
    {
        Database::transaction(static fn()=>WebhookService::publish(TestFixtures::BUSINESS_TENANT_ID,'booking.created',
            ['id'=>TestFixtures::BOOKING_ID,'status'=>$status,'email'=>'must-not-be-sent@example.com','notes'=>'private','booking_pattern'=>'timeslot']));
    }
    public function testProjectionExcludesSigningMaterialAndRevealIsOnceOnly(): void
    {
        $row=$this->create();
        (new SchemaValidator())->validate(WebhookOperations::definitions()[2]->outputSchema,$row);
        self::assertArrayNotHasKey('secret',$row);
        $secret=WebhookService::reveal($this->actor(),$row['business_id'],$row['id']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$secret);
        $this->expectException(OperationException::class);
        WebhookService::reveal($this->actor(),$row['business_id'],$row['id']);
    }
    public function testDomainRollbackCannotLeakAnEvent(): void
    {
        $subscription=$this->create();
        try {
            Database::transaction(static function():void {
                WebhookService::publish(TestFixtures::BUSINESS_TENANT_ID,'booking.created',['id'=>'rolled-back']);
                throw new \RuntimeException('fixture rollback');
            });
        }catch(\RuntimeException) {}
        self::assertSame(0,(int)Database::query('SELECT COUNT(*) n FROM webhook_deliveries WHERE subscription_id=?',[$subscription['id']])[0]['n']);
    }
    public function testFailureRetryPreservesEventAndExactSignedBodyWithoutPrivateFields(): void
    {
        $subscription=$this->create();$secret=WebhookService::reveal($this->actor(),$subscription['business_id'],$subscription['id']);$this->publish();
        $row=Database::query('SELECT * FROM webhook_deliveries WHERE subscription_id=?',[$subscription['id']])[0];
        $requests=[];
        $http=new WebhookHttp(static fn()=>['8.8.8.8'],static function($target,$body,$headers)use(&$requests):int {
            $requests[]=[$body,$headers];return count($requests)===1?503:204;
        });
        try { WebhookService::deliver(['delivery_id'=>$row['id']],$http);self::fail('Receiver failure lost'); }
        catch(\RuntimeException $e) { self::assertSame('webhook_delivery_failed',$e->getMessage()); }
        self::assertTrue(WebhookService::deliver(['delivery_id'=>$row['id']],$http)['sent']);
        self::assertSame($requests[0][0],$requests[1][0]);
        $event=json_decode($requests[0][0],true,flags:JSON_THROW_ON_ERROR);
        self::assertSame(['booking_pattern','id','status'],array_keys($event['data']));
        preg_match('/t=(\d+),v1=([a-f0-9]{64})/',$requests[1][1][0],$signature);
        self::assertSame(hash_hmac('sha256',$signature[1].'.'.$requests[1][0],$secret),$signature[2]);
        self::assertContains('VoxelBooking-Delivery-Id: '.$row['id'],$requests[1][1]);
        self::assertSame(204,(int)Database::query('SELECT last_http_status FROM webhook_deliveries WHERE id=?',[$row['id']])[0]['last_http_status']);
    }
    public function testRotationOverlapsAndStaleChangesConflict(): void
    {
        $row=$this->create();$old=WebhookService::reveal($this->actor(),$row['business_id'],$row['id']);
        $input=['business_id'=>$row['business_id'],'subscription_id'=>$row['id'],'expected_version'=>$row['version']];
        $changed=WebhookService::rotate($this->actor(),$input);
        $new=WebhookService::reveal($this->actor(),$row['business_id'],$row['id']);self::assertNotSame($old,$new);
        $this->publish();$delivery=Database::query('SELECT id FROM webhook_deliveries WHERE subscription_id=?',[$row['id']])[0];
        $headers=[];$http=new WebhookHttp(static fn()=>['8.8.8.8'],static function($target,$body,$sent)use(&$headers):int {$headers=$sent;return 200;});
        WebhookService::deliver(['delivery_id'=>$delivery['id']],$http);
        self::assertSame(2,substr_count($headers[0],',v1='));
        $this->expectException(OperationException::class);
        WebhookService::update($this->actor(),[...$input,'is_active'=>false]);
    }
    public function testCrossBusinessAndCurrentRoleBoundaries(): void
    {
        $row=$this->create();
        try { WebhookService::get($this->actor(),['business_id'=>'not-owned','subscription_id'=>$row['id']]);self::fail('Cross business access'); }
        catch(OperationException $error) { self::assertSame(404,$error->status); }
        $actor=$this->actor();
        Database::execute("UPDATE business_users SET role='manager' WHERE id=?",[TestFixtures::BUSINESS_USER_ID]);
        try {
            WebhookService::get($actor,['business_id'=>$row['business_id'],'subscription_id'=>$row['id']]);self::fail('Former owner access');
        }catch(OperationException $error) { self::assertSame(403,$error->status); }
        finally { Database::execute("UPDATE business_users SET role='owner' WHERE id=?",[TestFixtures::BUSINESS_USER_ID]); }
    }
    public function testOutboxRecoveryAndManualRetryAreBounded(): void
    {
        $row=$this->create();$this->publish();
        $delivery=Database::query('SELECT * FROM webhook_deliveries WHERE subscription_id=?',[$row['id']])[0];
        Database::execute("UPDATE delivery_outbox SET status='failed',attempts=8 WHERE id=?",[$delivery['outbox_id']]);
        $input=['business_id'=>$row['business_id'],'delivery_id'=>$delivery['id']];
        self::assertSame('queued',WebhookService::retry($this->actor(),$input)['status']);
        $this->expectException(OperationException::class);
        WebhookService::retry($this->actor(),$input);
    }
    public function testEveryWebhookOperationAndMediaReceiptHasARealValidatedSuccess(): void
    {
        \App\Engine\Auth::startSession();$session=$_SESSION;
        $_SESSION=['auth_type'=>'business_user','auth_id'=>TestFixtures::BUSINESS_USER_ID,'auth_tenant_id'=>TestFixtures::BUSINESS_TENANT_ID];
        $actor=(new PrincipalResolver())->resolve('business_user',TestFixtures::BUSINESS_USER_ID,[TestFixtures::BUSINESS_TENANT_ID],
            ['webhooks:read','webhooks:manage','media:manage'],authMode:'session');
        $executor=new \App\Agent\Executor(\App\Agent\Operations::registry());
        $review=static function(string $operation,array $input)use($executor,$actor):array {
            $preview=$executor->preview($actor,$operation,$input);
            (new \App\Agent\PreviewStore())->approve($actor,basename(parse_url($preview['review_url'],PHP_URL_PATH)));
            $key=\App\Engine\Ulid::generate();
            $result=$executor->execute($actor,$operation,['preview_handle'=>$preview['preview_handle']],$key);
            self::assertSame($result,$executor->execute($actor,$operation,['preview_handle'=>$preview['preview_handle']],$key));
            return $result;
        };
        try {
            $created=$review('webhook_create',['name'=>'Schema receiver','endpoint'=>'https://receiver.example/events','event_types'=>WebhookService::EVENTS]);
            $id=$created['data']['id'];$this->ids[]=$id;
            $record=$executor->execute($actor,'webhook_get',['subscription_id'=>$id])['data'];
            self::assertSame($id,$record['id']);
            self::assertContains($id,array_column($executor->execute($actor,'webhooks_list',[])['data']['items'],'id'));
            $updated=$review('webhook_update',['subscription_id'=>$id,'expected_version'=>$record['version'],'name'=>'Updated receiver']);
            $rotated=$review('webhook_secret_rotate',['subscription_id'=>$id,'expected_version'=>$updated['data']['version']]);
            self::assertNotSame($updated['data']['version'],$rotated['data']['version']);
            $this->publish();
            $deliveries=$executor->execute($actor,'webhook_deliveries_list',['subscription_id'=>$id])['data'];
            self::assertCount(1,$deliveries['items']);
            $delivery=$deliveries['items'][0]['id'];
            $outbox=Database::query('SELECT outbox_id FROM webhook_deliveries WHERE id=?',[$delivery])[0]['outbox_id'];
            for($attempt=0;$attempt<3;$attempt++) {
                Database::execute("UPDATE delivery_outbox SET status='failed',attempts=8 WHERE id=?",[$outbox]);
                $retried=$executor->execute($actor,'webhook_delivery_retry',['delivery_id'=>$delivery],\App\Engine\Ulid::generate());
                self::assertSame('queued',$retried['data']['status']);
            }
            Database::execute("UPDATE delivery_outbox SET status='failed',attempts=8 WHERE id=?",[$outbox]);
            try { $executor->execute($actor,'webhook_delivery_retry',['delivery_id'=>$delivery],\App\Engine\Ulid::generate());self::fail('Fourth manual retry allowed'); }
            catch(OperationException $error) { self::assertSame('delivery_not_retryable',$error->problemCode); }
            $upload=$executor->execute($actor,'media_upload_prepare',['purpose'=>'logo'],\App\Engine\Ulid::generate());
            self::assertSame(2097152,$upload['data']['max_bytes']);
            $receipt=$executor->execute($actor,'operation_get',['receipt_id'=>$upload['receipt_id']])['data'];
            self::assertSame('media_upload_prepare',$receipt['operation']);self::assertTrue($receipt['replay_available']);
            Database::execute('DELETE FROM agent_media_grants WHERE id=?',[$upload['data']['media_id']]);
        } finally { $_SESSION=$session; }
    }
}
