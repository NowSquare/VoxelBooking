<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Engine\AgentAuth;
use PHPUnit\Framework\TestCase;

final class LegacyCredentialTest extends TestCase
{
    public function testDormantWriteScopesNeverBecomeEffective(): void
    {
        $key = ['role' => 'agent', 'scopes_array' => AgentAuth::ROLE_DEFAULTS['agent']];
        foreach (['tenants:write', 'bookings:write', 'services:write', 'customers:write'] as $scope) {
            self::assertFalse(AgentAuth::hasScope($key, $scope), $scope);
        }
        self::assertTrue(AgentAuth::hasScope($key, 'bookings:read'));
    }

    public function testBearerSchemeIsCaseInsensitiveButRejectsMalformedTokens(): void
    {
        self::assertSame('abc123', AgentAuth::extractBearerToken('bearer abc123'));
        self::assertSame('', AgentAuth::extractBearerToken("Bearer abc\r\nInjected: x"));
        self::assertSame('', AgentAuth::extractBearerToken('Bearer a b'));
    }
}
