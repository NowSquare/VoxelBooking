<?php

declare(strict_types=1);

namespace App\Agent;

use App\Engine\Database;
use PDO;

final class ConnectionAuthenticator
{
    public function __construct(private ?PDO $db = null) {}

    public function authenticate(string $token, string $audience): ActorContext
    {
        if ($token === '' || strlen($token) > 4096) {
            throw new OperationException('unauthorized', 401);
        }
        $db = $this->db ?? Database::pdo();
        $query = $db->prepare(
            'SELECT c.*, k.id AS credential_id, k.audience, k.scopes AS credential_scopes, k.expires_at AS credential_expires_at, k.revoked_at AS credential_revoked_at FROM agent_credentials k JOIN agent_connections c ON c.id = k.connection_id WHERE k.token_hash = ?'
        );
        $query->execute([hash('sha256', $token)]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        // An unknown token learns nothing: it may belong to another installation or be a guess.
        if (!$row) {
            throw new OperationException('unauthorized', 401, nextAction: 'reconnect');
        }
        // The caller holds this token's secret, so telling it why the token stopped working
        // reveals nothing it does not own, and lets the client show the right recovery.
        // Both the connection and this credential must be live, and the token must be presented to its own audience.
        if ($row['revoked_at'] !== null || $row['credential_revoked_at'] !== null) {
            throw new OperationException('connection_revoked', 401, nextAction: 'reconnect');
        }
        if (strtotime($row['expires_at'] . ' UTC') <= time() || strtotime($row['credential_expires_at'] . ' UTC') <= time()) {
            throw new OperationException('connection_expired', 401, nextAction: 'reconnect');
        }
        if (!hash_equals($row['audience'], $audience)) {
            throw new OperationException('wrong_audience', 401, nextAction: 'use_matching_endpoint');
        }
        $scopes = json_decode($row['scopes'], true, flags: JSON_THROW_ON_ERROR);
        // A credential may carry a narrower scope set than the connection it belongs to.
        if ($row['credential_scopes'] !== null) {
            $scopes = array_values(array_intersect(
                $scopes,
                json_decode($row['credential_scopes'], true, flags: JSON_THROW_ON_ERROR)
            ));
        }
        try {
            $actor = (new PrincipalResolver($db))->resolve(
                $row['principal_type'],
                $row['principal_id'],
                json_decode($row['business_ids'], true, flags: JSON_THROW_ON_ERROR),
                $scopes,
                $row['identity_hash'],
                $row['id'],
                $row['credential_id'],
                customerCapabilityId: $row['customer_capability_id'] ?? null
            );
        } catch (OperationException $refused) {
            throw $this->disabledAccount($db, $row) ?? $refused;
        }
        $db->prepare('UPDATE agent_connections SET last_used_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $row['id']]);
        return $actor;
    }

    /**
     * A deactivated team member reconnecting cannot help; the business owner must
     * reactivate them. Only the authentication boundary says so: a request already
     * in flight keeps the plain unauthorized answer it has always had.
     */
    private function disabledAccount(PDO $db, array $connection): ?OperationException
    {
        if ($connection['principal_type'] !== 'business_user') {
            return null;
        }
        $query = $db->prepare('SELECT is_active FROM business_users WHERE id = ?');
        $query->execute([$connection['principal_id']]);
        $active = $query->fetchColumn();

        return $active !== false && !(bool) $active
            ? new OperationException('account_disabled', 403, nextAction: 'contact_business_owner')
            : null;
    }

    public function refresh(ActorContext $actor): ActorContext
    {
        if ($actor->authMode === 'customer') {
            return \App\Engine\CustomerCapability::refreshActor($actor);
        }
        if ($actor->connectionId === null) {
            if ($actor->authMode === 'session' && $actor->isStaff()) {
                return (new PrincipalResolver($this->db))->resolve(
                    $actor->principalType,
                    $actor->principalId,
                    $actor->businessIds,
                    $actor->scopes,
                    authMode: 'session'
                );
            }
            return $actor;
        }
        $db = $this->db ?? Database::pdo();
        $query = $db->prepare('SELECT * FROM agent_connections WHERE id = ?');
        $query->execute([$actor->connectionId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['revoked_at'] !== null || strtotime($row['expires_at'] . ' UTC') <= time()
            || $row['principal_type'] !== $actor->principalType || $row['principal_id'] !== $actor->principalId) {
            throw new OperationException('unauthorized', 401, nextAction: 'reconnect');
        }
        $scopes = json_decode($row['scopes'], true, flags: JSON_THROW_ON_ERROR);
        if ($actor->credentialId !== null) {
            $query = $db->prepare(
                'SELECT expires_at, revoked_at, scopes FROM agent_credentials WHERE id = ? AND connection_id = ?'
            );
            $query->execute([$actor->credentialId, $actor->connectionId]);
            $credential = $query->fetch(PDO::FETCH_ASSOC);
            if (!$credential
                || $credential['revoked_at'] !== null
                || strtotime($credential['expires_at'] . ' UTC') <= time()) {
                throw new OperationException('unauthorized', 401, nextAction: 'reconnect');
            }
            if ($credential['scopes'] !== null) {
                $scopes = array_values(array_intersect(
                    $scopes,
                    json_decode($credential['scopes'], true, flags: JSON_THROW_ON_ERROR)
                ));
            }
        }
        return (new PrincipalResolver($db))->resolve(
            $row['principal_type'],
            $row['principal_id'],
            json_decode($row['business_ids'], true, flags: JSON_THROW_ON_ERROR),
            $scopes,
            $row['identity_hash'],
            $row['id'],
            $actor->credentialId,
            $actor->authMode,
            $row['customer_capability_id'] ?? null
        );
    }
}
