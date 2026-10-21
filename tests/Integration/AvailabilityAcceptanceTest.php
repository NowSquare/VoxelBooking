<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Agent\{Endpoints,PreviewStore};
use App\Engine\{Auth,Database,Ulid};
use PHPUnit\Framework\TestCase;

/** A21/A23: identical fixture rules through the browser's API, HTTP v2 and native MCP. */
final class AvailabilityAcceptanceTest extends TestCase
{
    private array $businesses=[];
    private array $cookies=[];
    private array $session=[];

    protected function setUp():void
    {
        \Tests\Support\TestEnvironment::boot();TestFixtures::provision();
        Auth::startSession();$this->session=$_SESSION;
        Database::execute("DELETE FROM rate_limits WHERE endpoint_group IN ('agent','public')");
        Database::execute('DELETE FROM agent_rate_budgets');
    }
    protected function tearDown():void
    {
        $_SESSION=$this->session;
        foreach($this->cookies as $cookie)if(is_file($cookie))unlink($cookie);
        foreach($this->businesses as $id)Database::execute('DELETE FROM tenants WHERE id=?',[$id]);
    }
    private function business(string $pattern):array
    {
        $id=Ulid::generate();$slug='acceptance-'.strtolower($id);$this->businesses[]=$id;
        Database::execute("INSERT INTO tenants(id,name,slug,email,booking_pattern,timezone,currency,status,min_advance_hours,max_advance_days,requires_consent,booking_requires_approval,max_bookings_per_customer_per_day,buffer_minutes,slot_duration_minutes) VALUES(?, 'Acceptance fixture',?,'sink@example.test',?,'Europe/Amsterdam','EUR','active',0,365,0,0,20,15,30)",[$id,$slug,$pattern]);
        $date=(new \DateTimeImmutable('+10 days',new \DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
        $session=$this->request('/api/agent/v2/public/'.$slug.'/session',[]);
        self::assertSame(200,$session['status'],$session['raw']);
        return ['id'=>$id,'slug'=>$slug,'date'=>$date,'day'=>(int)(new \DateTimeImmutable($date))->format('N')-1,'continuation'=>$session['json']['continuation']];
    }
    private function request(string $path,?array $body=null,array $headers=[],?string $cookie=null):array
    {
        $curl=curl_init(Endpoints::baseUrl().$path);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>[
            'Content-Type: application/json','Accept: application/json, text/event-stream','MCP-Protocol-Version: 2025-11-25',...$headers,
        ]]);
        if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));
        if($cookie!==null)curl_setopt_array($curl,[CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie]);
        $raw=curl_exec($curl);self::assertIsString($raw);
        $status=curl_getinfo($curl,CURLINFO_HTTP_CODE);unset($curl);
        return ['status'=>$status,'raw'=>$raw,'json'=>json_decode($raw,true)];
    }
    private function native(array $business,string $operation,array $arguments):array
    {
        return $this->request('/mcp/public/'.$business['slug'],[
            'jsonrpc'=>'2.0','id'=>17,'method'=>'tools/call',
            'params'=>['name'=>$operation,'arguments'=>(object)($arguments+['continuation'=>$business['continuation']])],
        ]);
    }
    private function choices(array $business,array $input):array
    {
        $query=http_build_query($input);
        $legacy=$this->request('/api/'.$business['slug'].(isset($input['party_size'])?'/capacity/slots':'/availability').'?'.$query);
        $http=$this->request('/api/agent/v2/public/'.$business['slug'].'/availability?'.$query);
        $mcp=$this->native($business,'availability_search',$input);
        foreach([$legacy,$http,$mcp] as $response)self::assertSame(200,$response['status'],$response['raw']);
        self::assertFalse($mcp['json']['result']['isError'],$mcp['raw']);
        $expected=$http['json']['data']['choices'];
        self::assertSame($expected,$legacy['json']['slots'],'Browser discovery must match HTTP v2.');
        self::assertSame($expected,$mcp['json']['result']['structuredContent']['data']['choices'],'Native MCP must match HTTP v2.');
        return $expected;
    }
    private function create(array $business,string $transport,array $input,?string $expectedError=null):array
    {
        $input+=['customer'=>['name'=>'Fixture guest','email'=>'guest-'.strtolower(Ulid::generate()).'@example.test']];
        if($transport==='ui'){
            $cookie=tempnam(sys_get_temp_dir(),'vb-acceptance-');$this->cookies[]=$cookie;
            $page=$this->request('/book/'.$business['slug'],cookie:$cookie);
            self::assertSame(200,$page['status'],$page['raw']);
            self::assertSame(1,preg_match('/window\.__VB_CSRF__\s*=\s*"([^"]+)"/',$page['raw'],$matches));
            $response=$this->request('/api/'.$business['slug'].'/bookings',$input+['__ts'=>(time()-5)*1000],['X-CSRF-Token: '.$matches[1]],$cookie);
            if($expectedError!==null){
                self::assertSame(409,$response['status'],$response['raw']);
                self::assertSame($expectedError,$response['json']['error']);
                return [];
            }
            self::assertSame(201,$response['status'],$response['raw']);
            return $response['json']['booking'];
        }
        if($transport==='http'){
            $response=$this->request('/api/agent/v2/public/'.$business['slug'].'/bookings/preview',$input,['X-Booking-Continuation: '.$business['continuation']]);
            if($expectedError!==null){
                self::assertSame(409,$response['status'],$response['raw']);
                self::assertSame($expectedError,$response['json']['code']);return [];
            }
            self::assertSame(200,$response['status'],$response['raw']);$preview=$response['json'];
        }else{
            $response=$this->native($business,'public_booking_create_preview',$input);
            self::assertSame(200,$response['status'],$response['raw']);
            if($expectedError!==null){
                self::assertTrue($response['json']['result']['isError'],$response['raw']);
                self::assertSame($expectedError,$response['json']['result']['_meta']['com.voxelbooking/problem']['code']);return [];
            }
            self::assertFalse($response['json']['result']['isError'],$response['raw']);$preview=$response['json']['result']['structuredContent'];
        }
        $id=basename(parse_url($preview['review_url'],PHP_URL_PATH));$store=new PreviewStore();
        $store->exchangeBrowserHandle($id,\Tests\Support\ReviewLink::secret($preview));$store->approveCustomerBrowser($id,true);
        $key='acceptance-'.Ulid::generate();$arguments=['preview_handle'=>$preview['preview_handle']];
        if($transport==='http'){
            $response=$this->request('/api/agent/v2/public/'.$business['slug'].'/bookings',$arguments,[
                'X-Booking-Continuation: '.$business['continuation'],'Idempotency-Key: '.$key,
            ]);
            self::assertSame(200,$response['status'],$response['raw']);return $response['json']['data'];
        }
        $response=$this->native($business,'public_booking_create',$arguments+['idempotency_key'=>$key]);
        self::assertSame(200,$response['status'],$response['raw']);
        self::assertFalse($response['json']['result']['isError'],$response['raw']);
        return $response['json']['result']['structuredContent']['data'];
    }
    public function testTimeslotOverridesBuffersBlocksAndDeadlineHaveIdenticalOptionsAndOutcome():void
    {
        foreach(['ui','http','mcp'] as $transport){
            $b=$this->business('timeslot');$service=Ulid::generate();$staff=Ulid::generate();
            Database::execute("INSERT INTO services(id,tenant_id,name,duration_minutes,requires_staff,price) VALUES(?,?,'Consultation',30,1,25.50)",[$service,$b['id']]);
            Database::execute("INSERT INTO staff(id,tenant_id,name,email) VALUES(?,?,'Practitioner','practitioner@example.test')",[$staff,$b['id']]);
            Database::execute('INSERT INTO service_staff(service_id,staff_id) VALUES(?,?)',[$service,$staff]);
            foreach([[null,'09:00','13:00'],[$staff,'10:00','12:00']] as [$member,$start,$end]){
                Database::execute('INSERT INTO availability(id,tenant_id,staff_id,day_of_week,start_time,end_time) VALUES(?,?,?,?,?,?)',[Ulid::generate(),$b['id'],$member,$b['day'],$start,$end]);
            }
            $customer=Ulid::generate();Database::execute("INSERT INTO customers(id,tenant_id,name,email) VALUES(?,?,'Occupied fixture','occupied@example.test')",[$customer,$b['id']]);
            Database::execute("INSERT INTO bookings(id,tenant_id,booking_pattern,service_id,staff_id,customer_id,start_datetime,end_datetime,status) VALUES(?,?,'timeslot',?,?,?,?,?,'confirmed')",[Ulid::generate(),$b['id'],$service,$staff,$customer,$b['date'].' 10:30:00',$b['date'].' 11:00:00']);
            $input=['service_id'=>$service,'staff_id'=>$staff,'date'=>$b['date']];
            self::assertSame(['11:30'],array_column($this->choices($b,$input),'time'));
            $this->create($b,$transport,$input+['time'=>'11:00'],'slot_unavailable');
            foreach([$staff,null] as $blockedStaff){
                $block=Ulid::generate();Database::execute('INSERT INTO blocked_dates(id,tenant_id,staff_id,start_date,end_date) VALUES(?,?,?,?,?)',[$block,$b['id'],$blockedStaff,$b['date'],$b['date']]);
                self::assertSame([],$this->choices($b,$input));Database::execute('DELETE FROM blocked_dates WHERE id=?',[$block]);
            }
            // This date is 10 days away; a 300-hour deadline excludes the whole day.
            Database::execute('UPDATE tenants SET min_advance_hours=300 WHERE id=?',[$b['id']]);
            self::assertSame([],$this->choices($b,$input));
            // Every transport names the minimum advance period instead of calling the time taken.
            $this->create($b,$transport,$input+['time'=>'11:30'],'too_soon');
            Database::execute('UPDATE tenants SET min_advance_hours=0 WHERE id=?',[$b['id']]);
            $booking=$this->create($b,$transport,$input+['time'=>'11:30']);
            self::assertSame('confirmed',$booking['status']);
            $row=Database::query('SELECT start_datetime,end_datetime,staff_id,price_total,price_currency FROM bookings WHERE id=?',[$booking['id']])[0];
            self::assertSame(['start_datetime'=>$b['date'].' 11:30:00','end_datetime'=>$b['date'].' 12:00:00','staff_id'=>$staff,'price_total'=>'25.50','price_currency'=>'EUR'],$row);
            self::assertSame([],$this->choices($b,$input));
        }
    }
    public function testCapacityMinimumMaximumAdjacentBoundariesAndRemainingArithmeticMatchAllTransports():void
    {
        foreach(['ui','http','mcp'] as $transport){
            $b=$this->business('capacity');$slot=Ulid::generate();
            Database::execute("INSERT INTO capacity_slots(id,tenant_id,day_of_week,start_time,end_time,max_capacity,min_party_size,max_party_size) VALUES(?,?,?,'12:00','14:00',6,2,4)",[$slot,$b['id'],$b['day']]);
            $input=['date'=>$b['date']];
            foreach([1=>false,2=>true,4=>true,5=>false] as $size=>$available){
                $choices=$this->choices($b,$input+['party_size'=>$size]);
                self::assertSame($available,$choices!==[],'Party size '.$size);
                if($available){self::assertSame(6,$choices[0]['remaining']);self::assertSame(2,$choices[0]['min_party_size']);self::assertSame(4,$choices[0]['max_party_size']);}
            }
            $this->create($b,$transport,$input+['slot_id'=>$slot,'party_size'=>1],'party_too_small');
            $this->create($b,$transport,$input+['slot_id'=>$slot,'party_size'=>5],'party_too_large');
            foreach([2=>4,4=>0] as $size=>$remaining){
                $booking=$this->create($b,$transport,$input+['slot_id'=>$slot,'party_size'=>$size]);
                self::assertSame('confirmed',$booking['status']);
                self::assertSame($size,(int)Database::query('SELECT party_size FROM bookings WHERE id=?',[$booking['id']])[0]['party_size']);
                $choices=$this->choices($b,$input+['party_size'=>2]);
                self::assertSame($remaining,$choices[0]['remaining']??0);
            }
            $this->create($b,$transport,$input+['slot_id'=>$slot,'party_size'=>2],'capacity_exceeded');
            self::assertSame(6,(int)Database::query("SELECT SUM(party_size) AS n FROM bookings WHERE tenant_id=? AND status='confirmed'",[$b['id']])[0]['n']);
            self::assertSame(2,(int)Database::query('SELECT COUNT(*) AS n FROM bookings WHERE tenant_id=?',[$b['id']])[0]['n']);
        }
    }
}
