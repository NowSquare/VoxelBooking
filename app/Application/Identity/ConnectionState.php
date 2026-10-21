<?php
declare(strict_types=1);
namespace App\Application\Identity;

use App\Agent\{ActorContext,ConnectionAuthenticator,OperationException};
use App\Application\Shared\Cursor;
use App\Engine\Database;

final class ConnectionState
{
    public static function read(ActorContext $actor): array
    {
        $actor=(new ConnectionAuthenticator())->refresh($actor);
        if($actor->connectionId===null) { throw new OperationException('not_found',404); }
        $row=Database::query('SELECT id,name,purpose,expires_at,created_at,last_used_at FROM agent_connections WHERE id=? AND principal_type=? AND principal_id=?',
            [$actor->connectionId,$actor->principalType,$actor->principalId])[0]??throw new OperationException('not_found',404);
        $credential=$actor->credentialId===null?null:(Database::query('SELECT expires_at FROM agent_credentials WHERE id=? AND connection_id=?',[$actor->credentialId,$actor->connectionId])[0]??null);
        return [...$row,'scopes'=>$actor->scopes,'business_ids'=>$actor->businessIds,'credential_expires_at'=>$credential['expires_at']??null];
    }
    public static function activity(ActorContext $actor,array $input): array
    {
        $actor=(new ConnectionAuthenticator())->refresh($actor);
        self::read($actor);
        return self::activityPage($actor,$actor->connectionId,$input);
    }
    /** A person can inspect their own history even after ending the connection. */
    public static function browserActivity(ActorContext $actor,string $connectionId,array $input):array
    {
        $actor=(new ConnectionAuthenticator())->refresh($actor);
        if($actor->authMode!=='session'||!$actor->isStaff()) { throw new OperationException('forbidden',403); }
        $connection=Database::query('SELECT id,name FROM agent_connections WHERE id=? AND principal_type=? AND principal_id=?',
            [$connectionId,$actor->principalType,$actor->principalId])[0]??throw new OperationException('not_found',404);
        return ['connection'=>$connection,...self::activityPage($actor,$connectionId,$input)];
    }
    private static function activityPage(ActorContext $actor,string $connectionId,array $input):array
    {
        $context=Cursor::context($actor,'connection_activity',['connection_id'=>$connectionId]);
        $cursor=Cursor::read($input['cursor']??null,$context);
        $limit=max(1,min(100,(int)($input['limit']??25)));
        $where="entity_type='operation' AND actor_type=? AND actor_id=? AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(details) THEN details ELSE '{}' END,'$.connection_id'))=?";
        $params=[$actor->auditType(),$actor->principalId,$connectionId];
        // Role/membership changes must also narrow historical business context.
        $where.=' AND (tenant_id IS NULL'.($actor->businessIds!==[]?' OR tenant_id IN ('.implode(',',array_fill(0,count($actor->businessIds),'?')).')':'').')';
        $params=[...$params,...$actor->businessIds];
        $through=$cursor['through']??(Database::query('SELECT MAX(id) AS id FROM audit_log WHERE '.$where,$params)[0]['id']??'');
        $rows=Database::query('SELECT id,tenant_id AS business_id,action,entity_id AS receipt_id,request_id,created_at FROM audit_log WHERE '.$where.
            ' AND id<=?'.($cursor?' AND id<?':'').' ORDER BY id DESC LIMIT '.($limit+1),
            [...$params,$through,...($cursor?[$cursor['after']]:[])]);
        $more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);
        return ['items'=>$rows,'next_cursor'=>$more?Cursor::next($context,end($rows)['id'],$through,$cursor):null];
    }
}
