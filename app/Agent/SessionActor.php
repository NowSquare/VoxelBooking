<?php

declare(strict_types=1);
namespace App\Agent;
use App\Engine\Auth;
use App\Engine\Database;

final class SessionActor
{
    public static function resolve(OperationRegistry $registry): ActorContext
    {
        Auth::startSession();
        if (!Auth::check()) { throw new OperationException('unauthorized', 401, nextAction: 'sign_in'); }
        $type = $_SESSION['auth_type'] ?? '';
        $id = $_SESSION['auth_id'] ?? '';
        $businesses = $type === 'operator'
            ? array_column(Database::query("SELECT id FROM tenants"), 'id')
            : [$_SESSION['auth_tenant_id'] ?? ''];
        $scopes = [];
        foreach ($registry->all() as $operation) { $scopes = [...$scopes, ...$operation->requiredScopes]; }
        // Ignore impersonation: audit always identifies the actual authenticated person.
        return (new PrincipalResolver())->resolve($type, $id, $businesses, array_values(array_unique($scopes)), authMode: 'session');
    }
}
