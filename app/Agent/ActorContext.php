<?php

declare(strict_types=1);
namespace App\Agent;

/** Server-resolved identity. Request arguments never populate authority fields. */
final readonly class ActorContext
{
    public function __construct(
        public string $principalType,
        public string $principalId,
        public string $role,
        public array $businessIds,
        public array $scopes,
        public ?string $connectionId = null,
        public ?string $credentialId = null,
        public string $authMode = 'bearer',
        public ?string $bookingId = null,
        public ?string $customerId = null,
        public string $locale = 'en',
        public string $requestId = '',
    ) {}

    public function business(?string $requested = null): string
    {
        if ($requested !== null && $requested !== '') {
            if (!in_array($requested, $this->businessIds, true)) {
                throw new OperationException('not_found', 404);
            }
            return $requested;
        }
        if (count($this->businessIds) !== 1) {
            throw new OperationException('business_required', 422, nextAction: 'choose_business');
        }
        return $this->businessIds[0];
    }

    public function isStaff(): bool
    {
        return in_array($this->role, ['operator', 'business_owner', 'business_manager'], true);
    }

    public function auditType(): string
    {
        return match ($this->principalType) {
            'operator' => 'operator', 'business_user' => 'business_user',
            'customer' => 'customer', default => 'api',
        };
    }
}
