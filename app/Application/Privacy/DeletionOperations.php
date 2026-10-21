<?php

declare(strict_types=1);
namespace App\Application\Privacy;
use App\Agent\OperationDefinition;
use App\Application\Bookings\BookingOperations;
final class DeletionOperations
{
    public static function definitions():array
    {
        $o=BookingOperations::object(...);$s=['type'=>'string'];$n=['type'=>['string','null']];$id=['type'=>'string','maxLength'=>26];$selector=['business_id'=>$id,'customer_id'=>$id];
        $effect=[];foreach(['customers','bookings','email_logs','customer_capabilities','customer_connections','other_business_reviews','business_receipt_payloads','business_webhook_payloads','active_bookings'] as $key)$effect[$key]=['type'=>'integer'];
        $review=$o(['customer_id'=>$s,'business_id'=>$s,'customer_name'=>$s,'customer_email'=>$s,'requested_at'=>$s,'effects'=>$o($effect,array_keys($effect)),'retained'=>['type'=>'array','items'=>$s],'version'=>$s],['customer_id','business_id','customer_name','customer_email','requested_at','effects','retained','version']);
        $result=['customer_id'=>$s,'anonymized'=>['type'=>'boolean']];foreach(['bookings_affected','emails_affected','capabilities_revoked','connections_revoked','previews_removed','receipt_payloads_removed','webhook_payloads_removed','webhook_intents_suppressed'] as $key)$result[$key]=['type'=>'integer'];
        $item=$o(['id'=>$s,'business_id'=>$s,'name'=>$s,'email'=>$s,'deletion_requested_at'=>$s,'anonymized_at'=>$n,'booking_count'=>['type'=>'integer']],['id','business_id','name','email','deletion_requested_at','anonymized_at','booking_count']);
        $patterns=['timeslot','resource','capacity','event'];
        return [
            new OperationDefinition('deletion_queue_list',__('agent-booking.titles.deletion_queue_list'),__('agent-booking.descriptions.deletion_queue_list'),$o(['business_id'=>$id,'state'=>['type'=>'string','enum'=>['pending','processed']],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100],'cursor'=>['type'=>'string','maxLength'=>4096]],['business_id']),$o(['requests'=>['type'=>'array','items'=>$item],'next_cursor'=>['type'=>['string','null']]],['requests','next_cursor']),['privacy-review:read'],'operator',true,false,'none',$patterns,'customers-and-privacy',['C34'],['A02','A35','A36'],'GET','/businesses/{business_id}/deletion-requests',DeletionService::queue(...),allowArchivedBusiness:true),
            new OperationDefinition('deletion_review',__('agent-booking.titles.deletion_review'),__('agent-booking.descriptions.deletion_review'),$o($selector,['business_id','customer_id']),$review,['privacy-review:read'],'operator',true,false,'none',$patterns,'customers-and-privacy',['C34'],['A02','A35','A36'],'GET','/businesses/{business_id}/deletion-requests/{customer_id}',DeletionService::preview(...),allowArchivedBusiness:true),
            new OperationDefinition('deletion_confirm',__('agent-booking.titles.deletion_confirm'),__('agent-booking.descriptions.deletion_confirm'),$o($selector,['business_id','customer_id']),$o($result,array_keys($result)),['privacy-review:read','privacy-review:manage'],'operator',false,true,'trusted',$patterns,'customers-and-privacy',['C34'],['A16','A35','A36'],'POST','/businesses/{business_id}/deletion-requests/{customer_id}/confirm',DeletionService::confirm(...),previewHandler:DeletionService::preview(...),allowArchivedBusiness:true),
            new OperationDefinition('deletion_dismiss',__('agent-booking.titles.deletion_dismiss'),__('agent-booking.descriptions.deletion_dismiss'),$o($selector+['reason'=>['type'=>'string','maxLength'=>1000]],['business_id','customer_id']),$o(['customer_id'=>$s,'dismissed'=>['type'=>'boolean']],['customer_id','dismissed']),['privacy-review:read','privacy-review:manage'],'operator',false,false,'trusted',$patterns,'customers-and-privacy',['C34'],['A16','A35'],'POST','/businesses/{business_id}/deletion-requests/{customer_id}/dismiss',DeletionService::dismiss(...),previewHandler:DeletionService::preview(...),allowArchivedBusiness:true),
        ];
    }
}
