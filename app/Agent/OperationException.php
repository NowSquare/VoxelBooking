<?php

declare(strict_types=1);

namespace App\Agent;

final class OperationException extends \RuntimeException
{
    public function __construct(
        public readonly string $problemCode,
        public readonly int $status = 422,
        public readonly array $fieldErrors = [],
        public readonly ?string $nextAction = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($problemCode);
    }

    public function problem(string $requestId = ''): array
    {
        return array_filter([
            'type' => 'urn:voxelbooking:problem:' . str_replace('_', '-', $this->problemCode),
            'title' => __('agent.errors.' . $this->problemCode),
            'status' => $this->status,
            'code' => $this->problemCode,
            'request_id' => $requestId,
            'retryable' => $this->retryable,
            'field_errors' => $this->fieldErrors ?: null,
            'next_action' => $this->nextAction,
        ], static fn ($value) => $value !== null);
    }
}
