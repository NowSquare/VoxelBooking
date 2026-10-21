<?php

declare(strict_types=1);

namespace App\Application\Business;

use App\Agent\ActorContext;
use App\Agent\ConnectionAuthenticator;
use App\Agent\OperationException;
use App\Agent\PayloadCipher;
use App\Engine\AuditLog;
use App\Engine\Database;
use App\Engine\DemoMode;

final class BusinessAccess
{
    public static function actor(
        ActorContext $actor,
        string $scope,
        string $policy = 'operator',
        bool $write = false
    ): ActorContext {
        $actor = (new ConnectionAuthenticator())->refresh($actor);
        if (in_array($actor->principalType, ['operator', 'business_user'], true)) {
            $actor = (new \App\Agent\PrincipalResolver())->resolve(
                $actor->principalType,
                $actor->principalId,
                $actor->businessIds,
                $actor->scopes,
                connectionId: $actor->connectionId,
                credentialId: $actor->credentialId,
                authMode: $actor->authMode
            );
        } else {
            throw new OperationException('forbidden', 403);
        }
        $allowed = match ($policy) {
            'staff' => $actor->isStaff(),
            'owner' => in_array($actor->role, ['operator', 'business_owner'], true),
            default => $actor->role === 'operator',
        };
        if (!$allowed) {
            throw new OperationException('forbidden', 403);
        }
        if (!in_array($scope, $actor->scopes, true)) {
            throw new OperationException('insufficient_scope', 403);
        }
        if ($write && DemoMode::isActive()) {
            throw new OperationException('demo_restricted', 403);
        }

        return $actor;
    }

    public static function tenant(ActorContext $actor, array $input, bool $lock = false, bool $archived = false): array
    {
        $id = $actor->business($input['business_id'] ?? null);
        $tenant = Database::query(
            'SELECT * FROM tenants WHERE id = ?' . ($lock && !Database::isSQLite() ? ' FOR UPDATE' : ''),
            [$id]
        )[0] ?? null;
        if ($tenant === null || (!$archived && $tenant['status'] === 'archived')) {
            throw new OperationException('not_found', 404);
        }

        return $tenant;
    }

    public static function version(array $record): array
    {
        $record['version'] = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));
        return $record;
    }

    public static function check(array $record, array $input): void
    {
        if (
            !is_string($input['expected_version'] ?? null)
            || !hash_equals($record['version'], $input['expected_version'])
        ) {
            throw new OperationException('version_conflict', 409, nextAction: 'refresh');
        }
    }

    public static function invalid(string $field, string $code = 'invalid_value'): never
    {
        throw new OperationException('validation_failed', 422, [$field => $code]);
    }

    public static function audit(
        ActorContext $actor,
        string $action,
        string $entity,
        string $id,
        ?string $businessId = null
    ): void {
        AuditLog::log(
            $action,
            $entity,
            $id,
            ['connection_id' => $actor->connectionId],
            $businessId,
            $actor->auditType(),
            $actor->principalId
        );
    }

    /** Trusted callers supply table/where; cursors bind the entire authorized query. */
    public static function page(
        ActorContext $actor,
        string $table,
        string $where,
        array $bindings,
        array $input,
        callable $project
    ): array {
        $limit = max(1, min(100, (int) ($input['limit'] ?? 50)));
        $context = hash(
            'sha256',
            json_encode([
                $actor->principalType,
                $actor->principalId,
                $actor->businessIds,
                $table,
                $where,
                $bindings
            ], JSON_THROW_ON_ERROR)
        );
        $cursor = null;
        if (!empty($input['cursor'])) {
            try {
                $cursor = (new PayloadCipher())->decrypt($input['cursor']);
            } catch (\Throwable) {
                throw new OperationException('invalid_cursor', 422);
            }
            if (
                ($cursor['context'] ?? null) !== $context
                || ($cursor['expires'] ?? 0) < time()
                || !is_string($cursor['after'] ?? null)
                || !is_string($cursor['through'] ?? null)
            ) {
                throw new OperationException('invalid_cursor', 422);
            }
        }
        $through = $cursor['through']
            ?? (
                Database::query("SELECT MAX(id) AS last_id FROM `{$table}` WHERE {$where}", $bindings)[0]['last_id']
                    ?? ''
            );
        $rows = Database::query(
            "SELECT * FROM `{$table}` WHERE {$where} AND id > ? AND id <= ? ORDER BY id LIMIT " . ($limit + 1),
            [...$bindings, $cursor['after'] ?? '', $through]
        );
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return [
            'items' => array_map($project, $rows),
            'next_cursor' => $more
                ? (new PayloadCipher())->encrypt([
                    'context' => $context,
                    'after' => $rows[array_key_last($rows)]['id'],
                    'through' => $through,
                    'expires' => $cursor['expires'] ?? time() + 3600
                ])
                : null
        ];
    }
}
