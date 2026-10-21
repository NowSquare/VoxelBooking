<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Agent\{ActorContext,ConnectionAuthenticator,ConnectionService,Endpoints,Executor,Operations,OperationRegistry,PreviewStore,PrincipalResolver,PublicContinuation,SchemaValidator};
use App\Agent\Mcp\Server;
use App\Engine\{Auth,CustomerCapability,Database,Ulid};
use PHPUnit\Framework\TestCase;

/** Real persisted success branches, reviewed writes/retries, and both read transports. */
final class BookingOperationContractCoverageTest extends TestCase
{
    private OperationRegistry $registry;
    private Executor $executor;
    private ActorContext $reviewer;
    private array $businesses=[];
    private array $connections=[];
    private array $clients=[];
    private array $session=[];
    private array $covered=[];

    protected function setUp():void
    {
        \Tests\Support\TestEnvironment::boot();TestFixtures::provision();
        $this->resetTransportBudgets();
        Auth::startSession();$this->session=$_SESSION;$_SESSION=['auth_type'=>'operator','auth_id'=>TestFixtures::OPERATOR_ID];
        $this->registry=Operations::registry();$this->executor=new Executor($this->registry);
        foreach(['timeslot','resource','capacity','event'] as $pattern){
            $id=Ulid::generate();$slug='booking-contract-'.strtolower($id);
            Database::execute("INSERT INTO tenants(id,name,slug,email,booking_pattern,timezone,currency,status,min_advance_hours,max_advance_days,requires_consent,allow_cancellation,cancellation_hours_before,allow_rescheduling,rescheduling_hours_before) VALUES(?,?,?,'contract@example.test',?,'Europe/Amsterdam','EUR','active',0,365,1,1,0,1,0)",[$id,'Booking '.$pattern,$slug,$pattern]);
            $this->businesses[$pattern]=['id'=>$id,'slug'=>$slug];
        }
        $scopes=[];foreach($this->registry->all() as $definition)$scopes=[...$scopes,...$definition->requiredScopes];
        $this->reviewer=(new PrincipalResolver())->resolve('operator',TestFixtures::OPERATOR_ID,array_column($this->businesses,'id'),array_values(array_unique($scopes)),authMode:'session');
        $this->clients['staff']=$this->connect($this->reviewer);
    }
    protected function tearDown():void
    {
        $_SESSION=$this->session;
        foreach($this->connections as $id)Database::execute('UPDATE agent_connections SET revoked_at=UTC_TIMESTAMP() WHERE id=?',[$id]);
        foreach($this->businesses as $business)Database::execute('DELETE FROM tenants WHERE id=?',[$business['id']]);
    }
    private function connect(ActorContext $source):array
    {
        $service=new ConnectionService($this->registry);$expiry=gmdate('Y-m-d H:i:s',time()+1200);
        if($source->authMode==='customer'){
            $grant=$service->createCustomerGrant($source,'Customer contract',$source->scopes,$expiry,'Disposable contract verification');
        }else{
            $scopes=[];foreach($this->registry->visible($source) as $definition)$scopes=[...$scopes,...$definition->requiredScopes];
            $grant=$service->createGrant($source,'Staff contract',array_values(array_unique($scopes)),$source->businessIds,$expiry,'Disposable contract verification');
        }
        $this->connections[]=$grant['id'];$tokens=[];
        foreach(['http','mcp'] as $transport)$tokens[$transport]=$service->issueCredential($grant['id'],Endpoints::resource($transport),$expiry)['raw_token'];
        return ['actor'=>(new ConnectionAuthenticator())->authenticate($tokens['http'],Endpoints::resource('http')),'tokens'=>$tokens,'source'=>$source];
    }
    private function resetTransportBudgets():void
    {
        // Each pattern is an independent successful-contract scenario. Rate exhaustion
        // has its own tests; never bypass the production middleware or modify its limits.
        \Tests\Support\TestEnvironment::assertDatabase();
        Database::execute("DELETE FROM rate_limits WHERE endpoint_group='agent'");
        Database::execute('DELETE FROM agent_rate_budgets');
    }
    private function customer(string $business,string $subject,string $purpose):array
    {
        $secret=$purpose===CustomerCapability::MANAGE?CustomerCapability::issueManage($business,$subject):CustomerCapability::issuePrivacy($business,$subject);
        $grant=CustomerCapability::verify($secret,$purpose,$business,$subject);
        self::assertTrue(CustomerCapability::remember($secret,$purpose,$business,$subject));
        return $this->connect(CustomerCapability::actorById($grant['id']));
    }
    private function invoke(array $client,string $name,array $input):array
    {
        $definition=$this->registry->get($name);self::assertNotNull($definition,$name);$actor=$client['actor'];
        if(!$definition->readOnly&&$definition->confirmationPolicy!=='none'){
            $preview=$this->executor->preview($actor,$name,$input);
            $id=basename(parse_url($preview['review_url'],PHP_URL_PATH));$store=new PreviewStore();
            if($actor->isStaff())$store->approve($this->reviewer,$id);
            else{$store->exchangeBrowserHandle($id,$preview['preview_handle']);$store->approveCustomerBrowser($id,true);}
            $input=['preview_handle'=>$preview['preview_handle']];
        }
        $key=$definition->readOnly?null:'contract-'.Ulid::generate();
        $result=$this->executor->execute($actor,$name,$input,$key);
        (new SchemaValidator())->validate($definition->resultSchema(),$result,true);
        self::assertSame($name,$result['operation']);$this->covered[$name]=true;
        // Exercise native tool serialization against the same executor. Writes replay their
        // receipt, so protocol verification cannot introduce another booking or notification.
        $server=new Server(static fn()=>[$definition->tool()],fn($op,$args)=>$this->executor->execute($actor,$op,$args),['name'=>'VoxelBooking','version'=>'contract']);
        $toolInput=$input;if($key!==null)$toolInput['idempotency_key']=$key;
        $native=$server->dispatch('tools/call',(object)['name'=>$name,'arguments'=>(object)$toolInput],['protocol_version'=>Server::LEGACY_VERSION]);
        self::assertFalse($native['isError'],json_encode($native));
        (new SchemaValidator())->validate($definition->resultSchema(),$native['structuredContent'],true);
        if(!$definition->readOnly){self::assertSame($result,$native['structuredContent']);self::assertNotNull($result['receipt_id']);}
        else $this->assertReadTransports($client,$name,$input,$result['data']);
        return $result+['test_idempotency_key'=>$key];
    }
    private function request(string $path,?string $token=null,?array $body=null,?string $continuation=null,?string $idempotencyKey=null):array
    {
        $headers=['Content-Type: application/json','Accept: application/json, text/event-stream','MCP-Protocol-Version: 2025-11-25'];
        if($token!==null)$headers[]='Authorization: Bearer '.$token;
        if($continuation!==null)$headers[]='X-Booking-Continuation: '.$continuation;
        if($idempotencyKey!==null)$headers[]='Idempotency-Key: '.$idempotencyKey;
        $curl=curl_init(Endpoints::baseUrl().$path);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>$headers]);
        if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));
        $raw=curl_exec($curl);self::assertSame(200,curl_getinfo($curl,CURLINFO_HTTP_CODE),(string)$raw);
        return json_decode($raw,true,flags:JSON_THROW_ON_ERROR);
    }
    private function assertReadTransports(array $client,string $name,array $input,array $expected):void
    {
        $definition=$this->registry->get($name);$selectors=$input;
        if(isset($client['slug']))$selectors['slug']=$client['slug'];
        $path=preg_replace_callback('/\{([^}]+)\}/',static fn($m)=>rawurlencode($selectors[$m[1]]),$definition->httpPath);
        $http=$this->request('/api/agent/v2'.$path.'?'.http_build_query($input),$client['tokens']['http']??null,continuation:$client['continuation']??null);
        $nativeInput=$input;if(isset($client['continuation']))$nativeInput['continuation']=$client['continuation'];
        $native=$this->request(isset($client['slug'])?'/mcp/public/'.$client['slug']:'/mcp',$client['tokens']['mcp']??null,['jsonrpc'=>'2.0','id'=>42,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>(object)$nativeInput]]);
        self::assertFalse($native['result']['isError']??true,json_encode($native));
        foreach([$http,$native['result']['structuredContent']] as $result){
            (new SchemaValidator())->validate($definition->resultSchema(),$result,true);
            $data=$result['data'];$want=$expected;
            // Opaque per-request encrypted cursors and observation timestamps are not content.
            unset($data['next_cursor'],$want['next_cursor'],$data['exported_at'],$want['exported_at']);
            self::assertSame($want,$data,$name);
        }
    }
    private function fixtures(string $pattern):array
    {
        $business=$this->businesses[$pattern];$id=$business['id'];$date=(new \DateTimeImmutable('+35 days'))->format('Y-m-d');$move=(new \DateTimeImmutable($date))->modify('+7 days')->format('Y-m-d');$offering=Ulid::generate();
        switch($pattern){
            case 'timeslot':
                Database::execute("INSERT INTO services(id,tenant_id,name,duration_minutes,requires_staff,price) VALUES(?,?,'Consultation',30,0,25.50)",[$offering,$id]);
                Database::execute("INSERT INTO availability(id,tenant_id,day_of_week,start_time,end_time) VALUES(?,?,?,'08:00','18:00')",[Ulid::generate(),$id,(int)(new \DateTimeImmutable($date))->format('N')-1]);
                $staff=Ulid::generate();Database::execute("INSERT INTO staff(id,tenant_id,name,title,email) VALUES(?,?,'Instructor','Host','instructor@example.test')",[$staff,$id]);
                $create=['service_id'=>$offering,'date'=>$date,'time'=>'09:00'];$target=['new_date'=>$move,'new_time'=>'10:00'];$availability=['service_id'=>$offering,'date'=>$date];break;
            case 'resource':
                Database::execute("INSERT INTO resources(id,tenant_id,name,capacity,price_per_night,amenities) VALUES(?,?,'Private room',4,50.25,'[\"Desk\"]')",[$offering,$id]);
                $create=['resource_id'=>$offering,'check_in'=>$date,'check_out'=>(new \DateTimeImmutable($date))->modify('+2 days')->format('Y-m-d'),'guest_count'=>2];
                $target=['check_in'=>$move,'check_out'=>(new \DateTimeImmutable($move))->modify('+2 days')->format('Y-m-d')];$availability=$create;unset($availability['guest_count']);break;
            case 'capacity':
                Database::execute("INSERT INTO capacity_slots(id,tenant_id,day_of_week,start_time,end_time,max_capacity) VALUES(?,?,?,'12:00','14:00',50)",[$offering,$id,(int)(new \DateTimeImmutable($date))->format('N')-1]);
                $create=['slot_id'=>$offering,'date'=>$date,'party_size'=>2];$target=['date'=>$move,'slot_id'=>$offering];$availability=['date'=>$date,'party_size'=>2];break;
            default:
                Database::execute("INSERT INTO events(id,tenant_id,name,start_datetime,end_datetime,max_participants,price,is_recurring,rrule) VALUES(?,?,'Workshop',?,?,50,15.00,1,'FREQ=WEEKLY;COUNT=4')",[$offering,$id,$date.' 16:00:00',$date.' 17:00:00']);
                $create=['event_id'=>$offering,'date'=>$date,'spot_count'=>2];$target=['event_id'=>$offering,'date'=>$move];$availability=['event_id'=>$offering,'date'=>$date,'spot_count'=>2];
        }
        return $business+['date'=>$date,'move'=>$move,'offering'=>$offering,'create'=>$create,'target'=>$target,'availability'=>$availability];
    }
    public function testAllFourPatternsAndEveryBookingDiscoveryAndCustomerOperationHaveSuccessfulContracts():void
    {
        foreach(['timeslot','resource','capacity','event'] as $pattern){
            $this->resetTransportBudgets();
            $f=$this->fixtures($pattern);$business=$f['id'];$base=['business_id'=>$business];$staff=$this->clients['staff'];
            $continuation=(new PublicContinuation())->issue($business);
            $public=['actor'=>(new PublicContinuation())->resolve($continuation['continuation'],$business),'continuation'=>$continuation['continuation'],'slug'=>$f['slug']];
            $this->invoke($public,'public_business_get',$base);
            $kind=match($pattern){'timeslot'=>'services','resource'=>'resources','capacity'=>'capacity_slots','event'=>'events'};
            $this->invoke($public,'public_catalog_list',$base+['kind'=>$kind]);
            if($pattern==='timeslot')$this->invoke($public,'public_catalog_list',$base+['kind'=>'staff']);
            $this->invoke($public,'availability_search',$base+$f['availability']);
            $this->invoke($staff,'staff_availability_search',$base+$f['availability']);
            $booking=$this->invoke($staff,'booking_create',$base+$f['create']+['customer'=>['name'=>'Staff guest','email'=>'staff-'.$pattern.'@example.test']])['data'];
            $this->invoke($staff,'booking_get',$base+['booking_id'=>$booking['id']]);
            $moved=$this->invoke($staff,'booking_reschedule',$base+['booking_id'=>$booking['id'],'target'=>$f['target']])['data'];
            $this->invoke($staff,'booking_status_set',$base+['booking_id'=>$moved['id'],'status'=>'cancelled']);
            // The first slot/stay is free again; prospective booking must record browser consent.
            $created=$this->invoke($public,'public_booking_create',$base+$f['create']+['customer'=>['name'=>'Verified guest','email'=>'public-'.$pattern.'@example.test']]);
            self::assertTrue($created['data']['consent_recorded']);
            $this->invoke($public,'public_operation_get',['receipt_id'=>$created['receipt_id']]);
            $this->invoke($public,'public_operation_recover',['operation'=>'public_booking_create','original_business_id'=>$business,'original_idempotency_key'=>$created['test_idempotency_key']]);
            $customerId=Database::query('SELECT customer_id FROM bookings WHERE id=?',[$created['data']['id']])[0]['customer_id'];
            $customer=$this->customer($business,$created['data']['id'],CustomerCapability::MANAGE);
            $this->invoke($customer,'customer_booking_get',$base);
            $search=$f['availability'];if(isset($search['date']))$search['date']=$f['move'];if(isset($search['check_in']))$search=array_replace($search,$f['target']);
            $this->invoke($customer,'customer_availability_search',$base+$search);
            $moved=$this->invoke($customer,'customer_booking_reschedule',$base+['target'=>$f['target']])['data'];
            $customer['actor']=(new ConnectionAuthenticator())->refresh($customer['actor']);
            $this->invoke($customer,'customer_booking_cancel',$base+['reason'=>'Contract cancellation']);
            foreach(['bookings_list','bookings_export'] as $operation)$this->invoke($staff,$operation,$base);
            $this->invoke($staff,'calendar_get',$base+['from'=>$f['date'],'to'=>$f['move']]);
            foreach(['customer_list','customer_export'] as $operation)$this->invoke($staff,$operation,$base);
            foreach(['customer_get','customer_history'] as $operation)$this->invoke($staff,$operation,$base+['customer_id'=>$customerId]);
            $privacy=$this->customer($business,$customerId,CustomerCapability::PRIVACY);
            foreach(['customer_privacy_get','customer_privacy_records','customer_privacy_export','customer_deletion_request'] as $operation)$this->invoke($privacy,$operation,$base);
            $this->invoke($staff,'deletion_queue_list',$base);
            $this->invoke($staff,'deletion_review',$base+['customer_id'=>$customerId]);
            $this->invoke($staff,'deletion_dismiss',$base+['customer_id'=>$customerId,'reason'=>'Customer elected to retain data']);
            $this->invoke($privacy,'customer_deletion_request',$base);
            $erased=$this->invoke($staff,'deletion_confirm',$base+['customer_id'=>$customerId]);
            $tombstones=Database::query("SELECT id,payload_cipher,review_cipher FROM agent_previews WHERE tenant_id=? AND operation_id='deletion_confirm'",[$business]);
            self::assertCount(1,$tombstones);
            $cipher=new \App\Agent\PayloadCipher();
            self::assertSame([],$cipher->decrypt($tombstones[0]['review_cipher']));
            self::assertSame($base+['customer_id'=>$customerId],$cipher->decrypt($tombstones[0]['payload_cipher']));
            Database::execute('UPDATE agent_previews SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 25 HOUR) WHERE id=?',[$tombstones[0]['id']]);
            Database::execute('UPDATE agent_operation_receipts SET replay_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE id=?',[$erased['receipt_id']]);
            \App\Engine\Outbox::cleanup();
            self::assertSame([],Database::query('SELECT id FROM agent_previews WHERE id=?',[$tombstones[0]['id']]));
            $recovered=$this->invoke($staff,'operation_recover',['operation'=>'deletion_confirm','original_business_id'=>$business,'original_idempotency_key'=>$erased['test_idempotency_key']]);
            self::assertSame('committed',$recovered['data']['status']);self::assertFalse($recovered['data']['replay_available']);
        }
        foreach(['operator_bookings_list','operator_bookings_export'] as $operation)$this->invoke($this->clients['staff'],$operation,[]);
        foreach([\App\Application\Bookings\BookingOperations::definitions(),\App\Application\Availability\AvailabilityOperations::definitions(),\App\Application\Privacy\PrivacyOperations::definitions(),\App\Application\Privacy\DeletionOperations::definitions(),\App\Application\Customers\CustomerOperations::definitions()] as $definitions)foreach($definitions as $definition)self::assertArrayHasKey($definition->operationId,$this->covered,$definition->operationId.' has no successful executor contract');
    }
    public function testPublicHttpAndNativePaginationSurvivesIndependentAnonymousRequests():void
    {
        $f=$this->fixtures('timeslot');
        Database::execute("INSERT INTO services(id,tenant_id,name,duration_minutes,requires_staff) VALUES(?,?,'Another offering',30,0)",[Ulid::generate(),$f['id']]);
        $path='/api/agent/v2/public/'.$f['slug'].'/catalog?kind=services&limit=1';
        $first=$this->request($path);$second=$this->request($path.'&cursor='.rawurlencode($first['data']['next_cursor']));
        self::assertNotSame($first['data']['items'][0]['id'],$second['data']['items'][0]['id']);
        $f=$this->fixtures('event');$args=['event_id'=>$f['offering'],'limit'=>1];
        $body=['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>'availability_search','arguments'=>$args]];
        $first=$this->request('/mcp/public/'.$f['slug'],body:$body);
        $body['params']['arguments']['cursor']=$first['result']['structuredContent']['data']['next_cursor'];
        $second=$this->request('/mcp/public/'.$f['slug'],body:$body);
        self::assertFalse($second['result']['isError']??true,json_encode($second));
        self::assertNotSame($first['result']['structuredContent']['data']['choices'][0]['date'],$second['result']['structuredContent']['data']['choices'][0]['date']);
    }
    public function testPublishedAppointmentExampleSessionPreviewReviewCommitAndRetry():void
    {
        $f=$this->fixtures('timeslot');$path='/api/agent/v2/public/'.$f['slug'];
        $session=$this->request($path.'/session',body:[]);
        $input=['service_id'=>$f['offering'],'date'=>$f['date'],'time'=>'11:00','customer'=>['name'=>'Guide example','email'=>'guide@example.test'],'consent_given'=>true];
        $preview=$this->request($path.'/bookings/preview',body:$input,continuation:$session['continuation']);
        $id=basename(parse_url($preview['review_url'],PHP_URL_PATH));$store=new PreviewStore();
        $store->exchangeBrowserHandle($id,$preview['preview_handle']);$store->approveCustomerBrowser($id,true);
        $commit=['preview_handle'=>$preview['preview_handle']];$key='guide-'.Ulid::generate();
        $result=$this->request($path.'/bookings',body:$commit,continuation:$session['continuation'],idempotencyKey:$key);
        (new SchemaValidator())->validate($this->registry->get('public_booking_create')->resultSchema(),$result,true);
        self::assertTrue($result['data']['consent_recorded']);
        self::assertSame($result,$this->request($path.'/bookings',body:$commit,continuation:$session['continuation'],idempotencyKey:$key));
        self::assertSame(1,(int)Database::query('SELECT COUNT(*) AS n FROM bookings WHERE tenant_id=? AND customer_email_snapshot=?',[$f['id'],'guide@example.test'])[0]['n']);
    }

    public static function daylightSavingStays():array
    {
        return ['spring forward'=>['March',71,'23:00:00','22:00:00'],'autumn back'=>['October',73,'22:00:00','23:00:00']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('daylightSavingStays')]
    public function testResourceStayCrossesDaylightSavingAndSeasonalPriceBoundary(string $month,int $elapsedHours,string $startUtcClock,string $endUtcClock):void
    {
        $f=$this->fixtures('resource');$business=$f['id'];$base=['business_id'=>$business];
        // Use next year's known Amsterdam transition to keep this case future-bookable
        // without depending on today's distance from the March or October boundary.
        $year=(int)gmdate('Y')+1;
        $transition=new \DateTimeImmutable('last Sunday of '.$month.' '.$year,new \DateTimeZone('Europe/Amsterdam'));
        $checkIn=$transition->modify('-1 day')->format('Y-m-d');
        $checkOut=$transition->modify('+2 days')->format('Y-m-d');
        Database::execute('UPDATE tenants SET max_advance_days=730 WHERE id=?',[$business]);
        Database::execute("INSERT INTO seasonal_pricing(id,tenant_id,resource_id,start_date,end_date,price_per_night,label) VALUES(?,?,?,?,?,70.35,'Transition season')",[Ulid::generate(),$business,$f['offering'],$transition->format('Y-m-d'),$transition->modify('+10 days')->format('Y-m-d')]);
        $input=$base+['resource_id'=>$f['offering'],'check_in'=>$checkIn,'check_out'=>$checkOut,'guest_count'=>2];
        $continuation=(new PublicContinuation())->issue($business);
        $public=['actor'=>(new PublicContinuation())->resolve($continuation['continuation'],$business),'continuation'=>$continuation['continuation'],'slug'=>$f['slug']];
        $quote=$this->invoke($public,'availability_search',$input)['data']['quote'];
        self::assertTrue($quote['available']);
        self::assertSame(3,$quote['nights']);
        self::assertSame($checkIn,$quote['check_in']);self::assertSame($checkOut,$quote['check_out']);
        self::assertSame([
            ['date'=>$checkIn,'price'=>'50.25','label'=>null],
            ['date'=>$transition->format('Y-m-d'),'price'=>'70.35','label'=>'Transition season'],
            ['date'=>$transition->modify('+1 day')->format('Y-m-d'),'price'=>'70.35','label'=>'Transition season'],
        ],$quote['pricing']);
        self::assertSame('190.95',$quote['total']);
        $created=$this->invoke($public,'public_booking_create',$input+['customer'=>['name'=>'Seasonal guest','email'=>'seasonal@example.test']]);
        self::assertSame('190.95',$created['data']['total']);self::assertSame(3,$created['data']['nights']);
        self::assertSame(2,$created['data']['guest_count']);self::assertSame(4,$quote['resource']['capacity']);
        self::assertSame($checkIn,$created['data']['check_in']);self::assertSame($checkOut,$created['data']['check_out']);
        self::assertSame('Europe/Amsterdam',$created['data']['timezone']);
        $utcStart=(new \DateTimeImmutable($checkIn))->modify('-1 day')->format('Y-m-d').'T'.$startUtcClock.'Z';
        $utcEnd=(new \DateTimeImmutable($checkOut))->modify('-1 day')->format('Y-m-d').'T'.$endUtcClock.'Z';
        self::assertSame($utcStart,$created['data']['start_utc']);self::assertSame($utcEnd,$created['data']['end_utc']);
        self::assertSame($elapsedHours,(int)((strtotime($utcEnd)-strtotime($utcStart))/3600));
        $stored=Database::query('SELECT start_datetime,end_datetime,price_total,price_currency FROM bookings WHERE id=?',[$created['data']['id']])[0];
        self::assertSame(['start_datetime'=>$checkIn.' 00:00:00','end_datetime'=>$checkOut.' 00:00:00','price_total'=>'190.95','price_currency'=>'EUR'],$stored);
        $read=$this->invoke($this->clients['staff'],'booking_get',$base+['booking_id'=>$created['data']['id']])['data'];
        self::assertSame($stored['start_datetime'],$read['start_datetime']);self::assertSame($stored['end_datetime'],$read['end_datetime']);
        self::assertSame('190.95',$read['price_total']);self::assertSame($utcStart,$read['start_utc']);self::assertSame($utcEnd,$read['end_utc']);
    }

    public static function changedBookingReviews():array
    {
        return ['service price'=>['price'],'cancellation policy'=>['cancellation_policy'],
            'cancellation disabled'=>['allow_cancellation'],'cancellation notice'=>['cancellation_hours_before'],
            'rescheduling disabled'=>['allow_rescheduling'],'rescheduling notice'=>['rescheduling_hours_before']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedBookingReviews')]
    public function testApprovedCreationPreviewRejectsChangedPriceOrCancellationPolicy(string $change):void
    {
        $f=$this->fixtures('timeslot');$actor=$this->clients['staff']['actor'];
        $input=['business_id'=>$f['id']]+$f['create']+['customer'=>['name'=>'Reviewed guest','email'=>'reviewed@example.test']];
        $preview=$this->executor->preview($actor,'booking_create',$input);
        $deadline=(new \DateTimeImmutable($f['date'].' '.$f['create']['time'],new \DateTimeZone('Europe/Amsterdam')))->format('Y-m-d H:i:s P');
        foreach(['customer_cancellation','customer_rescheduling'] as $policy)self::assertSame(['enabled'=>true,'eligible'=>true,'notice_hours'=>0,'deadline'=>$deadline],$preview['review'][$policy]);
        $id=basename(parse_url($preview['review_url'],PHP_URL_PATH));
        (new PreviewStore())->approve($this->reviewer,$id);
        if($change==='price')Database::execute('UPDATE services SET price=35.75 WHERE id=?',[$f['offering']]);
        elseif($change==='cancellation_policy')Database::execute("UPDATE tenants SET cancellation_policy='Cancel at least 48 hours before your booking.' WHERE id=?",[$f['id']]);
        else Database::execute('UPDATE tenants SET '.$change.'=? WHERE id=?',[str_starts_with($change,'allow_')?0:48,$f['id']]);
        try{
            $this->executor->execute($actor,'booking_create',['preview_handle'=>$preview['preview_handle']],'changed-'.Ulid::generate());
            self::fail('A changed consequential review was committed.');
        }catch(\App\Agent\OperationException $error){self::assertSame('preview_changed',$error->problemCode);self::assertSame(409,$error->status);}
        foreach(['bookings','customers','delivery_outbox','agent_operation_receipts'] as $table)self::assertSame(0,(int)Database::query('SELECT COUNT(*) AS n FROM '.$table.' WHERE tenant_id=?',[$f['id']])[0]['n'],$table.' must remain unchanged');
        self::assertNull(Database::query('SELECT consumed_at FROM agent_previews WHERE id=?',[$id])[0]['consumed_at']);
        $fresh=$this->invoke($this->clients['staff'],'booking_create',$input);
        self::assertSame($change==='price'?'35.75':'25.50',$fresh['data']['total']);
        self::assertSame(1,(int)Database::query('SELECT COUNT(*) AS n FROM bookings WHERE tenant_id=?',[$f['id']])[0]['n']);
    }

    public function testCommittedBookingSurvivesProviderFailureAndRetriesOneNoticePerAudience():void
    {
        $f=$this->fixtures('timeslot');$business=$f['id'];
        Database::execute('UPDATE tenants SET notify_on_booking=1 WHERE id=?',[$business]);
        // A customer's own booking notifies the customer and the business; a staff booking notifies no one.
        $continuation=(new PublicContinuation())->issue($business);$actor=(new PublicContinuation())->resolve($continuation['continuation'],$business);
        $input=['business_id'=>$business]+$f['create']+['customer'=>['name'=>'Delivery guest','email'=>'delivery@example.test']];
        $preview=$this->executor->preview($actor,'public_booking_create',$input);
        $reviewId=basename(parse_url($preview['review_url'],PHP_URL_PATH));$store=new PreviewStore();
        $store->exchangeBrowserHandle($reviewId,$preview['preview_handle']);$store->approveCustomerBrowser($reviewId,true);
        $commit=['preview_handle'=>$preview['preview_handle']];$key='delivery-'.Ulid::generate();
        $created=$this->executor->execute($actor,'public_booking_create',$commit,$key);$bookingId=$created['data']['id'];$receiptId=$created['receipt_id'];
        self::assertSame('queued',$created['notification_state']);
        self::assertFalse(Database::pdo()->inTransaction(),'Provider delivery must happen after the booking commit.');
        $intents=Database::query('SELECT id FROM delivery_outbox WHERE operation_receipt_id=?',[$receiptId]);
        self::assertCount(2,$intents,'Customer and staff each receive one durable notification.');
        $this->assertBookingAndReceiptCommitted($business,$bookingId,$receiptId,'queued',$actor,'public_operation_get');
        // Make only these two intents the oldest eligible work, without deleting or
        // changing any other scenario's delivery rows in the shared disposable run.
        Database::execute("UPDATE delivery_outbox SET available_at='2000-01-01' WHERE operation_receipt_id=?",[$receiptId]);
        \Tests\Support\TestEnvironment::failMail('Synthetic provider unavailable');
        try{$failed=\App\Engine\Outbox::dispatch(2);}finally{\Tests\Support\TestEnvironment::failMail(null);}
        self::assertSame(['delivered'=>0,'retrying'=>2,'failed'=>0],$failed);
        $this->assertBookingAndReceiptCommitted($business,$bookingId,$receiptId,'queued',$actor,'public_operation_get');
        $rows=Database::query('SELECT status,attempts,last_error_code FROM delivery_outbox WHERE operation_receipt_id=?',[$receiptId]);
        foreach($rows as $row){self::assertSame('pending',$row['status']);self::assertSame(1,(int)$row['attempts']);self::assertSame('delivery_failed',$row['last_error_code']);}
        self::assertSame($created,$this->executor->execute($actor,'public_booking_create',$commit,$key));
        self::assertCount(2,Database::query('SELECT id FROM delivery_outbox WHERE operation_receipt_id=?',[$receiptId]));
        Database::execute("UPDATE delivery_outbox SET available_at='2000-01-01' WHERE operation_receipt_id=?",[$receiptId]);
        self::assertSame(['delivered'=>2,'retrying'=>0,'failed'=>0],\App\Engine\Outbox::dispatch(2));
        $this->assertBookingAndReceiptCommitted($business,$bookingId,$receiptId,'delivered',$actor,'public_operation_get');
        self::assertSame($created,$this->executor->execute($actor,'public_booking_create',$commit,$key));
        \App\Engine\Outbox::dispatch(2); // Delivered rows cannot be selected again.
        $this->assertBookingAndReceiptCommitted($business,$bookingId,$receiptId,'delivered',$actor,'public_operation_get');
        $rows=Database::query('SELECT status,attempts,last_error_code FROM delivery_outbox WHERE operation_receipt_id=?',[$receiptId]);
        self::assertCount(2,$rows);
        foreach($rows as $row){self::assertSame('delivered',$row['status']);self::assertSame(2,(int)$row['attempts']);self::assertNull($row['last_error_code']);}
        $mailPath=\Tests\Support\TestEnvironment::manifest()['run_root'].'/mail.jsonl';
        $messages=array_map(static fn($line)=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file($mailPath,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
        $messages=array_values(array_filter($messages,static fn($message)=>$message['booking_id']===$bookingId));
        self::assertCount(4,$messages,'Two failed attempts and two successful attempts; replay adds none.');
        foreach(['confirmation','staff_notification'] as $type){
            $attempts=array_values(array_filter($messages,static fn($message)=>$message['type']===$type));
            self::assertCount(2,$attempts);self::assertNotNull($attempts[0]['error']);self::assertNull($attempts[1]['error']);
            self::assertNotNull($attempts[0]['message_id']);self::assertSame($attempts[0]['message_id'],$attempts[1]['message_id']);
        }
    }

    public static function changedPermissiveReschedulePolicies():array
    {
        return ['cancellation notice'=>['cancellation_hours_before'],'rescheduling notice'=>['rescheduling_hours_before']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedPermissiveReschedulePolicies')]
    public function testApprovedRescheduleRejectsPermissivePolicyChangeWithoutMovingBooking(string $field):void
    {
        $f=$this->fixtures('timeslot');$business=$f['id'];$base=['business_id'=>$business];
        $created=$this->invoke($this->clients['staff'],'booking_create',$base+$f['create']+['customer'=>['name'=>'Moving guest','email'=>'moving@example.test']]);
        $bookingId=$created['data']['id'];$customer=$this->customer($business,$bookingId,CustomerCapability::MANAGE);
        $input=$base+['target'=>$f['target']];
        $preview=$this->executor->preview($customer['actor'],'customer_booking_reschedule',$input);
        $id=basename(parse_url($preview['review_url'],PHP_URL_PATH));$store=new PreviewStore();
        $store->exchangeBrowserHandle($id,$preview['preview_handle']);$store->approveCustomerBrowser($id,true);
        $counts=[];
        foreach(['bookings','delivery_outbox','agent_operation_receipts'] as $table)$counts[$table]=(int)Database::query('SELECT COUNT(*) AS n FROM '.$table.' WHERE tenant_id=?',[$business])[0]['n'];
        Database::execute('UPDATE tenants SET '.$field.'=48 WHERE id=?',[$business]);
        // Both the original and replacement are weeks away: the new policy still
        // permits rescheduling, but the terms shown in the approved review changed.
        $current=Database::query('SELECT * FROM tenants WHERE id=?',[$business])[0];
        $original=Database::query('SELECT * FROM bookings WHERE id=?',[$bookingId])[0];
        self::assertTrue(\App\Engine\BookingService::canReschedule($original,$current)['allowed']);
        try{
            $this->executor->execute($customer['actor'],'customer_booking_reschedule',['preview_handle'=>$preview['preview_handle']],'changed-move-'.Ulid::generate());
            self::fail('A permissive policy change silently altered the reviewed replacement.');
        }catch(\App\Agent\OperationException $error){self::assertSame('preview_changed',$error->problemCode);self::assertSame(409,$error->status);}
        foreach($counts as $table=>$count)self::assertSame($count,(int)Database::query('SELECT COUNT(*) AS n FROM '.$table.' WHERE tenant_id=?',[$business])[0]['n']);
        self::assertSame(['status'=>'confirmed','rescheduled_to_id'=>null],Database::query('SELECT status,rescheduled_to_id FROM bookings WHERE id=?',[$bookingId])[0]);
        self::assertSame($bookingId,(new ConnectionAuthenticator())->refresh($customer['actor'])->bookingId);
        self::assertNull(Database::query('SELECT consumed_at FROM agent_previews WHERE id=?',[$id])[0]['consumed_at']);
        $fresh=$this->executor->preview($customer['actor'],'customer_booking_reschedule',$input);
        $policy=$field==='cancellation_hours_before'?'customer_cancellation':'customer_rescheduling';
        $newStart=new \DateTimeImmutable($f['target']['new_date'].' '.$f['target']['new_time'],new \DateTimeZone('Europe/Amsterdam'));
        self::assertSame(['enabled'=>true,'eligible'=>true,'notice_hours'=>48,'deadline'=>$newStart->setTimestamp($newStart->getTimestamp()-48*3600)->format('Y-m-d H:i:s P')],$fresh['review'][$policy]);
        $moved=$this->invoke($customer,'customer_booking_reschedule',$input)['data'];
        self::assertSame($newStart->format('Y-m-d H:i:s'),$moved['start_datetime']);
        self::assertSame($moved['id'],Database::query('SELECT rescheduled_to_id FROM bookings WHERE id=?',[$bookingId])[0]['rescheduled_to_id']);
    }

    /** Receipts are private to their principal, so read them as whoever committed the operation. */
    private function assertBookingAndReceiptCommitted(string $business,string $bookingId,string $receiptId,string $notificationState,?ActorContext $reader=null,string $readOperation='operation_get'):void
    {
        $stored=Database::query('SELECT id,status FROM bookings WHERE tenant_id=?',[$business]);
        self::assertSame([['id'=>$bookingId,'status'=>'confirmed']],$stored);
        self::assertSame(1,(int)Database::query('SELECT booking_count FROM customers WHERE tenant_id=?',[$business])[0]['booking_count']);
        $receipt=$this->executor->execute($reader??$this->clients['staff']['actor'],$readOperation,['receipt_id'=>$receiptId])['data'];
        self::assertSame('committed',$receipt['status']);self::assertSame($bookingId,$receipt['resource_id']);
        self::assertSame($notificationState,$receipt['notification_state']);self::assertTrue($receipt['replay_available']);
    }
}
