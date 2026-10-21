<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

final class AgentCorsHttpTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        TestFixtures::provision();
        $this->base = rtrim($_ENV['APP_TEST_URL'], '/');
    }

    public function testPreflightRequiresNeitherCredentialsNorCsrfAndLimitsHeadersAndMethods(): void
    {
        $response = $this->request('/mcp', 'OPTIONS', [
            'Origin: '.$this->base, 'Access-Control-Request-Method: POST',
            'Access-Control-Request-Headers: Authorization, Content-Type, Mcp-Method, MCP-Protocol-Version',
        ]);
        self::assertSame(204, $response['status'], $response['body']);
        self::assertSame($this->base, $response['headers']['access-control-allow-origin']);
        self::assertSame('POST', $response['headers']['access-control-allow-methods']);
        self::assertArrayNotHasKey('access-control-allow-credentials', $response['headers']);
        self::assertStringContainsString('Origin', $response['headers']['vary']);
        foreach ([
            ['DELETE', 'Authorization'], ['POST', 'Cookie'], ['POST', 'X-Unlisted'],
        ] as [$method, $headers]) {
            $denied = $this->request('/mcp', 'OPTIONS', ['Origin: '.$this->base, 'Access-Control-Request-Method: '.$method, 'Access-Control-Request-Headers: '.$headers]);
            self::assertSame(403, $denied['status'], $denied['body']);
            self::assertSame('cors_preflight_denied', json_decode($denied['body'], true)['code']);
            self::assertArrayNotHasKey('access-control-allow-methods', $denied['headers']);
        }
    }

    public function testAuthenticationChallengeAndMetadataAreReadableByAllowedBrowserOrigin(): void
    {
        $denied = $this->request('/mcp', 'POST', ['Origin: '.$this->base, 'Content-Type: application/json', 'Accept: application/json, text/event-stream']);
        self::assertSame(401, $denied['status'], $denied['body']);
        self::assertSame($this->base, $denied['headers']['access-control-allow-origin']);
        self::assertStringContainsString('WWW-Authenticate', $denied['headers']['access-control-expose-headers']);
        self::assertStringContainsString('resource_metadata=', $denied['headers']['www-authenticate']);
        foreach (['/.well-known/oauth-authorization-server', '/.well-known/oauth-protected-resource/mcp', '/api/agent/v2/schema'] as $path) {
            $metadata = $this->request($path, 'GET', ['Origin: '.$this->base]);
            self::assertSame(200, $metadata['status'], $metadata['body']);
            self::assertSame($this->base, $metadata['headers']['access-control-allow-origin']);
            self::assertArrayNotHasKey('access-control-allow-credentials', $metadata['headers']);
        }
    }

    public function testForeignAndOpaqueOriginsFailBeforeResourceDispatch(): void
    {
        foreach (['https://unlisted.example', 'null'] as $origin) {
            $response = $this->request('/mcp', 'POST', ['Origin: '.$origin, 'Content-Type: application/json']);
            self::assertSame(403, $response['status'], $response['body']);
            self::assertSame('origin_not_allowed', json_decode($response['body'], true)['code']);
            self::assertArrayNotHasKey('access-control-allow-origin', $response['headers']);
        }
        $native = $this->request('/.well-known/oauth-authorization-server');
        self::assertSame(200, $native['status']);
        self::assertArrayNotHasKey('access-control-allow-origin', $native['headers']);
    }

    public function testOnlyRegisteredApiRoutesReceivePreflights(): void
    {
        foreach (['/api/agent/v2/public/session', '/api/agent/v2/public/test-fixture/session', '/oauth/token'] as $path) {
            $response = $this->request($path, 'OPTIONS', ['Origin: '.$this->base, 'Access-Control-Request-Method: POST', 'Access-Control-Request-Headers: Content-Type']);
            self::assertSame(204, $response['status'], $response['body']);
        }
        $wrongMethod = $this->request('/api/agent/v2/schema', 'OPTIONS', ['Origin: '.$this->base, 'Access-Control-Request-Method: POST']);
        self::assertSame(403, $wrongMethod['status']);
        $authorize = $this->request('/oauth/authorize', 'OPTIONS', ['Origin: '.$this->base, 'Access-Control-Request-Method: POST']);
        self::assertSame(405, $authorize['status']);
        self::assertArrayNotHasKey('access-control-allow-origin', $authorize['headers']);
    }

    private function request(string $path, string $method = 'GET', array $headers = []): array
    {
        $curl = curl_init($this->base.$path);
        $responseHeaders = [];
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($key))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, '{}');
        }
        $body = curl_exec($curl);
        return ['status' => curl_getinfo($curl, CURLINFO_HTTP_CODE), 'headers' => $responseHeaders, 'body' => $body];
    }
}
