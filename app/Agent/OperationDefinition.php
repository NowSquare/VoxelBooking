<?php

declare(strict_types=1);

namespace App\Agent;

final readonly class OperationDefinition
{
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
    }

    public function tool(): array
    {
        return [
            'name' => $this->operationId,
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
            'outputSchema' => $this->resultSchema(),
            'annotations' => [
                'readOnlyHint' => $this->readOnly,
                'destructiveHint' => $this->destructive,
                'idempotentHint' => true,
                'openWorldHint' => $this->sideEffects !== []
            ],
        ];
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
