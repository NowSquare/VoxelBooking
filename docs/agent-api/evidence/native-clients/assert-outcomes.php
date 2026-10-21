<?php
$root=realpath($argv[1]);
putenv('VOXELBOOKING_TEST_MANIFEST='.$root.'/manifest.json');require $root.'/app/tests/bootstrap.php';
use App\Engine\Database;
$fixture=json_decode(file_get_contents($root.'/clients/fixture.json'),true);
$evidence=[];
foreach($fixture['clients'] as $client=>$connection){
 $rows=Database::query('SELECT id,operation_id FROM agent_operation_receipts WHERE connection_id=? ORDER BY created_at,id',[$connection['connection_id']]);
 if(count($rows)!==3)throw new RuntimeException($client.' expected3 mutation receipts');
 $deliveries=[];
 foreach($rows as $row){$out=Database::query('SELECT topic,COUNT(*) AS total FROM delivery_outbox WHERE operation_receipt_id=? GROUP BY topic',[$row['id']]);$deliveries[]=['operation'=>$row['operation_id'],'receipt_id'=>$row['id'],'outbox'=>$out];}
 $bookings=Database::query('SELECT id,status,rescheduled_to_id,start_datetime,end_datetime FROM bookings WHERE customer_email_snapshot=? ORDER BY created_at,id',[$client.'-test@example.test']);
 if(count($bookings)!==2||array_column($bookings,'status')!==['rescheduled','cancelled'])throw new RuntimeException('Unexpected booking lifecycle');
 $revoked=Database::query('SELECT revoked_at FROM agent_connections WHERE id=?',[$connection['connection_id']])[0]['revoked_at'];
 if(!$revoked)throw new RuntimeException('Not revoked');
 $evidence[$client]=['receipts'=>$deliveries,'bookings'=>$bookings,'revoked'=>true];
}
file_put_contents($root.'/clients/database-evidence.json',json_encode($evidence,JSON_PRETTY_PRINT));
echo json_encode($evidence,JSON_PRETTY_PRINT)."\n";
