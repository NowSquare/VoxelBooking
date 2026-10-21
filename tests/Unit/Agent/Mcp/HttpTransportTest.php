<?php

declare(strict_types=1);

namespace Tests\Unit\Agent\Mcp;

use App\Agent\Mcp\HttpTransport;
use App\Agent\Mcp\Server;
use App\Agent\Mcp\ToolFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpTransportTest extends TestCase
{
    private const VERSION = '2026-07-28';
    private array $calls = [];

    private function transport(?\Closure $call = null, array $tools = []): HttpTransport
    {
        $tools = $tools ?: [[
            'name' => 'booking_get',
            'title' => 'Get a booking',
            'description' => 'Read a booking visible to the current actor.',
            'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
            'outputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
            'annotations' => ['readOnlyHint' => true],
        ]];
        $call ??= function (string $name, array $arguments, array $context): array {
            $this->calls[] = [$name, $arguments, $context];
            return ['id' => $arguments['id']];
        };
        return new HttpTransport(
            new Server(static fn (): array => $tools, $call, ['name' => 'voxelbooking', 'version' => '2.0.0']),
            ['https://booking.example', 'https://client.example:8443'],
        );
    }

    private function request(string $method, array $params = [], int|string $id = 1, string $version = self::VERSION): string
    {
        if ($version === self::VERSION) {
            $params['_meta'] = [
                'io.modelcontextprotocol/protocolVersion' => $version,
                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                'io.modelcontextprotocol/clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
            ] + ($params['_meta'] ?? []);
        }
        return json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params], JSON_THROW_ON_ERROR);
    }

    private function headers(string $method, ?string $name = null): array
    {
        return array_filter([
            'Content-Type' => 'application/json; charset=utf-8',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => self::VERSION,
            'Mcp-Method' => $method,
            'Mcp-Name' => $name,
        ], static fn ($value) => $value !== null);
    }

    public function testModernDiscoveryWorksWithoutHandshakeOrSession(): void
    {
        $response = $this->transport()->handle('POST', $this->headers('server/discover'), $this->request('server/discover'));
        self::assertSame(200, $response->status);
        self::assertSame('complete', $response->body['result']['resultType']);
        self::assertSame(['2026-07-28', '2025-11-25'], $response->body['result']['supportedVersions']);
        self::assertSame('voxelbooking', $response->body['result']['_meta']['io.modelcontextprotocol/serverInfo']['name']);
        self::assertSame('private', $response->body['result']['cacheScope']);
        self::assertSame(0, $response->body['result']['ttlMs']);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertArrayNotHasKey('Mcp-Session-Id', $response->headers);
        self::assertStringContainsString('"tools":{}', $response->json());
    }

    public function testLegacyHandshakeHasLegacyShapeAndNegotiatesSupportedVersion(): void
    {
        $headers = $this->headers('initialize');
        unset($headers['MCP-Protocol-Version'], $headers['Mcp-Method']);
        $body = $this->request('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'legacy-client', 'version' => '1'],
        ], 'init', '2025-11-25');
        $response = $this->transport()->handle('POST', $headers, $body);
        self::assertSame(200, $response->status);
        self::assertSame('init', $response->body['id']);
        self::assertSame('2025-11-25', $response->body['result']['protocolVersion']);
        self::assertArrayNotHasKey('resultType', $response->body['result']);
        self::assertArrayHasKey('serverInfo', $response->body['result']);
    }

    public function testLegacyToolCallRemainsStatelessAndHasTextFallback(): void
    {
        $headers = $this->headers('tools/call', 'booking_get');
        $headers['MCP-Protocol-Version'] = '2025-11-25';
        unset($headers['Mcp-Method'], $headers['Mcp-Name']);
        $response = $this->transport()->handle('POST', $headers, $this->request('tools/call', ['name' => 'booking_get', 'arguments' => ['id' => 'booking-1']], 0, '2025-11-25'));
        self::assertSame(200, $response->status);
        self::assertSame(0, $response->body['id']);
        self::assertSame(['id' => 'booking-1'], $response->body['result']['structuredContent']);
        self::assertSame('{"id":"booking-1"}', $response->body['result']['content'][0]['text']);
        self::assertArrayNotHasKey('resultType', $response->body['result']);
    }

    public function testUnsupportedModernVersionListsSupportedVersions(): void
    {
        $body = json_decode($this->request('server/discover'), true);
        $body['params']['_meta']['io.modelcontextprotocol/protocolVersion'] = '2099-01-01';
        $headers = $this->headers('server/discover');
        $headers['MCP-Protocol-Version'] = '2099-01-01';
        $response = $this->transport()->handle('POST', $headers, json_encode($body, JSON_THROW_ON_ERROR));
        self::assertSame(400, $response->status);
        self::assertSame(-32022, $response->body['error']['code']);
        self::assertSame('2099-01-01', $response->body['error']['data']['requested']);
        self::assertContains(self::VERSION, $response->body['error']['data']['supported']);
        self::assertSame([], $this->calls);
    }

    #[DataProvider('invalidEnvelopes')]
    public function testMalformedMessagesNeverReachOperations(string $body, int $code): void
    {
        $response = $this->transport()->handle('POST', $this->headers('tools/call'), $body);
        self::assertSame(400, $response->status);
        self::assertSame($code, $response->body['error']['code']);
        self::assertSame([], $this->calls);
    }

    public static function invalidEnvelopes(): array
    {
        return [
            'invalid-json' => ['{', -32700],
            'batch' => ['[{"jsonrpc":"2.0","id":1,"method":"tools/list"}]', -32600],
            'scalar' => ['null', -32600],
            'null-id' => ['{"jsonrpc":"2.0","id":null,"method":"tools/list"}', -32600],
            'boolean-id' => ['{"jsonrpc":"2.0","id":true,"method":"tools/list"}', -32600],
            'wrong-version' => ['{"jsonrpc":"1.0","id":1,"method":"tools/list"}', -32600],
            'params-array' => ['{"jsonrpc":"2.0","id":1,"method":"tools/list","params":[]}', -32602],
            'client-response' => ['{"jsonrpc":"2.0","id":1,"result":{}}', -32600],
        ];
    }

    #[DataProvider('origins')]
    public function testOriginValidationUsesConfiguredOrigins(string $origin, int $status): void
    {
        $headers = $this->headers('server/discover');
        $headers['Origin'] = $origin;
        $response = $this->transport()->handle('POST', $headers, $this->request('server/discover'));
        self::assertSame($status, $response->status);
    }

    public static function origins(): array
    {
        return [
            ['https://booking.example', 200],
            ['https://client.example:8443', 200],
            ['https://booking.example.evil.invalid', 403],
            ['http://booking.example', 403],
            ['https://booking.example:8443', 403],
            ['null', 403],
            ['', 403],
            ['https://booking.example/path', 403],
            ['https://booking.example https://evil.invalid', 403],
        ];
    }

    #[DataProvider('headerFailures')]
    public function testModernHeaderValidationRunsBeforeExecution(string $header, ?string $value): void
    {
        $headers = $this->headers('tools/call', 'booking_get');
        if ($value === null) {
            unset($headers[$header]);
        } else {
            $headers[$header] = $value;
        }
        $response = $this->transport()->handle('POST', $headers, $this->request('tools/call', ['name' => 'booking_get', 'arguments' => ['id' => 'booking-1']]));
        self::assertSame(400, $response->status);
        self::assertSame(-32020, $response->body['error']['code']);
        self::assertSame([], $this->calls);
    }

    public static function headerFailures(): array
    {
        return [
            ['MCP-Protocol-Version', null],
            ['MCP-Protocol-Version', '2025-11-25'],
            ['Mcp-Method', null],
            ['Mcp-Method', 'tools/list'],
            ['Mcp-Method', "tools/call\r\nX-Injected: yes"],
            ['Mcp-Name', null],
            ['Mcp-Name', 'booking_cancel'],
            ['Mcp-Name', '=?base64?invalid!?='],
        ];
    }

    public function testModernRequestRequiresCapabilityMetadata(): void
    {
        $body = json_decode($this->request('tools/list'), true);
        unset($body['params']['_meta']['io.modelcontextprotocol/clientCapabilities']);
        $response = $this->transport()->handle('POST', $this->headers('tools/list'), json_encode($body, JSON_THROW_ON_ERROR));
        self::assertSame(400, $response->status);
        self::assertSame(-32602, $response->body['error']['code']);
    }

    public function testToolArgumentsMustBeAnObject(): void
    {
        $response = $this->transport()->handle('POST', $this->headers('tools/call', 'booking_get'), $this->request('tools/call', ['name' => 'booking_get', 'arguments' => ['booking-1']]));
        self::assertSame(-32602, $response->body['error']['code']);
        self::assertSame([], $this->calls);
    }

    public function testToolCallPassesSafeProtocolContextAndIdempotencyToExecutor(): void
    {
        $headers = array_change_key_case($this->headers('tools/call', 'booking_get'), CASE_LOWER);
        $headers['authorization'] = 'Bearer secret-never-pass';
        $body = $this->request('tools/call', [
            'name' => 'booking_get',
            'arguments' => ['id' => 'booking-1'],
            '_meta' => ['com.voxelbooking/idempotencyKey' => 'retry-1'],
        ], 'request-1');
        $response = $this->transport()->handle('POST', $headers, $body);
        self::assertFalse($response->body['result']['isError']);
        self::assertSame(['id' => 'booking-1'], $response->body['result']['structuredContent']);
        self::assertSame('retry-1', $this->calls[0][2]['idempotency_key']);
        self::assertSame(self::VERSION, $this->calls[0][2]['protocol_version']);
        self::assertSame('request-1', $this->calls[0][2]['request_id']);
        self::assertStringNotContainsString('secret-never-pass', json_encode($this->calls));
    }

    public function testApplicationFailureIsActionableToolResultWithoutBreakingOutputSchema(): void
    {
        $problem = ['code' => 'slot_unavailable', 'status' => 409, 'retryable' => false, 'next_action' => 'refresh_availability'];
        $transport = $this->transport(static function () use ($problem): array {
            throw new ToolFailure($problem);
        });
        $response = $transport->handle('POST', $this->headers('tools/call', 'booking_get'), $this->request('tools/call', ['name' => 'booking_get', 'arguments' => ['id' => 'booking-1']]));
        self::assertSame(200, $response->status);
        self::assertTrue($response->body['result']['isError']);
        self::assertSame($problem, json_decode($response->body['result']['content'][0]['text'], true));
        self::assertArrayNotHasKey('structuredContent', $response->body['result']);
    }

    public function testUnexpectedFailureDoesNotLeakExceptionOrInput(): void
    {
        $transport = $this->transport(static function (): array {
            throw new \RuntimeException('secret database password');
        });
        $response = $transport->handle('POST', $this->headers('tools/call', 'booking_get'), $this->request('tools/call', ['name' => 'booking_get', 'arguments' => ['id' => 'booking-1']]));
        self::assertSame(500, $response->status);
        self::assertSame(-32603, $response->body['error']['code']);
        self::assertStringNotContainsString('secret database password', $response->json());
    }

    public function testUnknownToolNeverReachesExecutor(): void
    {
        $response = $this->transport()->handle('POST', $this->headers('tools/call', 'booking_cancel'), $this->request('tools/call', ['name' => 'booking_cancel']));
        self::assertSame(-32602, $response->body['error']['code']);
        self::assertSame([], $this->calls);
    }

    public function testModernUnknownMethodReturnsHttp404(): void
    {
        $response = $this->transport()->handle('POST', $this->headers('ping'), $this->request('ping'));
        self::assertSame(404, $response->status);
        self::assertSame(-32601, $response->body['error']['code']);
    }

    public function testLegacyInitializedNotificationReturnsEmptyAcceptedResponse(): void
    {
        $headers = $this->headers('notifications/initialized');
        $headers['MCP-Protocol-Version'] = '2025-11-25';
        $response = $this->transport()->handle('POST', $headers, '{"jsonrpc":"2.0","method":"notifications/initialized"}');
        self::assertSame(202, $response->status);
        self::assertNull($response->body);
        self::assertSame('', $response->json());
    }

    public function testNotificationCannotInvokeMutation(): void
    {
        $body = json_decode($this->request('tools/call', ['name' => 'booking_get', 'arguments' => ['id' => '1']]), true);
        unset($body['id']);
        $response = $this->transport()->handle('POST', $this->headers('tools/call', 'booking_get'), json_encode($body));
        self::assertSame(400, $response->status);
        self::assertSame([], $this->calls);
    }

    public function testGetAndDeleteReturn405WithoutSessionOrStream(): void
    {
        foreach (['GET', 'DELETE'] as $method) {
            $response = $this->transport()->handle($method, [], '');
            self::assertSame(405, $response->status);
            self::assertSame('POST', $response->headers['Allow']);
        }
    }

    public function testJsonAndEventStreamMustBeAccepted(): void
    {
        $headers = $this->headers('server/discover');
        $headers['Accept'] = 'application/json';
        $response = $this->transport()->handle('POST', $headers, $this->request('server/discover'));
        self::assertSame(406, $response->status);
    }

    public function testUnsupportedContentTypeAndOversizedBodyAreRejected(): void
    {
        $headers = $this->headers('server/discover');
        $headers['Content-Type'] = 'text/plain';
        self::assertSame(415, $this->transport()->handle('POST', $headers, '{}')->status);
        self::assertSame(413, $this->transport()->handle('POST', $this->headers('server/discover'), str_repeat(' ', 1_048_577))->status);
    }

    public function testEncodedNameAndCustomHeadersMatchBeforeExecution(): void
    {
        $tool = [
            'name' => 'booking_get',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'string', 'x-mcp-header' => 'Booking'],
                'filters' => ['type' => 'object', 'properties' => [
                    'limit' => ['type' => 'integer', 'x-mcp-header' => 'Limit'],
                ]],
            ]],
        ];
        $transport = $this->transport(tools: [$tool]);
        $body = $this->request('tools/call', ['name' => 'booking_get', 'arguments' => ['id' => 'boek-één', 'filters' => ['limit' => 42]]]);
        $headers = $this->headers('tools/call', '=?base64?' . base64_encode('booking_get') . '?=');
        $headers['Mcp-Param-Booking'] = '=?base64?' . base64_encode('boek-één') . '?=';
        $headers['Mcp-Param-Limit'] = '42';
        self::assertSame(200, $transport->handle('POST', $headers, $body)->status);
        $headers['Mcp-Param-Booking'] = 'wrong-booking';
        $response = $transport->handle('POST', $headers, $body);
        self::assertSame(400, $response->status);
        self::assertSame(-32020, $response->body['error']['code']);
        self::assertCount(1, $this->calls);
    }

    public function testToolsAreSortedAndPaginatedWithBoundOpaqueCursors(): void
    {
        $tools = [];
        for ($i = 54; $i >= 0; --$i) {
            $tools[] = ['name' => 'operation_' . sprintf('%02d', $i), 'inputSchema' => ['type' => 'object']];
        }
        $transport = $this->transport(tools: $tools);
        $first = $transport->handle('POST', $this->headers('tools/list'), $this->request('tools/list'));
        self::assertCount(50, $first->body['result']['tools']);
        self::assertSame('operation_00', $first->body['result']['tools'][0]['name']);
        $cursor = $first->body['result']['nextCursor'];
        $second = $transport->handle('POST', $this->headers('tools/list'), $this->request('tools/list', ['cursor' => $cursor]));
        self::assertCount(5, $second->body['result']['tools']);
        self::assertSame('operation_50', $second->body['result']['tools'][0]['name']);
        self::assertArrayNotHasKey('nextCursor', $second->body['result']);
        $tampered = $transport->handle('POST', $this->headers('tools/list'), $this->request('tools/list', ['cursor' => $cursor . 'a']));
        self::assertSame(-32602, $tampered->body['error']['code']);
        $otherActor = $this->transport(tools: $tools);
        $other = $otherActor->handle('POST', $this->headers('tools/list'), $this->request('tools/list', ['cursor' => $cursor]));
        self::assertSame(-32602, $other->body['error']['code']);
    }

    public function testResourcesAndPromptsAdvertiseOnlyConfiguredCapabilities(): void
    {
        $server = new Server(
            static fn (): array => [],
            static fn (): array => [],
            ['name' => 'voxelbooking', 'version' => '2.0.0'],
            resources: static fn (): array => [['uri' => 'voxelbooking://policy', 'name' => 'policy', 'mimeType' => 'application/json']],
            readResource: static fn (string $uri): array => ['contents' => [['uri' => $uri, 'mimeType' => 'application/json', 'text' => '{"role":"manager"}']]],
            prompts: static fn (): array => [['name' => 'review_day', 'arguments' => [['name' => 'date', 'required' => true]]]],
            getPrompt: static fn (string $name, array $arguments): array => ['messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $arguments['date']]]]],
        );
        $transport = new HttpTransport($server, []);
        $discovery = $transport->handle('POST', $this->headers('server/discover'), $this->request('server/discover'));
        self::assertArrayHasKey('resources', $discovery->body['result']['capabilities']);
        self::assertArrayHasKey('prompts', $discovery->body['result']['capabilities']);
        self::assertArrayNotHasKey('subscriptions', $discovery->body['result']['capabilities']);
        $resource = $transport->handle('POST', $this->headers('resources/read', 'voxelbooking://policy'), $this->request('resources/read', ['uri' => 'voxelbooking://policy']));
        self::assertSame('{"role":"manager"}', $resource->body['result']['contents'][0]['text']);
        self::assertSame('private', $resource->body['result']['cacheScope']);
        $missing = $transport->handle('POST', $this->headers('prompts/get', 'review_day'), $this->request('prompts/get', ['name' => 'review_day']));
        self::assertSame(-32602, $missing->body['error']['code']);
        $prompt = $transport->handle('POST', $this->headers('prompts/get', 'review_day'), $this->request('prompts/get', ['name' => 'review_day', 'arguments' => ['date' => '2026-10-20']]));
        self::assertSame('2026-10-20', $prompt->body['result']['messages'][0]['content']['text']);
    }
}
