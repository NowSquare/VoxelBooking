<?php

declare(strict_types=1);
namespace Tests\Unit\Agent;

use App\Agent\ActorContext;
use App\Agent\AuthorizationPolicy;
use App\Agent\OperationDefinition;
use App\Agent\OperationException;
use PHPUnit\Framework\TestCase;

final class AuthorizationPolicyTest extends TestCase
{
    private function operation(string $policy, string $scope): OperationDefinition
    {
        return new OperationDefinition('example', 'Example', 'Example', ['type' => 'object'], ['type' => 'object'], [$scope], $policy, true, false, 'none', [], 'example', ['C01'], ['A02'], 'GET', '/example', static fn () => []);
    }
    private function actor(string $role, array $businesses, array $scopes): ActorContext
    {
        return new ActorContext('business_user', 'user-a', $role, $businesses, $scopes, 'connection-a', 'credential-a');
    }
    public function testManagerCannotUseOwnerScopesEvenWhenGrantNamesThem(): void
    {
        $this->expectException(OperationException::class);
        (new AuthorizationPolicy())->authorize($this->actor('business_manager', ['a'], ['business-settings:manage']), $this->operation('owner', 'business-settings:manage'), 'a');
    }
    public function testOwnerCannotEscapeBusinessWithNestedSelectors(): void
    {
        $policy = new AuthorizationPolicy();
        $actor = $this->actor('business_owner', ['a'], ['bookings:read']);
        $policy->authorize($actor, $this->operation('staff', 'bookings:read'), 'a');
        $this->expectException(OperationException::class);
        $policy->authorize($actor, $this->operation('staff', 'bookings:read'), 'b');
    }
    public function testOperatorGrantIsStillBusinessBound(): void
    {
        $actor = new ActorContext('operator', 'op', 'operator', ['a'], ['bookings:read'], 'c', 'k');
        $this->expectException(OperationException::class);
        (new AuthorizationPolicy())->authorize($actor, $this->operation('staff', 'bookings:read'), 'b');
    }
    public function testCustomerCannotAcquireStaffAuthorityFromScope(): void
    {
        $actor = new ActorContext('customer', 'customer-a', 'verified_customer', ['a'], ['bookings:read'], 'c', 'k');
        $this->expectException(OperationException::class);
        (new AuthorizationPolicy())->authorize($actor, $this->operation('staff', 'bookings:read'), 'a');
    }
    public function testMissingScopeIsDeniedForOwner(): void
    {
        $this->expectException(OperationException::class);
        (new AuthorizationPolicy())->authorize($this->actor('business_owner', ['a'], []), $this->operation('staff', 'bookings:read'), 'a');
    }
    public function testSingleBusinessIsInferredButMultipleBusinessesRequireChoice(): void
    {
        self::assertSame('a', $this->actor('business_owner', ['a'], [])->business());
        $this->expectException(OperationException::class);
        $this->actor('operator', ['a', 'b'], [])->business();
    }
}
