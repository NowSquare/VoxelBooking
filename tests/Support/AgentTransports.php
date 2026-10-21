<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Agent\{ActorContext, ConnectionAuthenticator, ConnectionService, Endpoints, Executor, Operations, OperationRegistry, PreviewStore};

/**
 * Drives one operation through the three adapters a client can use: the
 * Executor in process, the HTTP v2 routes and the native MCP endpoint, both on
 * the disposable loopback server. Staff reviews are approved the way the
 * trusted browser approves them, by the person the connection represents.
 */
final class AgentTransports
{
    public readonly OperationRegistry $registry;
    public readonly Executor $executor;

    public function __construct()
    {
        $this->registry = Operations::registry();
        $this->executor = new Executor($this->registry);
    }

    /**
     * Grant a connection the way the admin screen does and issue one token per transport.
     *
     * @return array{actor: ActorContext, connection_id: string, tokens: array{http: string, mcp: string}, reviewer: ActorContext}
     */
    public function connect(ActorContext $session, ?array $scopes = null, int $ttl = 1200): array
    {
        $service = new ConnectionService($this->registry);
        $expiry = gmdate('Y-m-d H:i:s', time() + $ttl);
        if ($scopes === null) {
            $scopes = [];
            foreach ($this->registry->visible($session) as $definition) {
                $scopes = [...$scopes, ...$definition->requiredScopes];
            }
        }
        $grant = $service->createGrant(
            $session,
            'Transport test',
            array_values(array_unique($scopes)),
            $session->businessIds,
            $expiry,
            'Disposable transport verification'
        );
        $tokens = [];
        foreach (['http', 'mcp'] as $transport) {
            $tokens[$transport] = $service->issueCredential($grant['id'], Endpoints::resource($transport), $expiry)['raw_token'];
        }

        return [
            'actor' => (new ConnectionAuthenticator())->authenticate($tokens['http'], Endpoints::resource('http')),
            'connection_id' => $grant['id'],
            'tokens' => $tokens,
            'reviewer' => $session,
        ];
    }

    /** Staff reviewed writes need the represented person's browser approval before commit. */
    public function approve(array $client, array $preview): void
    {
        $id = basename((string) parse_url($preview['review_url'], PHP_URL_PATH));
        (new PreviewStore())->approve($client['reviewer'], $id);
    }

    /** @return array the Executor's result envelope */
    public function executor(array $client, string $name, array $input): array
    {
        $definition = $this->registry->get($name) ?? throw new \LogicException('Unknown operation ' . $name);
        $key = null;
        if (!$definition->readOnly) {
            $key = 'transport-' . bin2hex(random_bytes(8));
            if ($definition->confirmationPolicy !== 'none') {
                $preview = $this->executor->preview($client['actor'], $name, $input);
                $this->approve($client, $preview);
                $input = ['preview_handle' => $preview['preview_handle']];
            }
        }

        return $this->executor->execute($client['actor'], $name, $input, $key);
    }

    /** @return array{status: int, body: array, headers: string} */
    public function http(array $client, string $name, array $input): array
    {
        $definition = $this->registry->get($name) ?? throw new \LogicException('Unknown operation ' . $name);
        $path = '/api/agent/v2' . preg_replace_callback(
            '/\{([^}]+)\}/',
            static function (array $match) use (&$input): string {
                $value = $input[$match[1]];
                unset($input[$match[1]]);
                return rawurlencode($value);
            },
            $definition->httpPath
        );
        $token = $client['tokens']['http'];
        if ($definition->httpMethod === 'GET') {
            return $this->request('GET', $path . ($input === [] ? '' : '?' . http_build_query($input)), $token);
        }
        $key = 'transport-' . bin2hex(random_bytes(8));
        if ($definition->confirmationPolicy !== 'none') {
            $preview = $this->request('POST', $path . '/preview', $token, $input);
            if ($preview['status'] !== 200) {
                return $preview;
            }
            $this->approve($client, $preview['body']);
            $input = ['preview_handle' => $preview['body']['preview_handle']];
        }

        return $this->request('POST', $path, $token, $input, ['Idempotency-Key: ' . $key]);
    }

    /** @return array{status: int, body: array, headers: string} the JSON-RPC response of a tools/call */
    public function mcp(array $client, string $name, array $input): array
    {
        $definition = $this->registry->get($name) ?? throw new \LogicException('Unknown operation ' . $name);
        $token = $client['tokens']['mcp'];
        if (!$definition->readOnly) {
            $input['idempotency_key'] = 'transport-' . bin2hex(random_bytes(8));
            if ($definition->confirmationPolicy !== 'none') {
                $arguments = $input;
                unset($arguments['idempotency_key']);
                $preview = $this->call($token, $definition->mcpToolName . '_preview', $arguments);
                $review = $preview['body']['result']['structuredContent'] ?? null;
                if (!is_array($review) || ($preview['body']['result']['isError'] ?? true)) {
                    return $preview;
                }
                $this->approve($client, $review);
                $input = ['preview_handle' => $review['preview_handle'], 'idempotency_key' => $input['idempotency_key']];
            }
        }

        return $this->call($token, $definition->mcpToolName, $input);
    }

    /** @return array{status: int, body: array, headers: string} */
    public function call(?string $token, string $tool, array $arguments, string $path = '/mcp', array $headers = []): array
    {
        return $this->request('POST', $path, $token, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => (object) $arguments],
        ], $headers === [] ? ['MCP-Protocol-Version: ' . \App\Agent\Mcp\Server::LEGACY_VERSION] : $headers);
    }

    /** @return array{status: int, body: array, headers: string} */
    public function request(string $method, string $path, ?string $token = null, ?array $body = null, array $headers = []): array
    {
        $sent = ['Content-Type: application/json', 'Accept: application/json, text/event-stream', ...$headers];
        if ($token !== null) {
            $sent[] = 'Authorization: Bearer ' . $token;
        }
        $curl = curl_init(Endpoints::baseUrl() . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $sent,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        $raw = (string) curl_exec($curl);
        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $decoded = json_decode(substr($raw, $headerSize), true);

        return [
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : ['raw' => substr($raw, $headerSize)],
            'headers' => substr($raw, 0, $headerSize),
        ];
    }
}
