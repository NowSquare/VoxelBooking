<?php

declare(strict_types=1);
namespace App\Application\Media;
use App\Agent\{ActorContext,OperationDefinition};
final class MediaOperations
{
    public static function definitions():array
    {
        return [new OperationDefinition('media_upload_prepare',__('agent.media.title'),__('agent.media.description'),['type'=>'object','required'=>['business_id','purpose'],'properties'=>['business_id'=>['type'=>'string'],'purpose'=>['type'=>'string','enum'=>MediaService::PURPOSES]],'additionalProperties'=>false],['type'=>'object','required'=>['media_id','upload_url','upload_token','expires_at','max_bytes','field_name','allowed_types'],'properties'=>['media_id'=>['type'=>'string'],'upload_url'=>['type'=>'string','format'=>'uri'],'upload_token'=>['type'=>'string'],'expires_at'=>['type'=>'string','format'=>'date-time'],'max_bytes'=>['type'=>'integer'],'field_name'=>['type'=>'string'],'allowed_types'=>['type'=>'array','items'=>['type'=>'string']]],'additionalProperties'=>false],['media:manage'],'owner',false,false,'none',[],'settings-and-branding',['C21'],['A33'],'POST','/businesses/{business_id}/uploads',static fn(ActorContext $actor,array $input)=>MediaService::prepare($actor,$input['business_id'],$input['purpose']))];
    }
}
