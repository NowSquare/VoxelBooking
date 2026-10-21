<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\CorsPolicy;
use App\Agent\OperationException;
use PHPUnit\Framework\TestCase;

final class CorsPolicyTest extends TestCase
{
    public function testDefaultAllowsOnlyCanonicalOriginAndNativeClients(): void
    {
        $policy = new CorsPolicy('https://booking.example');
        self::assertTrue($policy->permits(null));
        self::assertTrue($policy->permits('https://booking.example'));
        foreach (['null', 'https://evil.example', 'https://booking.example.evil.test', 'https://booking.example/'] as $origin) {
            self::assertFalse($policy->permits($origin));
        }
    }

    public function testExplicitOriginsAreExactAndDoNotGrantCookieAccess(): void
    {
        $policy = new CorsPolicy('https://booking.example/app', 'https://client.example, https://editor.example:8443');
        self::assertSame(['https://booking.example', 'https://client.example', 'https://editor.example:8443'], $policy->origins());
        self::assertTrue($policy->permits('https://editor.example:8443'));
        self::assertFalse($policy->permits('https://editor.example'));
        $headers = $policy->responseHeaders('https://client.example');
        self::assertSame('https://client.example', $headers['Access-Control-Allow-Origin']);
        self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $headers);
        self::assertStringContainsString('WWW-Authenticate', $headers['Access-Control-Expose-Headers']);
    }

    public function testUnsafeConfigurationFailsClosed(): void
    {
        foreach (['*', 'null', 'https://user:pass@client.example', 'https://client.example/path', 'https://client.example?x=y', 'https://client.example#x', 'http://client.example', "https://client.example\r\nX-Test: x"] as $origin) {
            try {
                new CorsPolicy('https://booking.example', $origin);
                self::fail('Accepted unsafe origin: '.$origin);
            } catch (OperationException $error) {
                self::assertSame('origin_configuration_invalid', $error->problemCode);
                self::assertSame(503, $error->status);
            }
        }
    }

    public function testPreflightAllowsOnlyRequestedSupportedHeadersAndActualMethod(): void
    {
        $policy = new CorsPolicy('https://booking.example', 'https://client.example');
        $headers = $policy->preflight('https://client.example', 'POST', 'Authorization, Content-Type, MCP-Protocol-Version, Mcp-Method', ['POST']);
        self::assertSame('POST', $headers['Access-Control-Allow-Methods']);
        self::assertSame('authorization, content-type, mcp-protocol-version, mcp-method', $headers['Access-Control-Allow-Headers']);
        self::assertSame('600', $headers['Access-Control-Max-Age']);
        foreach ([['DELETE', '', ['POST']], ['POST', 'X-Unknown', ['POST']], ['POST', 'Cookie', ['POST']]] as [$method, $requested, $allowed]) {
            try {
                $policy->preflight('https://client.example', $method, $requested, $allowed);
                self::fail('Accepted invalid preflight.');
            } catch (OperationException $error) {
                self::assertSame('cors_preflight_denied', $error->problemCode);
            }
        }
    }

    public function testUnlistedPreflightOriginIsRejectedWithoutReflection(): void
    {
        $this->expectException(OperationException::class);
        (new CorsPolicy('https://booking.example'))->preflight('https://client.example', 'POST', '', ['POST']);
    }
}
