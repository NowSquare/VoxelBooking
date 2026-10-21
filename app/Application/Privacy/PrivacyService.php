<?php

declare(strict_types=1);
namespace App\Application\Privacy;
use App\Agent\{ActorContext,CanonicalJson,OperationException,PayloadCipher};
use App\Application\Bookings\{BookingCommands,BookingTransaction};
use App\Engine\{AuditLog,CustomerCapability,Database,DataExporter,DemoMode,Mailer,Outbox,Ulid};

final class PrivacyService
{
    private static function customer(ActorContext $actor,array $input):array
    {
        $actor = BookingCommands::currentActor($actor);
        if($actor->role!=='verified_customer'||$actor->bookingId!==null||$actor->customerId!==$actor->principalId)throw new OperationException('forbidden',403);
        $business=$actor->business($input['business_id']??null);
        return Database::query("SELECT c.* FROM customers c JOIN tenants t ON t.id=c.tenant_id WHERE c.id=? AND c.tenant_id=? AND c.is_anonymized=0 AND t.status='active'",[$actor->customerId,$business])[0]??throw new OperationException('not_found',404);
    }
    public static function summary(ActorContext $actor,array $input):array
    {
        $actor = BookingCommands::currentActor($actor);
        if(!in_array('privacy:read',$actor->scopes,true))throw new OperationException('forbidden',403);
        return self::snapshot($actor,$input);
    }
    public static function records(ActorContext $actor,array $input):array
    {
        $actor=BookingCommands::currentActor($actor);
        if(!in_array('privacy:read',$actor->scopes,true))throw new OperationException('forbidden',403);
        $customer=self::customer($actor,$input);$limit=max(1,min(100,(int)($input['limit']??50)));
        $binding=CanonicalJson::hash([$customer['tenant_id'],$customer['id'],$actor->connectionId,$actor->credentialId]);
        $snapshot=Database::query('SELECT MAX(id) AS id FROM bookings WHERE tenant_id=? AND customer_id=?',[$customer['tenant_id'],$customer['id']])[0]['id']??'';$after=null;
        if(!empty($input['cursor'])){
            try{$cursor=(new PayloadCipher())->decrypt($input['cursor']);}catch(\Throwable){throw new OperationException('invalid_cursor',422);}
            if(($cursor['binding']??null)!==$binding||($cursor['expires']??0)<time())throw new OperationException('invalid_cursor',422);
            $snapshot=$cursor['snapshot'];$after=$cursor['after'];
        }
        $where='tenant_id=? AND customer_id=? AND id<=?';$args=[$customer['tenant_id'],$customer['id'],$snapshot];
        if($after!==null){$where.=' AND (start_datetime<? OR (start_datetime=? AND id<?))';array_push($args,$after[0],$after[0],$after[1]);}
        $rows=Database::query('SELECT id,booking_pattern,start_datetime,end_datetime,party_size,status,source,consent_given_at,consent_text_shown FROM bookings WHERE '.$where.' ORDER BY start_datetime DESC,id DESC LIMIT ?',[...$args,$limit+1]);
        $more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);
        $last=$rows?end($rows):null;$next=$more?(new PayloadCipher())->encrypt(['binding'=>$binding,'snapshot'=>$snapshot,'after'=>[$last['start_datetime'],$last['id']],'expires'=>time()+3600]):null;
        $timezone=Database::query('SELECT timezone FROM tenants WHERE id=?',[$customer['tenant_id']])[0]['timezone'];
        foreach($rows as &$row){$row['party_size']=(int)$row['party_size'];$row+=\App\Engine\VenueDateTime::utcRange($row['start_datetime'],$row['end_datetime'],$timezone);}unset($row);
        return ['bookings'=>$rows,'next_cursor'=>$next,'timezone'=>$timezone];
    }
    private static function snapshot(ActorContext $actor,array $input):array
    {
        $actor = BookingCommands::currentActor($actor);
        $c=self::customer($actor,$input);
        $count=Database::query('SELECT COUNT(*) AS bookings,SUM(consent_given_at IS NOT NULL) AS consents FROM bookings WHERE tenant_id=? AND customer_id=?',[$c['tenant_id'],$c['id']])[0];
        return ['business_id'=>$c['tenant_id'],'customer'=>['name'=>$c['name'],'email'=>$c['email'],'phone'=>$c['phone'],'created_at'=>$c['created_at']],'booking_count'=>(int)$count['bookings'],'consent_record_count'=>(int)$count['consents'],'deletion_status'=>$c['deletion_requested_at']===null?'not_requested':'pending_review','deletion_requested_at'=>$c['deletion_requested_at']];
    }
    public static function export(ActorContext $actor,array $input):array
    {
        $actor = BookingCommands::currentActor($actor);
        if(!in_array('privacy:export',$actor->scopes,true))throw new OperationException('forbidden',403);
        return BookingTransaction::run($actor->business($input['business_id']??null),static function($tenant)use($actor,$input){
            $actor=BookingCommands::currentActor($actor);
            if(!in_array('privacy:export',$actor->scopes,true))throw new OperationException('forbidden',403);
            $c=self::customer($actor,$input);$data=DataExporter::export($c['id']);
            AuditLog::log('privacy.data_exported','customer',$c['id'],['connection_id'=>$actor->connectionId],$tenant['id'],actorType:'customer',actorId:$c['id']);
            Outbox::enqueue('customer.privacy_notification',['customer_id'=>$c['id'],'tenant_id'=>$tenant['id'],'kind'=>'export','audience'=>'customer'],$tenant['id'],'export:'.Ulid::generate());
            return $data;
        });
    }
    public static function requestDeletion(ActorContext $actor,array $input):array
    {
        $actor = BookingCommands::currentActor($actor);
        if(!in_array('privacy:request',$actor->scopes,true))throw new OperationException('forbidden',403);
        if(DemoMode::isActive())throw new OperationException('demo_restricted',403);
        return BookingTransaction::run($actor->business($input['business_id']??null),static function($tenant)use($actor,$input){
            $actor=BookingCommands::currentActor($actor);
            if(!in_array('privacy:request',$actor->scopes,true))throw new OperationException('forbidden',403);
            $c=self::customer($actor,$input);
            $changed=Database::execute('UPDATE customers SET deletion_requested_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND tenant_id=? AND deletion_requested_at IS NULL',[$c['id'],$tenant['id']]);
            if($changed){
                AuditLog::log('privacy.deletion_requested','customer',$c['id'],['connection_id'=>$actor->connectionId],$tenant['id'],actorType:'customer',actorId:$c['id']);
                $event=Ulid::generate();foreach(['customer','operator'] as $audience)Outbox::enqueue('customer.privacy_notification',['customer_id'=>$c['id'],'tenant_id'=>$tenant['id'],'kind'=>'deletion','audience'=>$audience],$tenant['id'],$event.':'.$audience);
            }
            return self::snapshot($actor,$input);
        });
    }
    public static function preview(ActorContext $actor,array $input):array
    {
        $actor = BookingCommands::currentActor($actor);
        $summary=self::snapshot($actor,$input);
        return ['business_id'=>$summary['business_id'],'customer'=>$summary['customer'],'booking_count'=>$summary['booking_count'],'consent_record_count'=>$summary['consent_record_count'],'deletion_status'=>$summary['deletion_status'],'effect'=>'Requests operator review. No data is erased by this request.'];
    }
    public static function deliver(array $payload):array
    {
        $c=Database::query('SELECT * FROM customers WHERE id=? AND tenant_id=? AND is_anonymized=0',[$payload['customer_id']??'', $payload['tenant_id']??''])[0]??null;
        $t=Database::query("SELECT * FROM tenants WHERE id=? AND status!='archived'",[$payload['tenant_id']??''])[0]??null;
        if($c===null||$t===null)return ['sent'=>true,'suppressed'=>true];
        if(($payload['kind']??'')==='export')return Mailer::sendExportAcknowledgment($c['email'],$t['name'],$t['id']);
        if($c['deletion_requested_at']===null)return ['sent'=>true,'suppressed'=>true];
        if(($payload['audience']??'')==='operator')return Mailer::notifyOperatorDeletionRequest($t['notification_email']?:$t['email'],$c['name'],$c['email'],$t['name'],$t['id']);
        return Mailer::sendDeletionAcknowledgment($c['email'],$t['name'],$t['id']);
    }
}
