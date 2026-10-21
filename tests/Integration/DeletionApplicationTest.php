<?php

declare(strict_types=1);
namespace Tests\Integration;
use App\Agent\ActorContext;
use App\Engine\{CustomerAnonymizer,CustomerCapability,Database};
use PHPUnit\Framework\TestCase;
final class DeletionApplicationTest extends TestCase
{
    protected function setUp():void {\Tests\Support\TestEnvironment::boot();TestFixtures::provision();\Tests\Support\CustomerFixtureState::restore();}
    public function testAnonymizationJoinsAndRollsBackWithOuterTransaction():void
    {
        $secret=CustomerCapability::issuePrivacy(TestFixtures::BUSINESS_TENANT_ID,TestFixtures::CUSTOMER_ID);
        Database::pdo()->beginTransaction();
        try {
            CustomerAnonymizer::anonymize(TestFixtures::CUSTOMER_ID);
            self::assertTrue(Database::pdo()->inTransaction(),'Anonymization must not commit the surrounding operation.');
            self::assertNull(CustomerCapability::verify($secret,CustomerCapability::PRIVACY,TestFixtures::BUSINESS_TENANT_ID,TestFixtures::CUSTOMER_ID));
        } finally { if(Database::pdo()->inTransaction())Database::pdo()->rollBack(); }
        self::assertNotNull(CustomerCapability::verify($secret,CustomerCapability::PRIVACY,TestFixtures::BUSINESS_TENANT_ID,TestFixtures::CUSTOMER_ID));
    }
    public function testDeletionPreviewBindsExactDataAndRevokesCustomerAuthority():void
    {
        $tenant=TestFixtures::BUSINESS_TENANT_ID;$id=TestFixtures::CUSTOMER_ID;
        Database::execute('UPDATE customers SET deletion_requested_at=UTC_TIMESTAMP() WHERE id=?',[$id]);
        $operator=new ActorContext('operator',TestFixtures::OPERATOR_ID,'operator',[$tenant],['privacy-review:read','privacy-review:manage'],authMode:'session');
        $input=['business_id'=>$tenant,'customer_id'=>$id];
        $secret=CustomerCapability::issuePrivacy($tenant,$id);
        $grant=CustomerCapability::verify($secret,CustomerCapability::PRIVACY,$tenant,$id);
        $customerActor=CustomerCapability::actorById($grant['id']);
        $connection=(new \App\Agent\ConnectionService(\App\Agent\Operations::registry()))->createCustomerGrant($customerActor,'Privacy test',['privacy:read'],gmdate('Y-m-d H:i:s',time()+1800),'privacy');
        $credential=(new \App\Agent\ConnectionService(\App\Agent\Operations::registry()))->issueCredential($connection['id'],'https://example.test/mcp',$connection['expires_at']);
        $subscription=\App\Engine\Ulid::generate();
        Database::execute('INSERT INTO webhook_subscriptions(id,tenant_id,name,endpoint,event_types,secret_cipher,created_at,updated_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',[$subscription,$tenant,'Privacy erasure','https://example.com/hook',json_encode(['booking.created']),(new \App\Agent\PayloadCipher())->encrypt(['secret'=>bin2hex(random_bytes(32))])]);
        Database::transaction(static fn()=>\App\Application\Integrations\WebhookService::publish($tenant,'booking.created',['id'=>TestFixtures::BOOKING_ID,'status'=>'confirmed']));
        $definition=\App\Agent\Operations::registry()->get('deletion_confirm');
        (new \App\Agent\PreviewStore())->create($operator,$definition,$input,['private'=>'Example private review'],$tenant);
        $receipts=new \App\Agent\OperationReceiptStore();$receipt=$receipts->acquire($operator,'deletion_test',$tenant,'deletion-test-receipt-001',$input);
        $receipts->complete($receipt['id'],['data'=>['id'=>$id,'private'=>'Example private result']]);
        $review=\App\Application\Privacy\DeletionService::preview($operator,$input);
        self::assertSame(1,$review['effects']['customers']);
        self::assertGreaterThanOrEqual(1,$review['effects']['bookings']);
        Database::execute('UPDATE bookings SET notes=? WHERE id=?',['Changed after review',TestFixtures::BOOKING_ID]);
        try {\App\Application\Privacy\DeletionService::confirm($operator,$input+['expected_version'=>$review['version']]);self::fail('Stale review accepted');}
        catch(\App\Agent\OperationException $error){self::assertSame('preview_changed',$error->problemCode);}
        $fresh=\App\Application\Privacy\DeletionService::preview($operator,$input);
        $result=\App\Application\Privacy\DeletionService::confirm($operator,$input+['expected_version'=>$fresh['version']]);
        self::assertTrue($result['anonymized']);self::assertSame(1,(int)Database::query('SELECT is_anonymized FROM customers WHERE id=?',[$id])[0]['is_anonymized']);
        self::assertNull(CustomerCapability::actorById($grant['id']));
        self::assertNotNull(Database::query('SELECT revoked_at FROM agent_connections WHERE id=?',[$connection['id']])[0]['revoked_at']);
        self::assertNotNull(Database::query('SELECT revoked_at FROM agent_credentials WHERE id=?',[$credential['id']])[0]['revoked_at']);
        self::assertNull(Database::query('SELECT result_cipher FROM agent_operation_receipts WHERE id=?',[$receipt['id']])[0]['result_cipher']);
        self::assertSame([],Database::query('SELECT id FROM agent_previews WHERE tenant_id=?',[$tenant]));
        self::assertNotEmpty($receipts->get($operator,$receipt['id']));
        self::assertSame([],Database::query('SELECT id FROM webhook_deliveries WHERE tenant_id=? AND payload_cipher IS NOT NULL',[$tenant]));
        self::assertSame(0,(int)Database::query("SELECT COUNT(*) AS n FROM delivery_outbox WHERE tenant_id=? AND topic='webhook.deliver' AND status IN ('pending','failed')",[$tenant])[0]['n']);
        $booking=Database::query('SELECT customer_name_snapshot,customer_email_snapshot,customer_phone_snapshot,notes,custom_field_data FROM bookings WHERE id=?',[TestFixtures::BOOKING_ID])[0];
        self::assertSame([null,null,null,null,null],array_values($booking));
        (new \App\Agent\SchemaValidator())->validate($definition->outputSchema,$result,true);
    }
    public function testQueueCursorIsStableAndBoundToStateAndBusiness():void
    {
        $tenant=TestFixtures::BUSINESS_TENANT_ID;
        $operator=new ActorContext('operator',TestFixtures::OPERATOR_ID,'operator',[$tenant],['privacy-review:read','privacy-review:manage'],authMode:'session');
        for($i=0;$i<3;$i++)Database::execute("INSERT INTO customers(id,tenant_id,name,email,deletion_requested_at) VALUES(?,?,?,?,'2026-01-01 00:00:00')",['deletion-cursor-'.$i,$tenant,'Queue '.$i,'queue'.$i.'@example.test']);
        try{
            $first=\App\Application\Privacy\DeletionService::queue($operator,['business_id'=>$tenant,'limit'=>1]);
            self::assertNotNull($first['next_cursor']);
            Database::execute("UPDATE customers SET deletion_requested_at=NULL WHERE id=?",[$first['requests'][0]['id']]);
            $second=\App\Application\Privacy\DeletionService::queue($operator,['business_id'=>$tenant,'limit'=>1,'cursor'=>$first['next_cursor']]);
            self::assertNotSame($first['requests'][0]['id'],$second['requests'][0]['id']);
            try {\App\Application\Privacy\DeletionService::queue($operator,['business_id'=>$tenant,'state'=>'processed','cursor'=>$first['next_cursor']]);self::fail('Cursor state changed');}
            catch(\App\Agent\OperationException $e){self::assertSame('invalid_cursor',$e->problemCode);}
        }finally{Database::execute("DELETE FROM customers WHERE id LIKE 'deletion-cursor-%'");}
    }

}
