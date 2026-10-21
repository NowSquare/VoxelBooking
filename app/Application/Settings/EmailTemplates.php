<?php

declare(strict_types=1);
namespace App\Application\Settings;

use App\Agent\{ActorContext,CanonicalJson,OperationException,SchemaValidator};
use App\Application\Shared\{Access,Contract};
use App\Engine\{AuditLog,Database,DemoMode,Ulid};

final class EmailTemplates
{
    public const TYPES=['confirmation','reminder','cancellation','reschedule_confirmation','approval_request','approval_confirmed'];
    public const FIELDS=['subject','heading','body_intro','body_outro','cta_label'];

    public static function fields(): array
    {
        return ['subject'=>Contract::text(255,true),'heading'=>Contract::text(255,true),
            'body_intro'=>Contract::text(10000,true),'body_outro'=>Contract::text(10000,true),
            'cta_label'=>Contract::text(255,true),'is_enabled'=>['type'=>'boolean']];
    }

    public static function read(ActorContext $actor,string $businessId): array
    {
        Access::tenant($actor,$businessId,'owner',scope:['business-settings:read','business-settings:manage']);
        $rows=Database::query('SELECT type,subject,heading,body_intro,body_outro,cta_label,is_enabled FROM tenant_email_templates WHERE tenant_id = ? ORDER BY type',[$businessId]);
        $indexed=array_column($rows,null,'type');$templates=[];
        foreach(self::TYPES as $type) {
            $row=$indexed[$type]??array_fill_keys(self::FIELDS,null);
            $templates[]=['type'=>$type,...array_intersect_key($row,array_flip(self::FIELDS)),'is_enabled'=>(bool)($row['is_enabled']??true)];
        }
        return ['business_id'=>$businessId,'templates'=>$templates,'version'=>CanonicalJson::hash($templates)];
    }

    public static function update(ActorContext $actor,array $input): array
    {
        if(DemoMode::isActive()) { throw new OperationException('demo_restricted',403); }
        return Access::transaction(static function()use($actor,$input):array {
            Access::tenant($actor,$input['business_id'],'owner',true,'business-settings:manage');
            $before=self::read($actor,$input['business_id']);
            if(!hash_equals($before['version'],$input['expected_version'])) { throw new OperationException('version_conflict',409,nextAction:'reread_record'); }
            foreach($input['templates'] as $change) {
                $type=$change['type'];
                if(!in_array($type,self::TYPES,true)) { throw new OperationException('validation_failed',422); }
                $values=$change;unset($values['type']);
                (new SchemaValidator())->validate(Contract::object(self::fields(),[]),$values);
                foreach($values as $field=>$value) {
                    if(is_string($value)) { $values[$field]=trim($value)?:null; }
                }
                if(isset($values['subject'])&&preg_match('/[\r\n\x00]/',$values['subject'])) { throw new OperationException('validation_failed',422,['subject'=>['single_line_required']]); }
                $existing=array_column($before['templates'],null,'type')[$type];unset($existing['type']);
                $merged=array_replace($existing,$values);
                Database::execute('INSERT INTO tenant_email_templates (id,tenant_id,type,subject,heading,body_intro,body_outro,cta_label,is_enabled) VALUES (?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE subject=VALUES(subject),heading=VALUES(heading),body_intro=VALUES(body_intro),body_outro=VALUES(body_outro),cta_label=VALUES(cta_label),is_enabled=VALUES(is_enabled)',
                    [Ulid::generate(),$input['business_id'],$type,...array_map(static fn($field)=>$merged[$field],self::FIELDS),(int)$merged['is_enabled']]);
            }
            AuditLog::log('tenant.emails_updated','tenant',$input['business_id'],['types'=>array_column($input['templates'],'type')],$input['business_id'],$actor->auditType(),$actor->principalId);
            return self::read($actor,$input['business_id']);
        });
    }

    public static function preview(ActorContext $actor,array $input): array
    {
        $templates=self::read($actor,$input['business_id']);
        $type=$input['type'];
        if(!in_array($type,self::TYPES,true)) { throw new OperationException('validation_failed',422); }
        $row=array_column($templates['templates'],null,'type')[$type];
        $prefix=match($type){'confirmation'=>'booking_confirmation','reminder'=>'booking_reminder',default=>$type};
        $sample=['{customer_name}'=>'Alex Example','{business_name}'=>'Example Studio','{service_name}'=>'Consultation',
            '{staff_name}'=>'Taylor Example','{booking_date}'=>'12 November 2026','{booking_time}'=>'10:30','{booking_end_time}'=>'11:00',
            '{duration}'=>'30','{booking_id}'=>'example-booking'];
        $defaults=['subject'=>__('email.'.$prefix.'.subject',['business'=>'Example Studio','service'=>'Consultation','date'=>'12 November 2026','time'=>'10:30']),
            'heading'=>__('email.'.$prefix.'.heading'),'body_intro'=>__('email.'.$prefix.'.body'),
            'body_outro'=>__('email.'.$prefix.'.footer'),'cta_label'=>__('email.common.manage_booking')];
        $rendered=[];
        foreach(self::FIELDS as $field) { $rendered[$field]=strtr($row[$field]??$defaults[$field],$sample); }
        return ['type'=>$type,'synthetic'=>true,'is_enabled'=>$row['is_enabled'],'content'=>$rendered];
    }
}
