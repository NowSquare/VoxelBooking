<?php

declare(strict_types=1);
namespace App\Agent;

final class AuthorizationPolicy
{
    public function permits(ActorContext $actor, OperationDefinition $operation, ?string $businessId = null): bool
    {
        try { $this->authorize($actor, $operation, $businessId); return true; }
        catch (OperationException) { return false; }
    }

    public function authorize(ActorContext $actor, OperationDefinition $operation, ?string $businessId = null): void
    {
        if ($businessId !== null) {
            $actor->business($businessId);
        }
        $eligible = match ($operation->actorPolicy) {
            'public' => true,
            'authenticated' => $actor->isStaff() || $actor->role === 'verified_customer',
            'staff' => $actor->isStaff(),
            'owner' => in_array($actor->role, ['operator', 'business_owner'], true),
            'operator' => $actor->role === 'operator',
            'self' => $actor->isStaff(),
            'customer_booking' => $actor->role === 'verified_customer' && $actor->bookingId !== null,
            'customer_privacy' => $actor->role === 'verified_customer' && $actor->customerId !== null && $actor->bookingId === null,
        };
        if (!$eligible || array_diff($operation->requiredScopes, $actor->scopes) !== []) {
            throw new OperationException('forbidden', 403, nextAction: 'review_connection_access');
        }
        if ($actor->authMode === 'legacy' && !$operation->readOnly) {
            throw new OperationException('legacy_read_only', 403, nextAction: 'create_connection');
        }
    }
}
