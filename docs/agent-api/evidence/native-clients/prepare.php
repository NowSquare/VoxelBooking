<?php
$root=realpath($argv[1]);
putenv('VOXELBOOKING_TEST_MANIFEST='.$root.'/manifest.json');
require $root.'/app/tests/bootstrap.php';
App\Engine\Locale::init($root.'/app');
Tests\Integration\TestFixtures::provision();
use App\Engine\Database;
use Tests\Integration\TestFixtures as F;
$tenant=F::BUSINESS_TENANT_ID;
$date=(new DateTimeImmutable('+5 days'))->format('Y-m-d');
Database::execute('UPDATE tenants SET requires_consent=0,min_advance_hours=0,max_advance_days=365,booking_requires_approval=0,max_bookings_per_customer_per_day=10 WHERE id=?',[$tenant]);
Database::execute("INSERT INTO services(id,tenant_id,name,duration_minutes,requires_staff,price) VALUES('client-service',?,'Client test consultation',30,0,25.00)",[$tenant]);
for($day=0;$day<7;$day++) Database::execute("INSERT INTO availability(id,tenant_id,day_of_week,start_time,end_time) VALUES(?,?,?,'08:00','18:00')",['client-hours-'.$day,$tenant,$day]);
$scopes=['bookings:read','bookings:write','services:read'];
$actor=new App\Agent\ActorContext('business_user',F::BUSINESS_USER_ID,'business_owner',[$tenant],$scopes,authMode:'session');
$service=new App\Agent\ConnectionService(App\Agent\Operations::registry());
$expiry=gmdate('Y-m-d H:i:s',time()+7200);
$dir=$root.'/clients';mkdir($dir,0700);
$result=['base'=>$_ENV['APP_TEST_URL'],'business_id'=>$tenant,'service_id'=>'client-service','date'=>$date,'clients'=>[]];
foreach(['codex','claude'] as $client){
 $grant=$service->createGrant($actor,'Disposable '.$client.' client',$scopes,[$tenant],$expiry,'Native client integration test');
 $key=$service->issueCredential($grant['id'],App\Agent\Endpoints::resource('mcp'),$expiry);
 $result['clients'][$client]=['connection_id'=>$grant['id'],'token'=>$key['raw_token']];
 if($client==='claude')file_put_contents($dir.'/claude-mcp.json',json_encode(['mcpServers'=>['voxelbooking'=>['type'=>'http','url'=>App\Agent\Endpoints::resource('mcp'),'headers'=>['Authorization'=>'Bearer '.$key['raw_token']]]]],JSON_PRETTY_PRINT));
}
file_put_contents($dir.'/fixture.json',json_encode($result,JSON_PRETTY_PRINT));
chmod($dir.'/fixture.json',0600);chmod($dir.'/claude-mcp.json',0600);
echo "Prepared isolated client fixture at $dir; date $date. Credentials stay in private files.\n";
