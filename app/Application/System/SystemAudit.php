<?php

declare(strict_types=1);
namespace App\Application\System;

use App\Agent\ActorContext;
use App\Application\Business\BusinessAccess as A;
use App\Application\Shared\Csv;
use App\Engine\Database;

/** An explicit event projection: raw details and network addresses never cross this boundary. */
final class SystemAudit
{
    public static function list(ActorContext $actor,array $input):array
    {
        $actor=A::actor($actor,'audit:read');
        [$where,$bindings]=self::filter($actor,$input);
        return A::page($actor,'audit_log',$where,$bindings,$input,self::project(...));
    }
    public static function browser(ActorContext $actor,array $input,int $page=1):array
    {
        $actor=A::actor($actor,'audit:read');[$where,$bindings]=self::filter($actor,$input);
        $total=(int)Database::query("SELECT COUNT(*) AS n FROM audit_log WHERE {$where}",$bindings)[0]['n'];
        $rows=Database::query("SELECT * FROM audit_log WHERE {$where} ORDER BY created_at DESC,id DESC LIMIT 50 OFFSET ".(max(0,$page-1)*50),$bindings);
        return ['entries'=>array_map(self::project(...),$rows),'total'=>$total];
    }
    public static function export(ActorContext $actor,array $input):array
    {
        $actor=A::actor($actor,'audit:export');[$where,$bindings]=self::filter($actor,$input);
        $rows=Database::query("SELECT * FROM audit_log WHERE {$where} ORDER BY created_at DESC,id DESC LIMIT 10001",$bindings);
        if(count($rows)>10000){throw new \App\Agent\OperationException('export_too_large',422,nextAction:'narrow_filters');}
        $records=array_map(self::project(...),$rows);
        return ['filename'=>'audit-'.gmdate('Y-m-d').'.csv','content_type'=>'text/csv; charset=UTF-8','row_count'=>count($records),'content'=>Csv::encode(['Date','Action','Business ID','Entity type','Entity ID','Principal type','Principal ID','Connection ID','Result','Correlation ID'],array_map(static fn(array $r):array=>[$r['created_at'],$r['action'],$r['business_id'],$r['entity_type'],$r['entity_id'],$r['actor_type'],$r['actor_id'],$r['connection_id'],$r['result'],$r['correlation_id']],$records))];
    }
    private static function filter(ActorContext $actor,array $input):array
    {
        $where=['(tenant_id IS NULL'.($actor->businessIds!==[]?' OR tenant_id IN ('.implode(',',array_fill(0,count($actor->businessIds),'?')).')':'').')'];$bindings=$actor->businessIds;
        if(!empty($input['business_filter'])){$business=$actor->business($input['business_filter']);$where[]='tenant_id=?';$bindings[]=$business;}
        foreach(['action'=>'action','principal_type'=>'actor_type','principal_id'=>'actor_id','entity_type'=>'entity_type','entity_id'=>'entity_id','correlation_id'=>'request_id'] as $field=>$column){
            if(isset($input[$field])&&$input[$field]!==''){if(!is_string($input[$field])||strlen($input[$field])>128){A::invalid($field);}$where[]="{$column}=?";$bindings[]=$input[$field];}
        }
        foreach(['from'=>'>=','until'=>'<'] as $field=>$operator){
            if(!empty($input[$field])){try{$date=new \DateTimeImmutable($input[$field]);}catch(\Throwable){A::invalid($field);}
                if(preg_match('/T.*(?:Z|[+-]\d{2}:\d{2})$/',$input[$field])!==1){A::invalid($field);}
                $where[]="created_at {$operator} ?";$bindings[]=$date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
        }
        if(!empty($input['connection_id'])){$where[]="JSON_UNQUOTE(JSON_EXTRACT(details,'$.connection_id'))=?";$bindings[]=$input['connection_id'];}
        if(!empty($input['result'])){$where[]="JSON_UNQUOTE(JSON_EXTRACT(details,'$.result'))=?";$bindings[]=$input['result'];}
        return [implode(' AND ',$where),$bindings];
    }
    private static function project(array $row):array
    {
        $details=json_decode($row['details']??'{}',true);$details=is_array($details)?$details:[];
        // Whitelist opaque correlations only; even legacy unredacted details remain inaccessible.
        $safe=static fn(mixed $v):?string=>is_string($v)&&preg_match('/\A[a-zA-Z0-9_.:-]{1,128}\z/D',$v)===1?$v:null;
        return ['id'=>$row['id'],'business_id'=>$row['tenant_id'],'action'=>$row['action'],'entity_type'=>$row['entity_type'],'entity_id'=>$row['entity_id'],'actor_type'=>$row['actor_type'],'actor_id'=>$row['actor_id'],
            'connection_id'=>$safe($details['connection_id']??null),'receipt_id'=>$safe($details['receipt_id']??null),'result'=>$safe($details['result']??null),'correlation_id'=>$row['request_id'],
            'created_at'=>str_replace(' ','T',$row['created_at']).'Z'];
    }
}
