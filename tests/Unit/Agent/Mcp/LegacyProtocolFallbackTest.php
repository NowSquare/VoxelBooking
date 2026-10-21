<?php

declare(strict_types=1);

namespace Tests\Unit\Agent\Mcp;

use App\Agent\Mcp\HttpTransport;
use App\Agent\Mcp\Server;
use PHPUnit\Framework\TestCase;

/**
 * Older Streamable HTTP clients that never send `MCP-Protocol-Version`, and the
 * status codes they rely on.
 *
 * The MCP specification tells servers to assume 2025-03-26 when the header is
 * absent. Those clients treat HTTP 404 as "your session expired", so a method
 * this server does not offer must answer 200 with a JSON-RPC error for them.
 */
final class LegacyProtocolFallbackTest extends TestCase
{
    private array $calls = [];

    private function transport(): HttpTransport
    {
        $tools = [[
            'name' => 'booking_get',
            'title' => 'Get a booking',
            'description' => 'Read a booking visible to the current actor.',
            'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
            'outputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
            'annotations' => ['readOnlyHint' => true],
        ]];
        $call = function (string $name, array $arguments, array $context): array {
            $this->calls[] = [$name, $arguments, $context];
            return ['id' => $arguments['id']];
        };
        return new HttpTransport(
            new Server(static fn (): array => $tools, $call, ['name' => 'voxelbooking', 'version' => '2.0.0']),
            ['https://booking.example'],
        );
    }

    private function headers(?string $protocolVersion = null): array
    {
        return array_filter([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => $protocolVersion,
        ], static fn ($value) => $value !== null);
    }

    private function body(string $method, array $params = [], bool $notification = false): string
    {
        $message = ['jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params];
        if (!$notification) {
            $message['id'] = 7;
        }
        return json_encode($message, JSON_THROW_ON_ERROR);
    }

    public function testHeaderlessToolCallFallsBackToLegacyBehaviour(): void
    {
        $response = $this->transport()->handle(
            'POST',
            $this->headers(),
            $this->body('tools/call', ['name' => 'booking_get', 'arguments' => ['id' => 'booking-1']]),
        );
        self::assertSame(200, $response->status, $response->json());
        self::assertSame(['id' => 'booking-1'], $response->body['result']['structuredContent']);
        self::assertArrayNotHasKey('resultType', $response->body['result']);
        self::assertSame(Server::ASSUMED_VERSION, $this->calls[0][2]['protocol_version']);
    }

    public function testHeaderlessListAndPingUseLegacyShapes(): void
    {
        $list = $this->transport()->handle('POST', $this->headers(), $this->body('tools/list'));
        self::assertSame(200, $list->status, $list->json());
        self::assertSame('booking_get', $list->body['result']['tools'][0]['name']);
        self::assertArrayNotHasKey('resultType', $list->body['result']);
        $ping = $this->transport()->handle('POST', $this->headers(), $this->body('ping'));
        self::assertSame(200, $ping->status, $ping->json());
        self::assertSame('{}', json_encode($ping->body['result']));
    }

    public function testHeaderlessInitializedNotificationIsAccepted(): void
    {
        $response = $this->transport()->handle('POST', $this->headers(), $this->body('notifications/initialized', notification: true));
        self::assertSame(202, $response->status);
    }

    public function testHeaderlessRequestCannotUseModernOnlyMethods(): void
    {
        $response = $this->transport()->handle('POST', $this->headers(), $this->body('server/discover'));
        self::assertSame(200, $response->status);
        self::assertSame(-32601, $response->body['error']['code']);
    }

    public function testModernBodyVersionStillRequiresTheMatchingHeader(): void
    {
        $params = ['_meta' => [
            'io.modelcontextprotocol/protocolVersion' => Server::MODERN_VERSION,
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ]];
        foreach ([null, Server::LEGACY_VERSION] as $header) {
            $headers = $this->headers($header) + ['Mcp-Method' => 'tools/list'];
            $response = $this->transport()->handle('POST', $headers, $this->body('tools/list', $params));
            self::assertSame(400, $response->status, $response->json());
            self::assertSame(-32020, $response->body['error']['code']);
            self::assertSame('MCP-Protocol-Version', $response->body['error']['data']['header']);
        }
        self::assertSame([], $this->calls);
    }

    public function testUnsupportedHeaderIsStillRejected(): void
    {
        $response = $this->transport()->handle('POST', $this->headers('2099-01-01'), $this->body('tools/list'));
        self::assertSame(400, $response->status);
        self::assertSame([], $this->calls);
    }

    public function testModernHeaderWithoutBodyMetadataIsStillRejected(): void
    {
        $headers = $this->headers(Server::MODERN_VERSION) + ['Mcp-Method' => 'tools/list'];
        $response = $this->transport()->handle('POST', $headers, $this->body('tools/list'));
        self::assertSame(400, $response->status);
        self::assertSame(-32602, $response->body['error']['code']);
    }

    public function testLegacyUnconfiguredResourcesAndPromptsAnswer200WithMethodNotFound(): void
    {
        foreach ([null, Server::LEGACY_VERSION] as $header) {
            foreach ([
                'resources/list' => [],
                'resources/templates/list' => [],
                'resources/read' => ['uri' => 'voxelbooking://policy'],
                'prompts/list' => [],
                'prompts/get' => ['name' => 'review_day'],
            ] as $method => $params) {
                $response = $this->transport()->handle('POST', $this->headers($header), $this->body($method, $params));
                self::assertSame(200, $response->status, $method);
                self::assertSame(-32601, $response->body['error']['code'], $method);
                self::assertSame(7, $response->body['id'], $method);
            }
        }
    }

    public function testModernUnconfiguredResourcesMatchModernUnknownMethods(): void
    {
        $params = ['_meta' => [
            'io.modelcontextprotocol/protocolVersion' => Server::MODERN_VERSION,
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ]];
        $headers = $this->headers(Server::MODERN_VERSION) + ['Mcp-Method' => 'resources/list'];
        $response = $this->transport()->handle('POST', $headers, $this->body('resources/list', $params));
        self::assertSame(404, $response->status);
        self::assertSame(-32601, $response->body['error']['code']);
    }
}
