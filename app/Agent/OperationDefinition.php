<?php

declare(strict_types=1);

namespace App\Agent;

final readonly class OperationDefinition
{
    /**
     * How safely a call can be repeated.
     *
     * none: a read; it takes no idempotency key and can be repeated freely.
     * required: a write whose repeat under a fresh key acts again (a second booking, a
     *   second email), so only the idempotency key's stored receipt makes a retry safe.
     * natural: a write that converges; repeating the same arguments under a fresh key
     *   leaves the same state and sends nothing new. It still needs a key, like every write.
     */
    public const IDEMPOTENCY_POLICIES = ['none', 'required', 'natural'];

    /**
     * Every side effect an operation may declare, mapped to whether it reaches beyond
     * this installation. Only effects that contact people or systems outside it (email,
     * webhook delivery) make an operation open-world for MCP clients.
     */
    public const SIDE_EFFECTS = [
        'audit' => false,
        'booking.notification' => true,
        'customer.privacy_notification' => true,
        'notifications' => true,
        'scheduled_maintenance' => true,
        'webhook_configuration' => false,
        'webhook_delivery' => true,
    ];

    /** Problems the agent transports raise for every operation, whatever its handler does. */
    private const TRANSPORT_ERRORS = [
        'forbidden',
        'insufficient_scope',
        'rate_limited',
        'rate_limiter_unavailable',
        'unauthorized',
        'validation_failed',
    ];

    /**
     * Why an authenticated transport refused the credential before any operation ran.
     * The caller holds the token, so naming the reason reveals nothing it does not own.
     */
    public const CREDENTIAL_ERRORS = [
        'account_disabled',
        'authorization_required',
        'connection_expired',
        'connection_revoked',
        'identity_changed',
        'identity_verification_required',
        'wrong_audience',
    ];

    /** Problems the executor raises around every write before or after its handler runs. */
    private const WRITE_ERRORS = [
        'idempotency_conflict',
        'idempotency_key_mismatch',
        'idempotency_key_required',
        'legacy_read_only',
        'replay_expired',
    ];

    /** Problems the executor raises when a write commits a reviewed preview. */
    private const REVIEW_ERRORS = [
        'preview_changed',
        'preview_consumed',
        'preview_expired',
        'preview_required',
        'preview_unavailable',
        'reviewed_payload_only',
        'trusted_review_required',
    ];

    public string $idempotencyPolicy;
    public string $mcpToolName;

    public function __construct(
        public string $operationId,
        public string $title,
        public string $description,
        public array $inputSchema,
        public array $outputSchema,
        public array $requiredScopes,
        public string $actorPolicy,
        public bool $readOnly,
        public bool $destructive,
        public string $confirmationPolicy,
        public array $patterns,
        public string $documentationPage,
        public array $capabilityIds,
        public array $acceptanceTestIds,
        public string $httpMethod,
        public string $httpPath,
        public \Closure $handler,
        public array $sideEffects = [],
        public array $errorCodes = [],
        public bool $demoAllowed = false,
        public ?\Closure $previewHandler = null,
        public bool $allowArchivedBusiness = false,
        ?string $idempotencyPolicy = null,
        ?string $mcpToolName = null,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/D', $operationId) !== 1) {
            throw new \InvalidArgumentException('Invalid operation name.');
        }
        if (!in_array(
            $actorPolicy,
            ['public', 'authenticated', 'customer_booking', 'customer_privacy', 'staff', 'owner', 'operator', 'self'],
            true
        )) {
            throw new \InvalidArgumentException('Unknown actor policy.');
        }
        // The executor demands a key for every write and accepts none for reads, so the policy must agree.
        $this->idempotencyPolicy = $idempotencyPolicy ?? ($readOnly ? 'none' : 'required');
        if (!in_array($this->idempotencyPolicy, self::IDEMPOTENCY_POLICIES, true)
            || ($this->idempotencyPolicy === 'none') !== $readOnly) {
            throw new \InvalidArgumentException('Idempotency policy does not match the operation.');
        }
        // MCP tool names allow 64 characters, and reviewed writes also publish a "_preview" tool.
        $this->mcpToolName = $mcpToolName ?? $operationId;
        if (preg_match('/\A[a-zA-Z0-9_-]{1,56}\z/D', $this->mcpToolName) !== 1) {
            throw new \InvalidArgumentException('Invalid MCP tool name.');
        }
        foreach ($sideEffects as $sideEffect) {
            if (!array_key_exists($sideEffect, self::SIDE_EFFECTS)) {
                throw new \InvalidArgumentException('Unknown side effect: ' . $sideEffect);
            }
        }
    }

    public function tool(): array
    {
        return [
            'name' => $this->mcpToolName,
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
            'outputSchema' => $this->resultSchema(),
            'annotations' => [
                'readOnlyHint' => $this->readOnly,
                'destructiveHint' => $this->destructive,
                'idempotentHint' => $this->idempotencyPolicy !== 'required',
                'openWorldHint' => $this->reachesOutside()
            ],
            '_meta' => [
                'com.voxelbooking/operation_id' => $this->operationId,
                'com.voxelbooking/idempotency_policy' => $this->idempotencyPolicy,
            ],
        ];
    }

    /** True when the operation contacts people or systems outside this installation. */
    public function reachesOutside(): bool
    {
        foreach ($this->sideEffects as $sideEffect) {
            if (self::SIDE_EFFECTS[$sideEffect]) {
                return true;
            }
        }
        return false;
    }

    /**
     * The handler's declared problems plus those the transports and executor add for
     * this kind of operation, so a published contract lists every code a caller can meet.
     */
    public function problemCodes(): array
    {
        $codes = [...$this->errorCodes, ...self::TRANSPORT_ERRORS];
        if ($this->actorPolicy !== 'public') {
            array_push($codes, ...self::CREDENTIAL_ERRORS);
        }
        if (isset($this->inputSchema['properties']['business_id'])) {
            $codes[] = 'business_required';
            $codes[] = 'not_found';
        }
        if (!$this->readOnly) {
            array_push($codes, ...self::WRITE_ERRORS);
            if (!$this->demoAllowed) {
                $codes[] = 'demo_restricted';
            }
            if ($this->confirmationPolicy !== 'none') {
                array_push($codes, ...self::REVIEW_ERRORS);
            }
        }
        $codes = array_values(array_unique($codes));
        sort($codes);
        return $codes;
    }

    public function resultSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['data', 'operation', 'business_id', 'receipt_id', 'notification_state'],
            'properties' => [
                'data' => $this->outputSchema,
                'operation' => ['type' => 'string', 'const' => $this->operationId],
                'business_id' => ['type' => ['string', 'null']],
                'receipt_id' => ['type' => ['string', 'null']],
                'notification_state' => ['type' => ['string', 'null']],
            ],
        ];
    }
}
