<?php

declare(strict_types=1);

namespace App\Agent\Mcp;

use Closure;
use stdClass;

/** Transport-independent protocol methods backed by the authorized application catalog. */
final class Server
{
    public const MODERN_VERSION = '2026-07-28';
    public const LEGACY_VERSION = '2025-11-25';
    public const SUPPORTED_VERSIONS = [self::MODERN_VERSION, self::LEGACY_VERSION];
    private readonly string $cursorSecret;

    public function __construct(
        private readonly Closure $tools,
        private readonly Closure $call,
        private readonly array $serverInfo,
        private readonly ?Closure $resources = null,
        private readonly ?Closure $readResource = null,
        private readonly ?Closure $prompts = null,
        private readonly ?Closure $getPrompt = null,
        ?string $cursorSecret = null,
    ) {
        // Production callers pass a persistent secret derived for the current actor/grant.
        $this->cursorSecret = $cursorSecret ?? bin2hex(random_bytes(32));
    }

    public function dispatch(string $method, stdClass $params, array $context, array $headers = []): array|stdClass
    {
        $modern = $context['protocol_version'] === self::MODERN_VERSION;
        $result = match ($method) {
            'server/discover' => $modern ? $this->discover() : $this->unknownMethod(false),
            'initialize' => !$modern ? $this->initialize($params) : $this->unknownMethod(true),
            'ping' => !$modern ? new stdClass() : $this->unknownMethod(true),
            'tools/list' => $this->listTools($params),
            'tools/call' => $this->callTool($params, $context, $headers),
            'resources/list' => $this->listResources($params),
            'resources/templates/list' => $this->resourceTemplates($params),
            'resources/read' => $this->readResource($params),
            'prompts/list' => $this->listPrompts($params),
            'prompts/get' => $this->getPrompt($params),
            default => $this->unknownMethod($modern),
        };
        if ($modern) {
            $result['resultType'] = 'complete';
            $result['_meta']['io.modelcontextprotocol/serverInfo'] = $this->serverInfo;
            if (in_array($method, ['server/discover', 'tools/list', 'resources/list', 'resources/read', 'resources/templates/list', 'prompts/list'], true)) {
                // Discovery and resources can change when the user's role or grant changes.
                $result['ttlMs'] = 0;
                $result['cacheScope'] = 'private';
            }
        }
        return $result;
    }

    private function capabilities(): array
    {
        $capabilities = ['tools' => new stdClass()];
        if ($this->resources !== null && $this->readResource !== null) {
            $capabilities['resources'] = new stdClass();
        }
        if ($this->prompts !== null && $this->getPrompt !== null) {
            $capabilities['prompts'] = new stdClass();
        }
        return $capabilities;
    }

    private function discover(): array
    {
        return ['supportedVersions' => self::SUPPORTED_VERSIONS, 'capabilities' => $this->capabilities()];
    }

    private function initialize(stdClass $params): array
    {
        if (!is_string($params->protocolVersion ?? null) || $params->protocolVersion === ''
            || !($params->capabilities ?? null) instanceof stdClass
            || !($params->clientInfo ?? null) instanceof stdClass
            || !is_string($params->clientInfo->name ?? null)
            || !is_string($params->clientInfo->version ?? null)) {
            throw new ProtocolError(-32602, 'Invalid initialization params');
        }
        return [
            'protocolVersion' => self::LEGACY_VERSION,
            'capabilities' => $this->capabilities(),
            'serverInfo' => $this->serverInfo,
        ];
    }

    private function listTools(stdClass $params): array
    {
        $tools = ($this->tools)();
        usort($tools, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
        return $this->page('tools', $tools, $params);
    }

    private function callTool(stdClass $params, array $context, array $headers): array
    {
        if (!is_string($params->name ?? null) || $params->name === ''
            || (property_exists($params, 'arguments') && !$params->arguments instanceof stdClass)) {
            throw new ProtocolError(-32602, 'Invalid tool call params');
        }
        $definition = null;
        foreach (($this->tools)() as $tool) {
            if ($tool['name'] === $params->name) {
                $definition = $tool;
                break;
            }
        }
        if ($definition === null) {
            throw new ProtocolError(-32602, 'Unknown tool');
        }
        $arguments = json_decode(json_encode($params->arguments ?? new stdClass(), JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
        if ($context['protocol_version'] === self::MODERN_VERSION) {
            HeaderValidator::parameters($headers, $definition['inputSchema'], $arguments);
        }
        try {
            $result = ($this->call)($params->name, $arguments, $context);
            return [
                'content' => [['type' => 'text', 'text' => json_encode($result === [] ? new stdClass() : $result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
                'structuredContent' => $result === [] ? new stdClass() : $result,
                'isError' => false,
            ];
        } catch (ToolFailure $error) {
            // The successful output schema does not describe problem documents.
            return [
                'content' => [['type' => 'text', 'text' => json_encode($error->problem, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
                'isError' => true,
                '_meta' => ['com.voxelbooking/problem' => $error->problem],
            ];
        }
    }

    private function listResources(stdClass $params): array
    {
        $this->requireResources();
        $resources = ($this->resources)();
        usort($resources, static fn (array $left, array $right): int => strcmp($left['uri'], $right['uri']));
        return $this->page('resources', $resources, $params);
    }

    private function resourceTemplates(stdClass $params): array
    {
        $this->requireResources();
        $this->noCursor($params);
        return ['resourceTemplates' => []];
    }

    private function readResource(stdClass $params): array
    {
        $this->requireResources();
        if (!is_string($params->uri ?? null)) {
            throw new ProtocolError(-32602, 'Invalid resource params');
        }
        foreach (($this->resources)() as $resource) {
            if ($resource['uri'] === $params->uri) {
                return ($this->readResource)($params->uri);
            }
        }
        throw new ProtocolError(-32602, 'Unknown resource');
    }

    private function listPrompts(stdClass $params): array
    {
        $this->requirePrompts();
        $prompts = ($this->prompts)();
        usort($prompts, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
        return $this->page('prompts', $prompts, $params);
    }

    private function getPrompt(stdClass $params): array
    {
        $this->requirePrompts();
        if (!is_string($params->name ?? null)
            || (property_exists($params, 'arguments') && !$params->arguments instanceof stdClass)) {
            throw new ProtocolError(-32602, 'Invalid prompt params');
        }
        $arguments = (array) ($params->arguments ?? new stdClass());
        foreach ($arguments as $value) {
            if (!is_string($value)) {
                throw new ProtocolError(-32602, 'Invalid prompt arguments');
            }
        }
        foreach (($this->prompts)() as $prompt) {
            if ($prompt['name'] === $params->name) {
                foreach ($prompt['arguments'] ?? [] as $argument) {
                    if (($argument['required'] ?? false) && !array_key_exists($argument['name'], $arguments)) {
                        throw new ProtocolError(-32602, 'Missing prompt argument');
                    }
                }
                return ($this->getPrompt)($params->name, $arguments);
            }
        }
        throw new ProtocolError(-32602, 'Unknown prompt');
    }

    private function requireResources(): void
    {
        if ($this->resources === null || $this->readResource === null) {
            throw new ProtocolError(-32601, 'Method not found', 404);
        }
    }

    private function requirePrompts(): void
    {
        if ($this->prompts === null || $this->getPrompt === null) {
            throw new ProtocolError(-32601, 'Method not found', 404);
        }
    }

    private function noCursor(stdClass $params): void
    {
        if (property_exists($params, 'cursor')) {
            throw new ProtocolError(-32602, 'Invalid cursor');
        }
    }

    private function page(string $kind, array $items, stdClass $params): array
    {
        $digest = hash('sha256', $kind . json_encode($items, JSON_THROW_ON_ERROR));
        $offset = 0;
        if (property_exists($params, 'cursor')) {
            if (!is_string($params->cursor) || strlen($params->cursor) > 512) {
                throw new ProtocolError(-32602, 'Invalid cursor');
            }
            $parts = explode('.', $params->cursor);
            if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], $this->cursorSecret), $parts[1])) {
                throw new ProtocolError(-32602, 'Invalid cursor');
            }
            $cursor = json_decode(base64_decode(strtr($parts[0], '-_', '+/'), true) ?: '', true);
            if (!is_array($cursor) || ($cursor['digest'] ?? null) !== $digest
                || !is_int($cursor['offset'] ?? null) || $cursor['offset'] < 1 || $cursor['offset'] >= count($items)) {
                throw new ProtocolError(-32602, 'Invalid cursor');
            }
            $offset = $cursor['offset'];
        }
        $result = [$kind => array_slice($items, $offset, 50)];
        if ($offset + 50 < count($items)) {
            $encoded = rtrim(strtr(base64_encode(json_encode(['digest' => $digest, 'offset' => $offset + 50], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            $result['nextCursor'] = $encoded . '.' . hash_hmac('sha256', $encoded, $this->cursorSecret);
        }
        return $result;
    }

    private function unknownMethod(bool $modern): never
    {
        throw new ProtocolError(-32601, 'Method not found', $modern ? 404 : 200);
    }
}
