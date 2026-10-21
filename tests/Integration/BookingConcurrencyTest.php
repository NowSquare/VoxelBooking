<?php

declare(strict_types=1);
namespace Tests\Integration;
use App\Agent\{ActorContext,OperationException};
use App\Application\Bookings\BookingCommands;
use App\Engine\Database;
use PHPUnit\Framework\TestCase;
final class BookingConcurrencyTest extends TestCase
{
    protected function setUp():void
    {
        \Tests\Support\TestEnvironment::boot();TestFixtures::provision();\Tests\Support\CustomerFixtureState::restore();
        Database::execute("DELETE FROM bookings WHERE service_id='race-service' OR resource_id='race-resource' OR capacity_slot_id='race-slot' OR event_id='race-event'");
        foreach(['services'=>'race-service','availability'=>'race-hours','resources'=>'race-resource','capacity_slots'=>'race-slot','events'=>'race-event'] as $table=>$id)Database::execute("DELETE FROM $table WHERE id=?",[$id]);
    }
    private function actor():ActorContext{return new ActorContext('business_user',TestFixtures::BUSINESS_USER_ID,'business_owner',[TestFixtures::BUSINESS_TENANT_ID],['bookings:read','bookings:write']);}
    private function inventory():array
    {
        $tenant=TestFixtures::BUSINESS_TENANT_ID;$date=(new \DateTimeImmutable('+15 days'))->format('Y-m-d');$day=(int)(new \DateTimeImmutable($date))->format('N')-1;
        Database::execute('UPDATE tenants SET min_advance_hours=0,max_advance_days=365,booking_requires_approval=0,max_bookings_per_customer_per_day=20 WHERE id=?',[$tenant]);
        Database::execute("INSERT INTO services(id,tenant_id,name,duration_minutes,requires_staff) VALUES('race-service',?,'Race',30,0)",[$tenant]);
        Database::execute("INSERT INTO availability(id,tenant_id,day_of_week,start_time,end_time) VALUES('race-hours',?,?,'08:00','18:00')",[$tenant,$day]);
        Database::execute("INSERT INTO resources(id,tenant_id,name,capacity) VALUES('race-resource',?,'Race room',1)",[$tenant]);
        Database::execute("INSERT INTO capacity_slots(id,tenant_id,day_of_week,start_time,end_time,max_capacity) VALUES('race-slot',?,?,'18:00','19:00',1)",[$tenant,$day]);
        Database::execute("INSERT INTO events(id,tenant_id,name,start_datetime,end_datetime,max_participants) VALUES('race-event',?,'Race event',?,?,1)",[$tenant,$date.' 20:00:00',$date.' 21:00:00']);
        return [
            'timeslot'=>['service_id'=>'race-service','date'=>$date,'time'=>'09:00'],
            'resource'=>['resource_id'=>'race-resource','check_in'=>$date,'check_out'=>(new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d')],
            'capacity'=>['slot_id'=>'race-slot','date'=>$date,'party_size'=>1],
            'event'=>['event_id'=>'race-event','date'=>$date,'spot_count'=>1],
        ];
    }
    /** Starts independent processes and database connections behind one gate. */
    private function race(callable $work):array
    {
        if(!function_exists('pcntl_fork'))self::markTestSkipped('pcntl is required for concurrent database verification.');
        Database::reset();$children=[];
        for($i=0;$i<2;$i++){
            $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);$file=tempnam(sys_get_temp_dir(),'vb-race-');
            $pid=pcntl_fork();
            if($pid===0){fclose($pair[0]);fread($pair[1],1);fclose($pair[1]);try{$value=$work($i);$result=['ok'=>true,'id'=>$value['id']??$value['new_booking_id']??null];}catch(\Throwable $error){$result=['ok'=>false,'error'=>$error instanceof OperationException?$error->problemCode:$error::class];}file_put_contents($file,json_encode($result));exit(0);}
            self::assertGreaterThan(0,$pid);fclose($pair[1]);$children[]=[$pid,$pair[0],$file];
        }
        foreach($children as [$pid,$socket,$file]){fwrite($socket,'1');fclose($socket);}
        $results=[];foreach($children as [$pid,$socket,$file]){pcntl_waitpid($pid,$status);self::assertSame(0,pcntl_wexitstatus($status));$results[]=json_decode(file_get_contents($file),true);unlink($file);}return $results;
    }
    public function testLastInventoryHasOneWinnerAcrossEveryPattern():void
    {
        $inputs=$this->inventory();$actor=$this->actor();$tenant=TestFixtures::BUSINESS_TENANT_ID;
        foreach($inputs as $pattern=>$input){
            Database::execute('UPDATE tenants SET booking_pattern=? WHERE id=?',[$pattern,$tenant]);
            $input+=['business_id'=>$tenant,'customer'=>['name'=>'Concurrent customer','email'=>'race@example.com']];
            $results=$this->race(static fn()=>BookingCommands::create($actor,$input));
            self::assertSame(1,count(array_filter($results,static fn($r)=>$r['ok'])),$pattern.': '.json_encode($results));
            self::assertSame(1,(int)Database::query("SELECT COUNT(*) AS n FROM bookings WHERE tenant_id=? AND booking_pattern=? AND customer_email_snapshot='race@example.com' AND status='confirmed'",[$tenant,$pattern])[0]['n']);
        }
    }
    public function testSimultaneousCancelHasOneLogicalNotificationAndCounterChange():void
    {
        // The customer's own cancellation notifies the customer and the business, so a race
        // that sent either message twice would show here. Staff cancellations send none.
        $secret=\App\Engine\CustomerCapability::issueManage(TestFixtures::BUSINESS_TENANT_ID,TestFixtures::BOOKING_ID);
        $grant=\App\Engine\CustomerCapability::verify($secret,\App\Engine\CustomerCapability::MANAGE,TestFixtures::BUSINESS_TENANT_ID,TestFixtures::BOOKING_ID);
        $actor=\App\Engine\CustomerCapability::actorById($grant['id']);$input=['business_id'=>TestFixtures::BUSINESS_TENANT_ID,'booking_id'=>TestFixtures::BOOKING_ID];
        Database::execute('UPDATE customers SET booking_count=2 WHERE id=?',[TestFixtures::CUSTOMER_ID]);
        $before=(int)Database::query("SELECT COUNT(*) AS n FROM delivery_outbox WHERE topic='booking.notification'")[0]['n'];
        $results=$this->race(static fn()=>BookingCommands::cancel($actor,$input));
        self::assertTrue($results[0]['ok']);self::assertTrue($results[1]['ok']);
        self::assertSame(1,(int)Database::query('SELECT booking_count FROM customers WHERE id=?',[TestFixtures::CUSTOMER_ID])[0]['booking_count']);
        self::assertSame($before+2,(int)Database::query("SELECT COUNT(*) AS n FROM delivery_outbox WHERE topic='booking.notification'")[0]['n']);
    }
    public function testEveryRestorationRechecksAllPatternInventory():void
    {
        $inputs=$this->inventory();$actor=$this->actor();$tenant=TestFixtures::BUSINESS_TENANT_ID;
        foreach($inputs as $pattern=>$input){
            Database::execute('UPDATE tenants SET booking_pattern=? WHERE id=?',[$pattern,$tenant]);
            $winner=BookingCommands::create($actor,$input+['business_id'=>$tenant,'customer'=>['name'=>'Restoration','email'=>'restore@example.com']]);
            foreach(array_merge(['pending','cancelled','completed','no_show'],$pattern==='event'?['waitlisted']:[]) as $status){
                $id='restore-'.$pattern.'-'.$status;
                Database::execute('INSERT INTO bookings(id,tenant_id,customer_id,booking_pattern,service_id,staff_id,resource_id,event_id,capacity_slot_id,start_datetime,end_datetime,party_size,status) SELECT ?,tenant_id,customer_id,booking_pattern,service_id,staff_id,resource_id,event_id,capacity_slot_id,start_datetime,end_datetime,party_size,? FROM bookings WHERE id=?',[$id,$status,$winner['id']]);
                try{BookingCommands::changeStatus($actor,['business_id'=>$tenant,'booking_id'=>$id,'status'=>'confirmed']);self::fail('Occupied inventory restored: '.$pattern.'/'.$status);}
                catch(OperationException $error){self::assertSame('inventory_conflict',$error->problemCode);}
                self::assertSame($status,Database::query('SELECT status FROM bookings WHERE id=?',[$id])[0]['status']);
                Database::execute('DELETE FROM bookings WHERE id=?',[$id]);
            }
        }
    }
    public function testDatabaseFailureAfterOriginalLockRollsBackEntireMove():void
    {
        $inputs=$this->inventory();$actor=$this->actor();$tenant=TestFixtures::BUSINESS_TENANT_ID;
        Database::execute("UPDATE tenants SET booking_pattern='capacity' WHERE id=?",[$tenant]);
        Database::execute("UPDATE bookings SET booking_pattern='capacity',start_datetime=?,end_datetime=?,party_size=1,status='confirmed' WHERE id=?",[(new \DateTimeImmutable('+3 days'))->format('Y-m-d').' 10:00:00',(new \DateTimeImmutable('+3 days'))->format('Y-m-d').' 11:00:00',TestFixtures::BOOKING_ID]);
        $before=(int)Database::query('SELECT COUNT(*) AS n FROM bookings WHERE tenant_id=?',[$tenant])[0]['n'];
        Database::execute("ALTER TABLE bookings ADD CONSTRAINT vb_test_fail_move CHECK (capacity_slot_id IS NULL OR capacity_slot_id <> 'race-slot' OR customer_id <> '" . TestFixtures::CUSTOMER_ID . "')");
        try{
            try{BookingCommands::reschedule($actor,['business_id'=>$tenant,'booking_id'=>TestFixtures::BOOKING_ID,'target'=>['date'=>$inputs['capacity']['date'],'slot_id'=>'race-slot']]);self::fail('Injected failure must abort move');}
            catch(OperationException $error){self::assertSame('reschedule_failed',$error->problemCode);}
        }finally{Database::execute('ALTER TABLE bookings DROP CHECK vb_test_fail_move');}
        self::assertSame('confirmed',Database::query('SELECT status FROM bookings WHERE id=?',[TestFixtures::BOOKING_ID])[0]['status']);
        self::assertSame($before,(int)Database::query('SELECT COUNT(*) AS n FROM bookings WHERE tenant_id=?',[$tenant])[0]['n']);
    }
    public function testConcurrentMovesProduceOneReplacementAndKeepHistory():void
    {
        $inputs=$this->inventory();$actor=$this->actor();$tenant=TestFixtures::BUSINESS_TENANT_ID;$date=$inputs['capacity']['date'];
        Database::execute("UPDATE tenants SET booking_pattern='capacity' WHERE id=?",[$tenant]);
        Database::execute("UPDATE bookings SET booking_pattern='capacity',start_datetime=?,end_datetime=?,party_size=1,status='confirmed' WHERE id=?",[(new \DateTimeImmutable('+3 days'))->format('Y-m-d').' 10:00:00',(new \DateTimeImmutable('+3 days'))->format('Y-m-d').' 11:00:00',TestFixtures::BOOKING_ID]);
        $input=['business_id'=>$tenant,'booking_id'=>TestFixtures::BOOKING_ID,'target'=>['date'=>$date,'slot_id'=>'race-slot']];
        $results=$this->race(static fn()=>BookingCommands::reschedule($actor,$input));
        self::assertSame(1,count(array_filter($results,static fn($r)=>$r['ok'])),json_encode($results));
        $original=Database::query('SELECT status,rescheduled_to_id FROM bookings WHERE id=?',[TestFixtures::BOOKING_ID])[0];
        self::assertSame('rescheduled',$original['status']);self::assertNotNull($original['rescheduled_to_id']);
        self::assertSame(1,(int)Database::query("SELECT COUNT(*) AS n FROM bookings WHERE tenant_id=? AND booking_pattern='capacity' AND capacity_slot_id='race-slot' AND status='confirmed'",[$tenant])[0]['n']);
    }
    public function testRestorationRejectsInactiveCatalogAndRemovedEventOccurrences():void
    {
        $inputs=$this->inventory();$actor=$this->actor();$tenant=TestFixtures::BUSINESS_TENANT_ID;
        foreach($inputs as $pattern=>$input){
            Database::execute('UPDATE tenants SET booking_pattern=? WHERE id=?',[$pattern,$tenant]);
            $booking=BookingCommands::create($actor,$input+['business_id'=>$tenant,'customer'=>['name'=>'Topology','email'=>'topology@example.com']]);
            BookingCommands::cancel($actor,['business_id'=>$tenant,'booking_id'=>$booking['id']]);
            [$table,$id]=match($pattern){'timeslot'=>['services','race-service'],'resource'=>['resources','race-resource'],'capacity'=>['capacity_slots','race-slot'],'event'=>['events','race-event']};
            Database::execute("UPDATE $table SET is_active=0 WHERE id=?",[$id]);
            try{BookingCommands::changeStatus($actor,['business_id'=>$tenant,'booking_id'=>$booking['id'],'status'=>'confirmed']);self::fail('Inactive inventory restored: '.$pattern);}
            catch(OperationException $e){self::assertSame('inventory_conflict',$e->problemCode);}
            if($pattern==='event'){
                Database::execute("UPDATE events SET is_active=1,is_recurring=1,rrule='FREQ=DAILY;COUNT=3',exception_dates=? WHERE id=?",[json_encode([$input['date']]),$id]);
                try{BookingCommands::changeStatus($actor,['business_id'=>$tenant,'booking_id'=>$booking['id'],'status'=>'confirmed']);self::fail('Excluded occurrence restored');}
                catch(OperationException $e){self::assertSame('inventory_conflict',$e->problemCode);}
            }
            self::assertSame('cancelled',Database::query('SELECT status FROM bookings WHERE id=?',[$booking['id']])[0]['status']);
        }
    }

}
