<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Agent\{ActorContext, AuthorizationPolicy, ConnectionAuthenticator, Endpoints, OperationException, Operations, TransportContext};
use App\Application\Shared\Cursor;
use App\Engine\Database;

final class ConnectionState
{
    public static function read(ActorContext $actor): array
    {
        $actor = (new ConnectionAuthenticator())->refresh($actor);
        if ($actor->connectionId === null) {
            throw new OperationException('not_found', 404);
        }

        $row = Database::query(
            'SELECT id,name,purpose,expires_at,created_at,last_used_at FROM agent_connections WHERE id=? AND principal_type=? AND principal_id=?',
            [$actor->connectionId, $actor->principalType, $actor->principalId]
        )[0] ?? throw new OperationException('not_found', 404);
        $credential = $actor->credentialId === null
            ? null
            : (
                Database::query(
                    'SELECT expires_at FROM agent_credentials WHERE id=? AND connection_id=?',
                    [$actor->credentialId, $actor->connectionId]
                )[0] ?? null
            );
        return [
            ...$row,
            'scopes' => $actor->scopes,
            'business_ids' => $actor->businessIds,
            'credential_expires_at' => $credential['expires_at'] ?? null
        ];
    }

    /**
     * connection_test: a client's self-check.
     *
     * Reaching the handler already proves the token authenticated against this
     * endpoint. The answer adds what a client needs to explain its own state:
     * who it acts for, where, until when, and which actions its role allows but
     * its grant does not, so a person knows whether to reconnect with more access.
     */
    public static function test(ActorContext $actor): array
    {
        $actor = (new ConnectionAuthenticator())->refresh($actor);
        $connection = null;
        $transport = null;
        if ($actor->connectionId !== null) {
            $row = Database::query(
                'SELECT id,name,expires_at FROM agent_connections WHERE id=? AND principal_type=? AND principal_id=?',
                [$actor->connectionId, $actor->principalType, $actor->principalId]
            )[0] ?? throw new OperationException('not_found', 404);
            $credential = $actor->credentialId === null
                ? null
                : (
                    Database::query(
                        'SELECT expires_at,audience FROM agent_credentials WHERE id=? AND connection_id=?',
                        [$actor->credentialId, $actor->connectionId]
                    )[0] ?? null
                );
            $ends = strtotime($row['expires_at'] . ' UTC');
            if ($credential !== null) {
                $ends = min($ends, strtotime($credential['expires_at'] . ' UTC'));
                $transport = match ($credential['audience']) {
                    Endpoints::resource('mcp') => 'mcp',
                    Endpoints::resource('http') => 'http',
                    default => null,
                };
            }
            $connection = [
                'id' => $row['id'],
                'name' => $row['name'],
                'expires_at' => $row['expires_at'],
                'credential_expires_at' => $credential['expires_at'] ?? null,
                'expires_in_seconds' => max(0, $ends - time()),
            ];
        }

        return [
            'authenticated' => true,
            'principal' => ['type' => $actor->principalType, 'id' => $actor->principalId, 'role' => $actor->role],
            'business_ids' => $actor->businessIds,
            'scopes' => $actor->scopes,
            'auth_mode' => $actor->authMode,
            'connection' => $connection,
            'transport' => $transport,
            'protocol_version' => TransportContext::protocolVersion(),
            'scope_coverage' => self::coverage($actor),
            'discovery' => ['openapi_url' => Endpoints::resource('http') . '/schema', 'mcp_url' => Endpoints::resource('mcp')],
        ];
    }

    /**
     * Operations this caller can run, and those its role permits but its grant's
     * scopes do not. Public booking-page actions are left out: they need no grant.
     */
    private static function coverage(ActorContext $actor): array
    {
        $operations = array_values(array_filter(
            Operations::registry()->all(),
            static fn ($operation) => !str_starts_with($operation->httpPath, '/public/')
        ));
        $everyScope = [];
        foreach ($operations as $operation) {
            $everyScope = [...$everyScope, ...$operation->requiredScopes];
        }
        // The same person with every scope: what their role alone would allow.
        $unrestricted = new ActorContext(
            $actor->principalType,
            $actor->principalId,
            $actor->role,
            $actor->businessIds,
            array_values(array_unique($everyScope)),
            $actor->connectionId,
            $actor->credentialId,
            $actor->authMode,
            $actor->bookingId,
            $actor->customerId
        );
        $policy = new AuthorizationPolicy();
        $available = [];
        $missing = [];
        foreach ($operations as $operation) {
            if ($policy->permits($actor, $operation)) {
                $available[] = $operation->operationId;
            } elseif ($policy->permits($unrestricted, $operation)) {
                $missing[] = [
                    'operation' => $operation->operationId,
                    'missing_scopes' => array_values(array_diff($operation->requiredScopes, $actor->scopes)),
                ];
            }
        }

        return ['available' => $available, 'missing_scope' => $missing];
    }

    public static function activity(ActorContext $actor, array $input): array
    {
        $actor = (new ConnectionAuthenticator())->refresh($actor);
        self::read($actor);
        return self::activityPage($actor, $actor->connectionId, $input);
    }

    /** A person can inspect their own history even after ending the connection. */
    public static function browserActivity(ActorContext $actor, string $connectionId, array $input): array
    {
        $actor = (new ConnectionAuthenticator())->refresh($actor);
        if ($actor->authMode !== 'session' || !$actor->isStaff()) {
            throw new OperationException('forbidden', 403);
        }

        $connection = Database::query(
            'SELECT id,name FROM agent_connections WHERE id=? AND principal_type=? AND principal_id=?',
            [$connectionId, $actor->principalType, $actor->principalId]
        )[0] ?? throw new OperationException('not_found', 404);
        return ['connection' => $connection, ...self::activityPage($actor, $connectionId, $input)];
    }

    private static function activityPage(ActorContext $actor, string $connectionId, array $input): array
    {
        $context = Cursor::context($actor, 'connection_activity', ['connection_id' => $connectionId]);
        $cursor = Cursor::read($input['cursor'] ?? null, $context);
        $limit = max(1, min(100, (int) ($input['limit'] ?? 25)));
        $where = "entity_type='operation' AND actor_type=? AND actor_id=? AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(details) THEN details ELSE '{}' END,'$.connection_id'))=?";
        $params = [$actor->auditType(), $actor->principalId, $connectionId];
        // Role/membership changes must also narrow historical business context.
        $where .= ' AND (tenant_id IS NULL'
            . (
                $actor->businessIds !== []
                ? ' OR tenant_id IN (' . implode(',', array_fill(0, count($actor->businessIds), '?')) . ')'
                : ''
            ) . ')';
        $params = [...$params, ...$actor->businessIds];
        $through = $cursor['through']
            ?? (Database::query('SELECT MAX(id) AS id FROM audit_log WHERE ' . $where, $params)[0]['id'] ?? '');
        $rows = Database::query(
            'SELECT id,tenant_id AS business_id,action,entity_id AS receipt_id,request_id,created_at FROM audit_log WHERE '
                . $where . ' AND id<=?' . ($cursor ? ' AND id<?' : '') . ' ORDER BY id DESC LIMIT ' . ($limit + 1),
            [...$params, $through, ...($cursor ? [$cursor['after']] : [])]
        );
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return [
            'items' => $rows,
            'next_cursor' => $more ? Cursor::next($context, end($rows)['id'], $through, $cursor) : null
        ];
    }
}
