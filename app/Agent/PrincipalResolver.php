<?php

declare(strict_types=1);

namespace App\Agent;

use App\Engine\Database;
use PDO;

/** Re-reads the identity and business records; stored token roles are never authoritative. */
final class PrincipalResolver
{
    public function __construct(private ?PDO $db = null) {}

    public function resolve(
        string $type,
        string $id,
        array $businessIds,
        array $scopes,
        ?string $identityHash = null,
        ?string $connectionId = null,
        ?string $credentialId = null,
        string $authMode = 'bearer',
        ?string $customerCapabilityId = null
    ): ActorContext {
        $db = $this->db ?? Database::pdo();
        if ($type === 'customer') {
            // A customer principal is only as strong as the capability it was delegated from.
            $source = $customerCapabilityId === null ? null : \App\Engine\CustomerCapability::actorById($customerCapabilityId);
            if ($source === null || $source->principalId !== $id || array_diff($businessIds, $source->businessIds) !== []) {
                throw new OperationException('unauthorized', 401, nextAction: 'verify_customer');
            }
            $query = $db->prepare('SELECT email FROM customers WHERE id = ? AND tenant_id = ? AND is_anonymized = 0');
            $query->execute([$id, $source->business()]);
            $email = $query->fetchColumn();
            if (!is_string($email) || ($identityHash !== null && !hash_equals($identityHash, self::identityHash($email)))) {
                throw new OperationException('identity_changed', 401, nextAction: 'verify_customer');
            }
            return new ActorContext(
                'customer',
                $id,
                'verified_customer',
                $businessIds,
                array_values(array_intersect($scopes, $source->scopes)),
                $connectionId,
                $credentialId,
                $authMode,
                $source->bookingId,
                $source->customerId
            );
        }
        $table = match ($type) {
            'operator' => 'operators',
            'business_user' => 'business_users',
            default => throw new OperationException('unauthorized', 401),
        };
        $query = $db->prepare('SELECT * FROM ' . $table . ' WHERE id = ?');
        $query->execute([$id]);
        $principal = $query->fetch(PDO::FETCH_ASSOC);
        if (!$principal || ($type === 'business_user' && !(bool) $principal['is_active'])) {
            throw new OperationException('unauthorized', 401, nextAction: 'reconnect');
        }
        if ($authMode !== 'session' && !empty($principal['force_password_change'])) {
            throw new OperationException('identity_verification_required', 401, nextAction: 'sign_in');
        }
        if ($identityHash !== null && !hash_equals($identityHash, self::identityHash($principal['email']))) {
            throw new OperationException('identity_changed', 401, nextAction: 'reconnect');
        }
        if ($type === 'business_user') {
            $businessIds = array_values(array_intersect($businessIds, [$principal['tenant_id']]));
        }
        $ids = [];
        if ($businessIds !== []) {
            // Operators keep access to archived businesses; business users do not.
            $query = $db->prepare(
                'SELECT id FROM tenants WHERE '
                    . ($type === 'operator' ? '' : "status != 'archived' AND ")
                    . 'id IN (' . implode(',', array_fill(0, count($businessIds), '?')) . ')'
            );
            $query->execute($businessIds);
            $ids = $query->fetchAll(PDO::FETCH_COLUMN);
            sort($ids);
        }
        $role = $type === 'operator' ? 'operator' : match ($principal['role']) {
            'owner' => 'business_owner',
            'manager' => 'business_manager',
            default => throw new OperationException('unauthorized', 401),
        };
        return new ActorContext(
            $type,
            $id,
            $role,
            $ids,
            array_values(array_unique($scopes)),
            $connectionId,
            $credentialId,
            $authMode
        );
    }

    public static function identityHash(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }
}
