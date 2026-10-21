<?php

declare(strict_types=1);

namespace App\Agent;

use App\Engine\AuditLog;
use App\Engine\Database;
use App\Engine\Ulid;

final class ConnectionService
{
    public function __construct(private OperationRegistry $registry) {}

    /** Browser consent can delegate only the exact verified customer capability. */
    public function createCustomerGrant(
        ActorContext $actor,
        string $name,
        array $scopes,
        string $expiresAt,
        string $purpose,
        ?string $clientId = null
    ): array {
        if ($actor->authMode !== 'customer' || $actor->credentialId === null) {
            throw new OperationException('trusted_review_required', 403, nextAction: 'verify_customer');
        }
        $actor = \App\Engine\CustomerCapability::refreshActor($actor);
        $source = Database::query('SELECT expires_at FROM customer_capabilities WHERE id = ?', [$actor->credentialId])[0] ?? null;
        $scopes = array_values(array_unique($scopes));
        $expiry = strtotime($expiresAt . ' UTC');
        // The grant may narrow the customer capability but never outlive or widen it.
        if ($source === null || $scopes === [] || array_diff($scopes, $actor->scopes) !== []
            || $expiry === false || $expiry <= time() || $expiry > strtotime($source['expires_at'] . ' UTC')
            || trim($name) === '' || mb_strlen($name) > 120 || mb_strlen($purpose) > 500) {
            throw new OperationException('invalid_grant', 422);
        }
        $customer = Database::query(
            'SELECT email FROM customers WHERE id = ? AND tenant_id = ? AND is_anonymized = 0',
            [$actor->principalId, $actor->business()]
        )[0] ?? null;
        if ($customer === null) {
            throw new OperationException('unauthorized', 401);
        }
        $id = Ulid::generate();
        Database::execute('INSERT INTO agent_connections (id, principal_type, principal_id, identity_hash, customer_capability_id, name, scopes, business_ids, client_id, purpose, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $id,
            'customer',
            $actor->principalId,
            PrincipalResolver::identityHash($customer['email']),
            $actor->credentialId,
            trim($name),
            json_encode($scopes, JSON_THROW_ON_ERROR),
            json_encode($actor->businessIds, JSON_THROW_ON_ERROR),
            $clientId,
            trim($purpose),
            gmdate('Y-m-d H:i:s', $expiry),
            gmdate('Y-m-d H:i:s'),
        ]);
        AuditLog::log(
            'connection.created',
            'connection',
            $id,
            ['scopes' => $scopes, 'business_ids' => $actor->businessIds],
            actorType: 'customer',
            actorId: $actor->principalId
        );
        return [
            'id' => $id,
            'name' => trim($name),
            'scopes' => $scopes,
            'business_ids' => $actor->businessIds,
            'expires_at' => gmdate('Y-m-d H:i:s', $expiry)
        ];
    }

    /** Only the trusted browser can establish new delegation; an API cannot consent for itself. */
    public function createGrant(
        ActorContext $actor,
        string $name,
        array $scopes,
        array $businessIds,
        string $expiresAt,
        string $purpose,
        ?string $clientId = null
    ): array {
        $actor = (new ConnectionAuthenticator())->refresh($actor);
        if ($actor->authMode !== 'session' || !$actor->isStaff()) {
            throw new OperationException('trusted_review_required', 403, nextAction: 'open_connection_settings');
        }
        $allowed = [];
        foreach ($this->registry->visible($actor) as $operation) {
            $allowed = [...$allowed, ...$operation->requiredScopes];
        }
        $scopes = array_values(array_unique($scopes));
        $businessIds = array_values(array_unique($businessIds));
        if ($scopes === [] || array_diff($scopes, $allowed) !== [] || array_diff($businessIds, $actor->businessIds) !== []) {
            throw new OperationException('invalid_grant', 422);
        }
        $expiry = strtotime($expiresAt . ' UTC');
        if ($expiry === false || $expiry <= time() || $expiry > time() + 366 * 86400
            || trim($name) === '' || mb_strlen($name) > 120 || mb_strlen($purpose) > 500) {
            throw new OperationException('validation_failed', 422);
        }
        $table = $actor->principalType === 'operator' ? 'operators' : 'business_users';
        $principal = Database::query(
            'SELECT email'
                . ($actor->principalType === 'business_user' ? ',force_password_change' : '')
                . ' FROM ' . $table . ' WHERE id = ?',
            [$actor->principalId]
        )[0] ?? null;
        if ($principal === null) {
            throw new OperationException('unauthorized', 401);
        }
        // A temporary password can reach account recovery, but cannot consent to
        // delegation until the current database identity has completed it.
        if (!empty($principal['force_password_change'])) {
            throw new OperationException('identity_verification_required', 403, nextAction: 'sign_in');
        }
        $id = Ulid::generate();
        Database::execute('INSERT INTO agent_connections (id, principal_type, principal_id, identity_hash, name, scopes, business_ids, client_id, purpose, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $id,
            $actor->principalType,
            $actor->principalId,
            PrincipalResolver::identityHash($principal['email']),
            trim($name),
            json_encode($scopes, JSON_THROW_ON_ERROR),
            json_encode($businessIds, JSON_THROW_ON_ERROR),
            $clientId,
            trim($purpose),
            gmdate('Y-m-d H:i:s', $expiry),
            gmdate('Y-m-d H:i:s'),
        ]);
        AuditLog::log(
            'connection.created',
            'connection',
            $id,
            ['scopes' => $scopes, 'business_ids' => $businessIds],
            actorType: $actor->auditType(),
            actorId: $actor->principalId
        );
        return [
            'id' => $id,
            'name' => trim($name),
            'scopes' => $scopes,
            'business_ids' => $businessIds,
            'expires_at' => gmdate('Y-m-d H:i:s', $expiry)
        ];
    }

    /** Internal issuance after browser consent or a validated OAuth grant. Never exposed as a tool. */
    public function issueCredential(string $connectionId, string $audience, string $expiresAt, ?array $scopes = null): array
    {
        $connection = Database::query('SELECT * FROM agent_connections WHERE id = ?', [$connectionId])[0] ?? null;
        $expiry = strtotime($expiresAt . ' UTC');
        if ($connection === null || $connection['revoked_at'] !== null || $expiry === false || $expiry <= time()
            || $expiry > strtotime($connection['expires_at'] . ' UTC') || $audience === '' || strlen($audience) > 512) {
            throw new OperationException('invalid_grant', 400);
        }
        // Re-resolve the principal so a credential is never minted for an identity that has since lost access.
        (new PrincipalResolver())->resolve(
            $connection['principal_type'],
            $connection['principal_id'],
            json_decode($connection['business_ids'], true, flags: JSON_THROW_ON_ERROR),
            json_decode($connection['scopes'], true, flags: JSON_THROW_ON_ERROR),
            $connection['identity_hash'],
            customerCapabilityId: $connection['customer_capability_id'] ?? null
        );
        $grantedScopes = json_decode($connection['scopes'], true, flags: JSON_THROW_ON_ERROR);
        $scopes ??= $grantedScopes;
        if (array_diff($scopes, $grantedScopes) !== []) {
            throw new OperationException('invalid_grant', 400);
        }
        $raw = 'vbc_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = Ulid::generate();
        Database::execute('INSERT INTO agent_credentials (id, connection_id, token_hash, prefix, audience, scopes, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $id,
            $connectionId,
            hash('sha256', $raw),
            substr($raw, 0, 12),
            $audience,
            json_encode(array_values($scopes), JSON_THROW_ON_ERROR),
            gmdate('Y-m-d H:i:s', $expiry),
            gmdate('Y-m-d H:i:s')
        ]);
        return ['id' => $id, 'raw_token' => $raw];
    }

    public function revoke(ActorContext $actor, string $id): void
    {
        $actor = (new ConnectionAuthenticator())->refresh($actor);
        $connection = Database::query('SELECT principal_type, principal_id FROM agent_connections WHERE id = ?', [$id])[0] ?? null;
        if (!$connection
            || $connection['principal_type'] !== $actor->principalType
            || $connection['principal_id'] !== $actor->principalId) {
            throw new OperationException('not_found', 404);
        }
        Database::execute('UPDATE agent_connections SET revoked_at = COALESCE(revoked_at, ?) WHERE id = ?', [gmdate('Y-m-d H:i:s'), $id]);
        AuditLog::log('connection.revoked', 'connection', $id, [], actorType: $actor->auditType(), actorId: $actor->principalId);
    }

    /**
     * Bring a connection's end forward to a chosen time.
     *
     * The admin screen fixes expiry at creation and otherwise only revokes. A
     * connection can shorten its own life without ending it now, for example to
     * finish a task and then lapse. It can never extend itself, and its tokens
     * never outlive it.
     *
     * @return array{connection_id: string, previous_expires_at: string, expires_at: string}
     */
    public function expire(ActorContext $actor, string $id, string $expiresAt): array
    {
        return \App\Application\Shared\Access::transaction(function () use ($actor, $id, $expiresAt): array {
            $row = Database::query(
                'SELECT principal_type, principal_id, expires_at FROM agent_connections WHERE id = ? FOR UPDATE',
                [$id]
            )[0] ?? null;
            $actor = (new ConnectionAuthenticator())->refresh($actor);
            if (!$row || $row['principal_type'] !== $actor->principalType || $row['principal_id'] !== $actor->principalId) {
                throw new OperationException('not_found', 404);
            }
            $expiry = self::shortenedExpiry($expiresAt, $row['expires_at']);
            Database::execute('UPDATE agent_connections SET expires_at = ? WHERE id = ?', [$expiry, $id]);
            Database::execute(
                'UPDATE agent_credentials SET expires_at = LEAST(expires_at, ?) WHERE connection_id = ?',
                [$expiry, $id]
            );
            AuditLog::log(
                'connection.expiry_shortened',
                'connection',
                $id,
                ['previous_expires_at' => $row['expires_at'], 'expires_at' => $expiry],
                actorType: $actor->auditType(),
                actorId: $actor->principalId
            );

            return ['connection_id' => $id, 'previous_expires_at' => $row['expires_at'], 'expires_at' => $expiry];
        });
    }

    /**
     * The new expiry in storage format, refused unless it lies after now and no
     * later than the current expiry. Ending access now is connection_revoke's job.
     */
    public static function shortenedExpiry(string $requested, string $current): string
    {
        try {
            $time = (new \DateTimeImmutable($requested))->getTimestamp();
        } catch (\Exception) {
            throw new OperationException('validation_failed', 422, ['expires_at' => 'invalid']);
        }
        if ($time <= time()) {
            throw new OperationException('validation_failed', 422, ['expires_at' => 'must_be_future'], nextAction: 'revoke_connection');
        }
        if ($time > strtotime($current . ' UTC')) {
            throw new OperationException('validation_failed', 422, ['expires_at' => 'cannot_extend'], nextAction: 'open_connection_settings');
        }

        return gmdate('Y-m-d H:i:s', $time);
    }

    public function rotate(ActorContext $actor, string $id, string $audience): array
    {
        $actor = (new ConnectionAuthenticator())->refresh($actor);
        if ($actor->authMode !== 'session' || !$actor->isStaff()) {
            throw new OperationException('trusted_review_required', 403);
        }
        return Database::transaction(function () use ($actor, $id, $audience): array {
            $row = Database::query('SELECT * FROM agent_connections WHERE id = ? FOR UPDATE', [$id])[0] ?? null;
            // OAuth-issued connections rotate through the token endpoint, not here.
            if (!$row || $row['principal_type'] !== $actor->principalType || $row['principal_id'] !== $actor->principalId
                || $row['client_id'] !== null) {
                throw new OperationException('not_found', 404);
            }
            $credential = $this->issueCredential($id, $audience, $row['expires_at']);
            Database::execute(
                'UPDATE agent_credentials SET revoked_at = COALESCE(revoked_at, ?) WHERE connection_id = ? AND id != ?',
                [gmdate('Y-m-d H:i:s'), $id, $credential['id']]
            );
            AuditLog::log('connection.rotated', 'connection', $id, [], actorType: $actor->auditType(), actorId: $actor->principalId);
            return $credential;
        });
    }

    public function page(ActorContext $actor, ?string $cursor = null): array
    {
        $actor = (new ConnectionAuthenticator())->refresh($actor);
        $context = \App\Application\Shared\Cursor::context($actor, 'account_connections', []);
        $page = \App\Application\Shared\Cursor::read($cursor, $context);
        // Pin the first page's upper bound so later pages stay stable while new connections arrive.
        $through = $page['through'] ?? (Database::query(
            'SELECT MAX(id) AS id FROM agent_connections WHERE principal_type=? AND principal_id=?',
            [$actor->principalType, $actor->principalId]
        )[0]['id'] ?? '');
        $rows = Database::query(
            'SELECT id, name, scopes, business_ids, client_id, purpose, expires_at, revoked_at, created_at, last_used_at FROM agent_connections WHERE principal_type = ? AND principal_id = ? AND id<=?'
                . ($page ? ' AND id<?' : '')
                . ' ORDER BY id DESC LIMIT 21',
            [$actor->principalType, $actor->principalId, $through, ...($page ? [$page['after']] : [])]
        );
        $more = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);
        foreach ($rows as &$row) {
            $row['scopes'] = json_decode($row['scopes'], true, flags: JSON_THROW_ON_ERROR);
            $row['business_ids'] = json_decode($row['business_ids'], true, flags: JSON_THROW_ON_ERROR);
            $row['state'] = $row['revoked_at'] !== null
                ? 'revoked'
                : (strtotime($row['expires_at'] . ' UTC') <= time() ? 'expired' : 'active');
        }
        unset($row);
        return [
            'items' => $rows,
            'next_cursor' => $more ? \App\Application\Shared\Cursor::next($context, end($rows)['id'], $through, $page) : null
        ];
    }
}
