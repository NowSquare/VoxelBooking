<?php

declare(strict_types=1);
namespace App\Agent;
use App\Engine\Database;
use App\Engine\Ulid;

final class OperationReceiptStore
{
    /** Must run inside the command transaction. The unique key serializes parallel retries. */
    public function acquire(ActorContext $actor, string $operation, ?string $businessId, string $key, array $arguments): array
    {
        if (strlen($key) < 8 || strlen($key) > 128 || preg_match('/\A[\x21-\x7e]+\z/D', $key) !== 1) {
            throw new OperationException('idempotency_key_required', 422);
        }
        $customerCredential = $actor->authMode === 'customer' ? $actor->credentialId : null;
        $hash = CanonicalJson::hash([$actor->principalType, $actor->principalId, $actor->connectionId, $customerCredential, $businessId, $operation, $key]);
        $digest = CanonicalJson::hash($arguments);
        $id = Ulid::generate();
        Database::execute('INSERT INTO agent_operation_receipts (id, dedupe_hash, request_hash, principal_type, principal_id, connection_id, credential_id, tenant_id, operation_id, created_at, replay_until) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE dedupe_hash = dedupe_hash', [$id, $hash, $digest, $actor->principalType, $actor->principalId, $actor->connectionId, $customerCredential, $businessId, $operation, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() + 86400)]);
        $row = Database::query('SELECT * FROM agent_operation_receipts WHERE dedupe_hash = ? FOR UPDATE', [$hash])[0];
        if (!hash_equals($row['request_hash'], $digest)) { throw new OperationException('idempotency_conflict', 409, nextAction: 'recover_operation'); }
        if ($row['id'] !== $id && ($row['result_cipher'] === null || strtotime($row['replay_until'] . ' UTC') <= time())) {
            throw new OperationException('replay_expired', 409, nextAction: 'inspect_receipt:' . $row['id']);
        }
        return ['id' => $row['id'], 'result' => $row['result_cipher'] === null ? null : (new PayloadCipher())->decrypt($row['result_cipher'])];
    }
    public function complete(string $id, array $result): void
    {
        Database::execute('UPDATE agent_operation_receipts SET result_cipher = ?, resource_id = ? WHERE id = ?', [(new PayloadCipher())->encrypt($result), $result['data']['id'] ?? null, $id]);
    }
    public function get(ActorContext $actor, string $id): array
    {
        $row = Database::query('SELECT * FROM agent_operation_receipts WHERE id = ? AND principal_type = ? AND principal_id = ?', [$id, $actor->principalType, $actor->principalId])[0] ?? null;
        if (!$row || $row['connection_id'] !== $actor->connectionId || ($actor->authMode === 'customer' && $row['credential_id'] !== $actor->credentialId)) { throw new OperationException('not_found', 404); }
        if ($row['tenant_id'] !== null) { $actor->business($row['tenant_id']); }
        return ['id' => $id, 'operation' => $row['operation_id'], 'business_id' => $row['tenant_id'], 'resource_id' => $row['resource_id'], 'status' => 'committed', 'created_at' => $row['created_at'], 'replay_available' => $row['result_cipher'] !== null && strtotime($row['replay_until'] . ' UTC') > time(),
            'notification_state'=>\App\Engine\Outbox::notificationState($id)];
    }

    public function recover(ActorContext $actor, string $operation, ?string $businessId, string $key): array
    {
        if($businessId!==null) { $actor->business($businessId); }
        $customerCredential=$actor->authMode==='customer'?$actor->credentialId:null;
        $hash=CanonicalJson::hash([$actor->principalType,$actor->principalId,$actor->connectionId,$customerCredential,$businessId,$operation,$key]);
        $row=Database::query('SELECT id FROM agent_operation_receipts WHERE dedupe_hash = ?',[$hash])[0]??null;
        if(!$row) { throw new OperationException('operation_not_found',404,nextAction:'retry_original_request'); }
        return $this->get($actor,$row['id']);
    }
}
