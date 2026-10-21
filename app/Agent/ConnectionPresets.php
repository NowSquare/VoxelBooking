<?php

declare(strict_types=1);
namespace App\Agent;

/** Human-sized access choices, intersected with today's operations and role. */
final class ConnectionPresets
{
    public static function forActor(ActorContext $actor, OperationRegistry $registry): array
    {
        $choices = [
            'read'=>['account:read','bookings:read','availability:read','customers:read','businesses:read','reports:read'],
            'bookings'=>['account:read','bookings:read','bookings:write','bookings:export','availability:read','customers:read','customers:export','reports:read'],
            'business'=>['account:read','account:manage','business-settings:read','business-settings:manage','services:read','services:manage','staff:read','staff:manage','resources:read','resources:manage','capacity:read','capacity:manage','events:read','events:manage','availability:read','availability:manage','team:read','team:manage','media:manage','webhooks:read','webhooks:manage'],
            'operator'=>['account:read','account:manage','businesses:read','businesses:manage','applications:read','applications:manage','system:read','system:manage','audit:read','audit:export','jobs:read','jobs:run','privacy-review:read','privacy-review:manage'],
        ];
        $allowed = [];
        foreach ($registry->visible($actor) as $operation) { $allowed=[...$allowed,...$operation->requiredScopes]; }
        $presets=[];
        foreach($choices as $id=>$scopes) {
            $scopes=array_values(array_intersect($scopes,$allowed));
            if($scopes===[] || ($id==='operator' && $actor->role!=='operator')) { continue; }
            $presets[$id]=['title'=>__('agent.presets.'.$id.'.title'),'description'=>__('agent.presets.'.$id.'.description'),'scopes'=>$scopes];
        }
        return $presets;
    }
}
