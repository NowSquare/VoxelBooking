<?php

declare(strict_types=1);

namespace App\Agent;

use App\Engine\AuditLog;
use App\Engine\Database;
use App\Engine\DemoMode;
use App\Engine\Ulid;

final class Executor
{
    /** Set only by PreviewStore when a person approves a review, never accepted from callers. */
    private const REVIEW_ONLY_FIELDS = ['consent_given' => true];

    public function __construct(private OperationRegistry $registry) {}

    public function execute(ActorContext $actor, string $operationId, array $arguments, ?string $idempotencyKey = null): array
    {
        $actor = (new ConnectionAuthenticator())->refresh($actor);
        $definition = $this->registry->get($operationId) ?? throw new OperationException('operation_not_found', 404);
        $previewHandle = $arguments['preview_handle'] ?? null;
        // The key may arrive as a header or an argument, but both must agree when both are present.
        if ($idempotencyKey !== null
            && isset($arguments['idempotency_key'])
            && $idempotencyKey !== $arguments['idempotency_key']) {
            throw new OperationException('idempotency_key_mismatch', 422);
        }
        $idempotencyKey ??= $arguments['idempotency_key'] ?? null;
        unset($arguments['preview_handle'], $arguments['idempotency_key']);
        if (!$definition->readOnly && $definition->confirmationPolicy !== 'none') {
            if (!is_string($previewHandle) || $previewHandle === '') {
                throw new OperationException('preview_required', 422, nextAction: 'preview_operation');
            }
            // A reviewed commit replays the stored payload, so extra arguments could only smuggle unreviewed input.
            if ($arguments !== []) {
                throw new OperationException('reviewed_payload_only', 422);
            }
            $arguments = (new PreviewStore())->resolve($actor, $operationId, $previewHandle)['arguments'];
        }
        // Fields a person adds on the review page are not part of the caller's
        // contract, so they skip input validation and only ever come from a review.
        $reviewed = array_intersect_key($arguments, self::REVIEW_ONLY_FIELDS);
        [$arguments, $businessId] = $this->authorizeInput($actor, $definition, array_diff_key($arguments, self::REVIEW_ONLY_FIELDS));
        $arguments += $reviewed;
        if ($definition->readOnly) {
            return $this->result($definition, ($definition->handler)($actor, $arguments), $businessId, null);
        }
        if ($idempotencyKey === null) {
            throw new OperationException('idempotency_key_required', 422);
        }
        return Database::transaction(function () use (
            $actor,
            $definition,
            $arguments,
            $businessId,
            $idempotencyKey,
            $previewHandle
        ): array {
            // Serialize against business configuration and inventory before rereading preview effects.
            if ($businessId !== null) {
                $tenant = Database::query(
                    "SELECT id FROM tenants WHERE id = ?"
                        . ($definition->allowArchivedBusiness ? '' : " AND status != 'archived'")
                        . " FOR UPDATE",
                    [$businessId]
                );
                if (!$tenant) {
                    throw new OperationException('not_found', 404);
                }
            }
            $actor = (new ConnectionAuthenticator())->refresh($actor);
            (new AuthorizationPolicy())->authorize($actor, $definition, $businessId);
            $store = new OperationReceiptStore();
            $receipt = $store->acquire($actor, $definition->operationId, $businessId, $idempotencyKey, $arguments);
            if ($receipt['result'] !== null) {
                return $receipt['result'];
            }
            if ($previewHandle !== null) {
                if ($definition->previewHandler === null) {
                    throw new OperationException('preview_unavailable', 503);
                }
                $previews = new PreviewStore();
                $row = $previews->resolve($actor, $definition->operationId, $previewHandle, true);
                $previews->assertCommittable($row);
                $previews->verifyForCommit($row, ($definition->previewHandler)($actor, $arguments));
            }
            $data = \App\Engine\Outbox::duringOperation(
                $receipt['id'],
                fn () => ($definition->handler)($actor, $arguments)
            );
            $result = $this->result($definition, $data, $businessId, $receipt['id']);
            // This INSERT is required evidence. Unlike legacy best-effort logging, failure rolls back the command.
            Database::execute('INSERT INTO audit_log (id, tenant_id, actor_type, actor_id, action, entity_type, entity_id, details, request_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                Ulid::generate(),
                $businessId,
                $actor->auditType(),
                $actor->principalId ?: null,
                'agent.' . $definition->operationId,
                'operation',
                $receipt['id'],
                json_encode(
                    ['connection_id' => $actor->connectionId, 'receipt_id' => $receipt['id'], 'result' => 'committed'],
                    JSON_THROW_ON_ERROR
                ),
                AuditLog::getRequestId() ?: Ulid::generate(),
                gmdate('Y-m-d H:i:s')
            ]);
            $store->complete($receipt['id'], $result);
            return $result;
        });
    }

    public function preview(ActorContext $actor, string $operationId, array $arguments): array
    {
        $actor = (new ConnectionAuthenticator())->refresh($actor);
        $definition = $this->registry->get($operationId) ?? throw new OperationException('operation_not_found', 404);
        [$arguments, $businessId] = $this->authorizeInput($actor, $definition, $arguments);
        if ($definition->previewHandler === null) {
            throw new OperationException('preview_unavailable', 422);
        }
        return (new PreviewStore())->create(
            $actor,
            $definition,
            $arguments,
            ($definition->previewHandler)($actor, $arguments),
            $businessId
        );
    }

    private function authorizeInput(ActorContext $actor, OperationDefinition $definition, array $arguments): array
    {
        $businessId = null;
        if (isset($definition->inputSchema['properties']['business_id'])) {
            $businessId = $actor->business(
                isset($arguments['business_id']) && is_string($arguments['business_id']) ? $arguments['business_id'] : null
            );
            $arguments['business_id'] = $businessId;
        }
        (new AuthorizationPolicy())->authorize($actor, $definition, $businessId);
        if (!$definition->readOnly && DemoMode::isActive() && !$definition->demoAllowed) {
            throw new OperationException('demo_restricted', 403);
        }
        (new SchemaValidator())->validate($definition->inputSchema, $arguments);
        return [$arguments, $businessId];
    }

    private function result(OperationDefinition $definition, array $data, ?string $businessId, ?string $receiptId): array
    {
        (new SchemaValidator())->validate($definition->outputSchema, $data, true);
        return [
            'data' => $data,
            'operation' => $definition->operationId,
            'business_id' => $businessId,
            'receipt_id' => $receiptId,
            'notification_state' => $receiptId === null ? null : \App\Engine\Outbox::notificationState($receiptId)
        ];
    }
}
