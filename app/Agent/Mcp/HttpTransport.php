<?php

declare(strict_types=1);

namespace App\Agent\Mcp;

use JsonException;
use stdClass;
use Throwable;

/**
 * Request-scoped Streamable HTTP with JSON responses.
 * Authenticate and rate-limit every request before invoking this adapter.
 */
final class HttpTransport
{
    private const MAX_BODY_BYTES = 1_048_576;
    private const RESPONSE_HEADERS = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private readonly Server $server,
        private readonly array $allowedOrigins,
        private readonly ?\Closure $onInternalError = null,
    ) {
    }

    public function handle(string $method, array $headers, string $body): HttpResponse
    {
        $id = null;
        try {
            $headers = array_change_key_case($headers, CASE_LOWER);
            if (array_key_exists('origin', $headers)
                && (!is_string($headers['origin']) || !in_array($headers['origin'], $this->allowedOrigins, true) || $headers['origin'] === '')) {
                throw new ProtocolError(-32600, 'Origin not allowed', 403);
            }
            if ($method !== 'POST') {
                return new HttpResponse(405, null, self::RESPONSE_HEADERS + ['Allow' => 'POST']);
            }
            if (strlen($body) > self::MAX_BODY_BYTES) {
                throw new ProtocolError(-32600, 'Request too large', 413);
            }
            if (!is_string($headers['content-type'] ?? null)
                || strtolower(trim(explode(';', $headers['content-type'])[0])) !== 'application/json') {
                throw new ProtocolError(-32600, 'Unsupported content type', 415);
            }
            if (!$this->accepts($headers['accept'] ?? '', 'application/json')
                || !$this->accepts($headers['accept'] ?? '', 'text/event-stream')) {
                throw new ProtocolError(-32600, 'Accept must include application/json and text/event-stream', 406);
            }
            try {
                $message = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new ProtocolError(-32700, 'Parse error');
            }
            if (!$message instanceof stdClass || ($message->jsonrpc ?? null) !== '2.0'
                || !is_string($message->method ?? null) || $message->method === ''
                || property_exists($message, 'result') || property_exists($message, 'error')) {
                throw new ProtocolError(-32600, 'Invalid request');
            }
            $notification = !property_exists($message, 'id');
            if (!$notification) {
                if (!is_int($message->id) && !is_string($message->id)) {
                    throw new ProtocolError(-32600, 'Invalid request');
                }
                $id = $message->id;
            }
            $params = $message->params ?? new stdClass();
            if (!$params instanceof stdClass || (property_exists($message, 'params') && $message->params === null)) {
                throw new ProtocolError(-32602, 'Invalid params');
            }
            $version = $this->protocolVersion($message->method, $params, $headers);
            if ($notification) {
                // A notification can never invoke an application operation.
                if ($version === Server::LEGACY_VERSION && in_array($message->method, ['notifications/initialized', 'notifications/cancelled'], true)) {
                    return new HttpResponse(202, null, self::RESPONSE_HEADERS);
                }
                throw new ProtocolError(-32600, 'Unsupported notification');
            }
            if ($version === Server::MODERN_VERSION) {
                HeaderValidator::matching($headers, 'Mcp-Method', $message->method);
                $nameField = match ($message->method) {
                    'tools/call', 'prompts/get' => 'name',
                    'resources/read' => 'uri',
                    default => null,
                };
                if ($nameField !== null) {
                    if (!is_string($params->{$nameField} ?? null)) {
                        throw new ProtocolError(-32602, 'Invalid params');
                    }
                    HeaderValidator::matching($headers, 'Mcp-Name', $params->{$nameField}, true);
                }
            }
            $result = $this->server->dispatch($message->method, $params, [
                'request_id' => $id,
                'protocol_version' => $version,
                'client_info' => (array) ($params->_meta->{'io.modelcontextprotocol/clientInfo'} ?? $params->clientInfo ?? new stdClass()),
                'idempotency_key' => $this->idempotencyKey($params, $headers),
            ], $headers);
            return new HttpResponse(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], self::RESPONSE_HEADERS);
        } catch (ProtocolError $error) {
            return $this->errorResponse($error, $id);
        } catch (Throwable $error) {
            // Logging is an injected redacting observer, never a raw HTTP response.
            if ($this->onInternalError !== null) {
                try {
                    ($this->onInternalError)($error);
                } catch (Throwable) {
                    // A failed observer cannot expose the original exception.
                }
            }
            return $this->errorResponse(new ProtocolError(-32603, 'Internal error', 500), $id);
        }
    }

    private function protocolVersion(string $method, stdClass $params, array $headers): string
    {
        $meta = $params->_meta ?? new stdClass();
        if (!$meta instanceof stdClass || (property_exists($params, '_meta') && $params->_meta === null)) {
            throw new ProtocolError(-32602, 'Invalid params');
        }
        $bodyVersion = $meta->{'io.modelcontextprotocol/protocolVersion'} ?? null;
        // Only a real initialize request can enter legacy negotiation without headers.
        if ($method === 'initialize' && $bodyVersion === null && ($headers['mcp-protocol-version'] ?? Server::LEGACY_VERSION) !== Server::MODERN_VERSION) {
            return Server::LEGACY_VERSION;
        }
        if ($bodyVersion === null && ($headers['mcp-protocol-version'] ?? null) === Server::LEGACY_VERSION) {
            return Server::LEGACY_VERSION;
        }
        if (!is_string($bodyVersion) || $bodyVersion === '') {
            throw new ProtocolError(-32602, 'Missing required protocol metadata');
        }
        HeaderValidator::matching($headers, 'MCP-Protocol-Version', $bodyVersion);
        if ($bodyVersion !== Server::MODERN_VERSION) {
            throw new ProtocolError(-32022, 'Unsupported protocol version', data: [
                'supported' => Server::SUPPORTED_VERSIONS,
                'requested' => $bodyVersion,
            ]);
        }
        if (!($meta->{'io.modelcontextprotocol/clientCapabilities'} ?? null) instanceof stdClass) {
            throw new ProtocolError(-32602, 'Missing required protocol metadata');
        }
        if (property_exists($meta, 'io.modelcontextprotocol/clientInfo')) {
            $info = $meta->{'io.modelcontextprotocol/clientInfo'};
            if (!$info instanceof stdClass || !is_string($info->name ?? null) || !is_string($info->version ?? null)) {
                throw new ProtocolError(-32602, 'Invalid client metadata');
            }
        }
        return $bodyVersion;
    }

    private function idempotencyKey(stdClass $params, array $headers): ?string
    {
        $metaKey = $params->_meta->{'com.voxelbooking/idempotencyKey'} ?? null;
        $headerKey = $headers['idempotency-key'] ?? null;
        if ($metaKey !== null && $headerKey !== null && $metaKey !== $headerKey) {
            throw new ProtocolError(-32602, 'Conflicting idempotency keys');
        }
        $key = $metaKey ?? $headerKey;
        if ($key !== null && (!is_string($key) || !preg_match('/^[\x21-\x7E]{1,128}$/D', $key))) {
            throw new ProtocolError(-32602, 'Invalid idempotency key');
        }
        return $key;
    }

    private function accepts(mixed $header, string $mediaType): bool
    {
        if (!is_string($header)) {
            return false;
        }
        foreach (explode(',', strtolower($header)) as $entry) {
            $parts = array_map('trim', explode(';', $entry));
            if ($parts[0] !== $mediaType) {
                continue;
            }
            foreach (array_slice($parts, 1) as $parameter) {
                if (preg_match('/^q\s*=\s*(.+)$/', $parameter, $matches) && (float) $matches[1] <= 0) {
                    continue 2;
                }
            }
            return true;
        }
        return false;
    }

    private function errorResponse(ProtocolError $error, int|string|null $id): HttpResponse
    {
        $body = ['jsonrpc' => '2.0'];
        if ($id !== null) {
            $body['id'] = $id;
        }
        $body['error'] = ['code' => $error->getCode(), 'message' => $error->getMessage()];
        if ($error->data !== null) {
            $body['error']['data'] = $error->data;
        }
        return new HttpResponse($error->httpStatus, $body, self::RESPONSE_HEADERS);
    }
}
