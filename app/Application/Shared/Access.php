<?php

declare(strict_types=1);
namespace App\Application\Shared;
use App\Agent\ActorContext;
use App\Agent\ConnectionAuthenticator;
use App\Agent\OperationException;
use App\Engine\Database;

final class Access
{
    public static function tenant(ActorContext $actor, string $id, string $minimumRole = 'staff', bool $lock = false, string|array|null $scope = null): array
    {
        // Acquire the domain lock before resolving authority: a role, connection
        // or business grant may change while this writer is waiting.
        $tenant = Database::query("SELECT * FROM tenants WHERE id = ? AND status != 'archived'" . ($lock ? ' FOR UPDATE' : ''), [$id])[0] ?? null;
        $actor = (new ConnectionAuthenticator())->refresh($actor);
        $actor->business($id);
        $allowed = match ($minimumRole) {
            'operator' => $actor->role === 'operator',
            'owner' => in_array($actor->role, ['operator','business_owner'], true),
            default => $actor->isStaff(),
        };
        if (!$allowed) { throw new OperationException('forbidden', 403); }
        if ($scope !== null && array_intersect((array)$scope, $actor->scopes) === []) { throw new OperationException('forbidden', 403); }
        if (!$tenant) { throw new OperationException('not_found', 404); }
        return $tenant;
    }
    public static function transaction(callable $callback): mixed
    {
        return Database::pdo()->inTransaction() ? $callback() : Database::transaction($callback);
    }
}
