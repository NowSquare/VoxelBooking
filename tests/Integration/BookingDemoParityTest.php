<?php

declare(strict_types=1);
namespace Tests\Integration;
use App\Agent\{ActorContext,Executor,Operations,PreviewStore,OperationException,PayloadCipher};
use App\Agent\Mcp\Server;
use App\Application\Bookings\{BookingCommands,BookingNotifications};
use App\Engine\{Database,DemoMode,Ulid};
use PHPUnit\Framework\TestCase;
final class BookingDemoParityTest extends TestCase
{
    private string $demoRoot;
    protected function setUp():void
    {
        \Tests\Support\TestEnvironment::boot();TestFixtures::provision();\Tests\Support\CustomerFixtureState::restore();
        $this->demoRoot=sys_get_temp_dir().'/vb-demo-policy-'.bin2hex(random_bytes(8));mkdir($this->demoRoot,0700);touch($this->demoRoot.'/.demo');DemoMode::init($this->demoRoot);
    }
    protected function tearDown():void
    {
        if(Database::pdo()->inTransaction())Database::pdo()->rollBack();unlink($this->demoRoot.'/.demo');rmdir($this->demoRoot);DemoMode::init(dirname(__DIR__,2));\App\Engine\Auth::clearSession();
    }
    public function testDemoWritesUseSharedRulesAndNativeReviewWhileNotificationsStaySuppressed():void
    {
        $tenant=TestFixtures::BUSINESS_TENANT_ID;$date=(new \DateTimeImmutable('+45 days'))->format('Y-m-d');$service=Ulid::generate();
        Database::execute("UPDATE business_users SET is_active=1,role='owner' WHERE id=?",[TestFixtures::BUSINESS_USER_ID]);
        Database::execute('UPDATE tenants SET send_reminders=1,reminder_hours_before=24 WHERE id=?',[$tenant]);
        Database::execute("INSERT INTO services(id,tenant_id,name,duration_minutes,requires_staff) VALUES(?,?,'Demo consult',30,0)",[$service,$tenant]);
        Database::execute("INSERT INTO availability(id,tenant_id,day_of_week,start_time,end_time) VALUES(?,?,?,'08:00','20:00')",[Ulid::generate(),$tenant,(int)(new \DateTimeImmutable($date))->format('N')-1]);
        \App\Engine\Auth::startSession();\App\Engine\Auth::setSession('business_user',TestFixtures::BUSINESS_USER_ID,'Test Business User',TestFixtures::BUSINESS_EMAIL,$tenant,'owner');
        $actor=new ActorContext('business_user',TestFixtures::BUSINESS_USER_ID,'business_owner',[$tenant],['bookings:read','bookings:write','business:manage'],authMode:'session');
        $input=['business_id'=>$tenant,'service_id'=>$service,'date'=>$date,'time'=>'08:00','customer'=>['name'=>'Demo customer','email'=>'demo-real-domain@example.net']];
        $direct=BookingCommands::create($actor,$input);
        self::assertSame('confirmed',$direct['status']);
        self::assertSame(0,(int)Database::query('SELECT COUNT(*) AS n FROM reminders WHERE booking_id=?',[$direct['id']])[0]['n']);
        $registry=Operations::registry();$executor=new Executor($registry);$input['time']='09:00';
        $preview=$executor->preview($actor,'booking_create',$input);
        $row=Database::query('SELECT id FROM agent_previews WHERE handle_hash=?',[hash('sha256',$preview['preview_handle'])])[0];
        (new PreviewStore())->approve($actor,$row['id']);
        $definition=$registry->get('booking_create');
        self::assertTrue($definition->demoAllowed);
        $server=new Server(static fn()=>[$definition->tool()],static fn($name,$args)=>$executor->execute($actor,$name,$args),['name'=>'VoxelBooking','version'=>'test']);
        $result=$server->dispatch('tools/call',(object)['name'=>'booking_create','arguments'=>(object)['preview_handle'=>$preview['preview_handle'],'idempotency_key'=>'demo-native-'.Ulid::generate()]],['protocol_version'=>Server::LEGACY_VERSION]);
        self::assertFalse($result['isError']);self::assertSame('confirmed',$result['structuredContent']['data']['status']);
        $booking=$result['structuredContent']['data']['id'];
        $messages=Database::query("SELECT payload_cipher FROM delivery_outbox WHERE topic='booking.notification' AND tenant_id=?",[$tenant]);$seen=false;
        foreach($messages as $message){$payload=(new PayloadCipher())->decrypt($message['payload_cipher']);if($payload['booking_id']!==$booking)continue;$seen=true;self::assertTrue($payload['demo_suppressed']);DemoMode::init($this->demoRoot.'/no-sentinel');self::assertTrue(BookingNotifications::deliver($payload)['suppressed']);DemoMode::init($this->demoRoot);}
        self::assertTrue($seen);
        foreach(['booking_status_set','booking_reschedule','customer_booking_cancel','customer_booking_reschedule','public_booking_create'] as $operation)self::assertTrue($registry->get($operation)->demoAllowed);
        self::assertFalse($registry->get('customer_deletion_request')->demoAllowed);
    }
}
