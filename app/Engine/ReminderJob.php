<?php

declare(strict_types=1);
namespace App\Engine;

/** Due reminders enqueue once. Delivery retries share the durable outbox policy. */
final class ReminderJob
{
    public static function run():array
    {
        $queued=0;
        Database::transaction(static function()use(&$queued):void{
            // Historical scheduled_at values are tenant-local wall times. Match
            // them to that timezone instead of the host/database timezone.
            $clauses=[];$bindings=[];
            foreach(Database::query("SELECT DISTINCT timezone FROM tenants WHERE status!='archived'") as $zone){
                $timezone=new \DateTimeZone($zone['timezone']);
                $clauses[]='(t.timezone=? AND r.scheduled_at<=?)';
                array_push($bindings,$zone['timezone'],(new \DateTimeImmutable('now',$timezone))->format('Y-m-d H:i:s'));
            }
            if($clauses===[]){return;}
            $rows=Database::query("SELECT r.id,r.booking_id,r.tenant_id FROM reminders r JOIN tenants t ON t.id=r.tenant_id WHERE r.sent_at IS NULL AND t.status!='archived' AND (".implode(' OR ',$clauses).") AND r.attempts<8 AND NOT EXISTS (SELECT 1 FROM delivery_outbox d WHERE d.dedupe_hash=SHA2(CONCAT('booking.reminder',CHAR(0),r.tenant_id,CHAR(0),r.id),256)) ORDER BY r.scheduled_at,r.id LIMIT 20 FOR UPDATE SKIP LOCKED",$bindings);
            foreach($rows as $row){
                Outbox::enqueue('booking.reminder',['reminder_id'=>$row['id'],'booking_id'=>$row['booking_id'],'tenant_id'=>$row['tenant_id'],'demo_suppressed'=>DemoMode::isActive()],$row['tenant_id'],$row['id']);
                $queued++;
            }
        });
        return ['queued'=>$queued,'sent'=>0,'skipped'=>0,'failed'=>0,'errors'=>[]];
    }
    public static function deliver(array $payload):array
    {
        $tenantId=(string)($payload['tenant_id']??'');$bookingId=(string)($payload['booking_id']??'');
        $reminder=Database::query('SELECT * FROM reminders WHERE id=? AND booking_id=? AND tenant_id=?',[(string)($payload['reminder_id']??''),$bookingId,$tenantId])[0]??null;
        if($reminder===null||$reminder['sent_at']!==null){return ['sent'=>true,'skipped'=>true];}
        if(!empty($payload['demo_suppressed'])||DemoMode::isActive()){
            self::markSent($reminder['id']);return ['sent'=>false,'demo_suppressed'=>true];
        }
        $tenant=Database::query("SELECT * FROM tenants WHERE id=? AND status!='archived'",[$tenantId])[0]??null;
        $booking=BookingService::findByIdWithDetails($bookingId,$tenantId);
        $customer=$booking!==null?Database::query('SELECT is_anonymized FROM customers WHERE id=? AND tenant_id=?',[$booking['customer_id'],$tenantId])[0]??null:null;
        if($tenant===null||$booking===null||$customer===null||(bool)$customer['is_anonymized']||$booking['status']!=='confirmed'||!(bool)$tenant['send_reminders']||empty($booking['customer_email'])){
            self::markSent($reminder['id']);return ['sent'=>true,'skipped'=>true];
        }
        Locale::resolveForBooking($tenant,null);
        $zone=new \DateTimeZone($tenant['timezone']);$start=new \DateTimeImmutable($booking['start_datetime'],$zone);$end=new \DateTimeImmutable($booking['end_datetime'],$zone);
        if($start<=new \DateTimeImmutable('now',$zone)){self::markSent($reminder['id']);return ['sent'=>true,'skipped'=>true];}
        $result=Mailer::sendReminder($booking['customer_email'],$booking['customer_name'],['date'=>$start->format('Y-m-d'),'formatted_date'=>Locale::dateLong($start),'time'=>$start->format('H:i'),'end_time'=>$end->format('H:i')],
            $booking['service_name']??$booking['resource_name']??$booking['event_name']??null,$booking['staff_name']??null,$tenant['name'],$tenantId,$bookingId,$tenant['brand_color']??'#2563EB',$tenant['slug']);
        if(!empty($result['sent'])||!empty($result['skipped'])||!empty($result['demo_suppressed'])||!empty($result['logged'])){self::markSent($reminder['id']);}
        else{Database::execute("UPDATE reminders SET attempts=attempts+1,last_error='delivery_failed' WHERE id=?",[$reminder['id']]);}
        return $result;
    }
    private static function markSent(string $id):void
    {
        Database::execute('UPDATE reminders SET sent_at=UTC_TIMESTAMP(),last_error=NULL WHERE id=?',[$id]);
    }
}
