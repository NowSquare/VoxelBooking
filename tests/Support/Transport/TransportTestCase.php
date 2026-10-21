<?php

declare(strict_types=1);

namespace Tests\Support\Transport;

use App\Agent\{ActorContext, ConnectionAuthenticator, ConnectionService, Endpoints, Executor, OperationDefinition, Operations, OperationRegistry, PreviewStore, PrincipalResolver, PublicContinuation, SchemaValidator};
use App\Engine\{Auth, CustomerCapability, Database, Locale, Ulid};
use PHPUnit\Framework\TestCase;
use Tests\Integration\TestFixtures;
use Tests\Support\{ReviewLink, TestEnvironment};

/**
 * Successful operations over the real wire: the HTTP v2 API and the native MCP endpoint.
 *
 * Every call is a plain curl request against the disposable loopback server, authenticated
 * by a connection that holds only the scopes its operations require. A reviewed command
 * travels the published route: preview over the transport, approval by the person who
 * would approve it (staff in the admin, a customer by review link), then commit with an
 * idempotency key. The approval step stands in for the browser, exactly as the existing
 * contract tests do; it never runs through the agent's own credential.
 */
abstract class TransportTestCase extends TestCase
{
    protected const TRANSPORTS = ['http', 'mcp'];

    protected OperationRegistry $registry;
    protected Executor $executor;
    /** @var list<string> */
    protected array $connectionIds = [];
    /** @var list<string> */
    protected array $tenantIds = [];
    private array $savedSession = [];

    protected function setUp(): void
    {
        TestEnvironment::boot();
        TestFixtures::provision();
        Locale::reset();
        Locale::init(dirname(__DIR__, 3));
        Auth::startSession();
        $this->savedSession = $_SESSION;
        $_SESSION = ['auth_type' => 'operator', 'auth_id' => TestFixtures::OPERATOR_ID];
        $this->registry = Operations::registry();
        $this->executor = new Executor($this->registry);
        $this->resetBudgets();
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
        foreach ($this->connectionIds as $id) {
            Database::execute('UPDATE agent_connections SET revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP()) WHERE id=?', [$id]);
        }
        foreach ($this->tenantIds as $id) {
            Database::execute('DELETE FROM tenants WHERE id=?', [$id]);
        }
    }

    /** Rate exhaustion has its own tests; each scenario starts with its own budget. */
    protected function resetBudgets(): void
    {
        TestEnvironment::assertDatabase();
        Database::execute("DELETE FROM rate_limits WHERE endpoint_group='agent'");
        Database::execute('DELETE FROM agent_rate_budgets');
    }

    protected function tenant(string $pattern, array $columns = []): array
    {
        $id = Ulid::generate();
        $slug = 'transport-' . strtolower($id);
        $row = array_replace([
            'id' => $id, 'name' => 'Transport ' . $pattern, 'slug' => $slug, 'email' => 'transport@example.test',
            'booking_pattern' => $pattern, 'timezone' => 'UTC', 'currency' => 'EUR', 'status' => 'active',
        ], $columns);
        Database::execute(
            'INSERT INTO tenants (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')',
            array_values($row)
        );
        $this->tenantIds[] = $id;
        return ['id' => $id, 'slug' => $row['slug']];
    }

    /** The signed-in person who grants the connection and approves its reviews in the admin. */
    protected function operatorSession(array $businessIds): ActorContext
    {
        return (new PrincipalResolver())->resolve('operator', TestFixtures::OPERATOR_ID, $businessIds, $this->allScopes(), authMode: 'session');
    }

    protected function ownerSession(): ActorContext
    {
        return (new PrincipalResolver())->resolve('business_user', TestFixtures::BUSINESS_USER_ID, [TestFixtures::BUSINESS_TENANT_ID], $this->allScopes(), authMode: 'session');
    }

    protected function allScopes(): array
    {
        $scopes = [];
        foreach ($this->registry->all() as $definition) {
            $scopes = [...$scopes, ...$definition->requiredScopes];
        }
        return array_values(array_unique($scopes));
    }

    /** The union of the scopes the named operations require; nothing broader. */
    protected function scopesFor(array $operations): array
    {
        $scopes = [];
        foreach ($operations as $name) {
            $definition = $this->registry->get($name);
            self::assertNotNull($definition, $name);
            $scopes = [...$scopes, ...$definition->requiredScopes];
        }
        $scopes = array_values(array_unique($scopes));
        // A grant needs at least one scope; identity operations are scope-free.
        return $scopes === [] ? ['account:read'] : $scopes;
    }

    /**
     * One connection with one credential per transport audience.
     *
     * @return array{actor: ActorContext, reviewer: ActorContext, grant: string, tokens: array{http: string, mcp: string}}
     */
    protected function connect(ActorContext $session, array $operations, ?array $businessIds = null): array
    {
        $service = new ConnectionService($this->registry);
        $expiry = gmdate('Y-m-d H:i:s', time() + 1800);
        $grant = $service->createGrant($session, 'Transport evidence', $this->scopesFor($operations), $businessIds ?? $session->businessIds, $expiry, 'Disposable transport verification');
        $this->connectionIds[] = $grant['id'];
        $tokens = [];
        foreach (self::TRANSPORTS as $transport) {
            $tokens[$transport] = $service->issueCredential($grant['id'], Endpoints::resource($transport), $expiry)['raw_token'];
        }
        return [
            'actor' => (new ConnectionAuthenticator())->authenticate($tokens['http'], Endpoints::resource('http')),
            'reviewer' => $session,
            'grant' => $grant['id'],
            'tokens' => $tokens,
        ];
    }

    /** A customer delegates their verified booking or privacy capability, as the browser consent page does. */
    protected function connectCustomer(string $businessId, string $subject, string $purpose): array
    {
        $secret = $purpose === CustomerCapability::MANAGE
            ? CustomerCapability::issueManage($businessId, $subject)
            : CustomerCapability::issuePrivacy($businessId, $subject);
        $grant = CustomerCapability::verify($secret, $purpose, $businessId, $subject);
        self::assertTrue(CustomerCapability::remember($secret, $purpose, $businessId, $subject));
        $source = CustomerCapability::actorById($grant['id']);
        $service = new ConnectionService($this->registry);
        $expiry = gmdate('Y-m-d H:i:s', time() + 1200);
        $connection = $service->createCustomerGrant($source, 'Customer transport evidence', $source->scopes, $expiry, 'Disposable transport verification');
        $this->connectionIds[] = $connection['id'];
        $tokens = [];
        foreach (self::TRANSPORTS as $transport) {
            $tokens[$transport] = $service->issueCredential($connection['id'], Endpoints::resource($transport), $expiry)['raw_token'];
        }
        return [
            'actor' => (new ConnectionAuthenticator())->authenticate($tokens['http'], Endpoints::resource('http')),
            'reviewer' => null,
            'grant' => $connection['id'],
            'tokens' => $tokens,
        ];
    }

    /** An anonymous platform session (no business), as an agent starts it over each transport. */
    protected function publicPlatformSession(): array
    {
        $issued = (new PublicContinuation())->issue('platform');
        return ['public' => true, 'slug' => null, 'continuation' => $issued['continuation'], 'tokens' => ['http' => null, 'mcp' => null], 'reviewer' => null];
    }

    /**
     * Perform one successful call over a transport and return the published envelope.
     *
     * Reviewed commands are previewed over the same transport, approved by the
     * appropriate person, then committed with the preview handle and an idempotency key.
     */
    protected function call(string $transport, array $client, string $operation, array $input): array
    {
        $definition = $this->registry->get($operation);
        self::assertNotNull($definition, $operation);
        $reviewed = !$definition->readOnly && $definition->confirmationPolicy !== 'none';
        $key = $definition->readOnly ? null : 'transport-' . Ulid::generate();
        $arguments = $input;
        if ($reviewed) {
            $preview = $transport === 'http'
                ? $this->http($client, $definition, $input, true)
                : $this->mcp($client, $operation . '_preview', $input);
            self::assertTrue($preview['approval_required'], $operation . ' preview over ' . $transport);
            self::assertFalse($preview['reserves_inventory'], $operation . ' preview over ' . $transport);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $preview['preview_handle']);
            $this->approve($client, $preview);
            $arguments = ['preview_handle' => $preview['preview_handle']];
        }
        $envelope = $transport === 'http'
            ? $this->http($client, $definition, $input, false, $arguments, $key)
            : $this->mcp($client, $operation, $key === null ? $arguments : $arguments + ['idempotency_key' => $key]);
        $this->assertEnvelope($definition, $envelope, $transport);
        return $envelope;
    }

    protected function assertEnvelope(OperationDefinition $definition, array $envelope, string $transport): void
    {
        $candidate = $envelope;
        if (($candidate['data'] ?? null) === [] && ($definition->outputSchema['type'] ?? '') === 'object') {
            $candidate['data'] = new \stdClass();
        }
        (new SchemaValidator())->validate($definition->resultSchema(), $candidate, true);
        self::assertSame($definition->operationId, $envelope['operation'], $transport);
        if ($definition->readOnly) {
            self::assertNull($envelope['receipt_id'], $definition->operationId . ' over ' . $transport);
        } else {
            self::assertIsString($envelope['receipt_id'], $definition->operationId . ' over ' . $transport);
            self::assertSame(1, (int) Database::query(
                'SELECT COUNT(*) AS n FROM agent_operation_receipts WHERE id=? AND operation_id=?',
                [$envelope['receipt_id'], $definition->operationId]
            )[0]['n'], $definition->operationId . ' receipt over ' . $transport);
        }
    }

    /** Staff approve in the signed-in admin; customers and the public through their review link. */
    protected function approve(array $client, array $preview): void
    {
        $id = ReviewLink::id($preview);
        $store = new PreviewStore();
        if ($client['reviewer'] instanceof ActorContext) {
            $store->approve($client['reviewer'], $id);
            return;
        }
        $store->exchangeBrowserHandle($id, ReviewLink::secret($preview));
        $store->approveCustomerBrowser($id, true);
    }

    protected function http(array $client, OperationDefinition $definition, array $input, bool $preview, ?array $body = null, ?string $key = null): array
    {
        $response = $this->httpRaw($client, $definition, $input, $preview, $body, $key);
        self::assertSame(200, $response['status'], $definition->operationId . ($preview ? ' preview' : '') . ' over HTTP: ' . $response['body']);
        return $response['json'];
    }

    /** @return array{status: int, body: string, json: mixed, path: string} */
    protected function httpRaw(array $client, OperationDefinition $definition, array $input, bool $preview = false, ?array $body = null, ?string $key = null): array
    {
        $selectors = $input + ['slug' => $client['slug'] ?? null];
        $path = '/api/agent/v2' . preg_replace_callback(
            '/\{([^}]+)\}/',
            static fn(array $match): string => rawurlencode((string) $selectors[$match[1]]),
            $definition->httpPath
        );
        $method = $preview ? 'POST' : $definition->httpMethod;
        if ($preview) {
            $path .= '/preview';
        }
        $payload = $body ?? $input;
        if ($method === 'GET') {
            $query = [];
            foreach ($payload as $name => $value) {
                $query[$name] = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (is_bool($value) ? ($value ? 'true' : 'false') : $value);
            }
            if ($query !== []) {
                $path .= '?' . http_build_query($query);
            }
        }
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if (($client['tokens']['http'] ?? null) !== null) {
            $headers[] = 'Authorization: Bearer ' . $client['tokens']['http'];
        }
        if (isset($client['continuation'])) {
            $headers[] = 'X-Booking-Continuation: ' . $client['continuation'];
        }
        if ($key !== null) {
            $headers[] = 'Idempotency-Key: ' . $key;
        }
        $curl = curl_init(Endpoints::baseUrl() . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
        if ($method !== 'GET') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload === [] ? new \stdClass() : $payload, JSON_THROW_ON_ERROR));
        }
        $raw = (string) curl_exec($curl);
        return ['status' => (int) curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => $raw, 'json' => json_decode($raw, true), 'path' => $path];
    }

    /** Call a native MCP tool over HTTP POST /mcp (or /mcp/public) and return its structured content. */
    protected function mcp(array $client, string $tool, array $arguments): array
    {
        $response = $this->mcpRaw($client, $tool, $arguments);
        self::assertSame(200, $response['status'], $tool . ' over MCP: ' . $response['body']);
        self::assertFalse($response['json']['result']['isError'] ?? true, $tool . ' over MCP: ' . $response['body']);
        $content = $response['json']['result']['structuredContent'];
        // The text block carries the same JSON for clients without structured content.
        self::assertSame($content, json_decode($response['json']['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR));
        return $content;
    }

    /** @return array{status: int, body: string, json: mixed} */
    protected function mcpRaw(array $client, string $tool, array $arguments): array
    {
        $path = '/mcp';
        if (!empty($client['public'])) {
            $path = '/mcp/public' . ($client['slug'] !== null ? '/' . $client['slug'] : '');
            $arguments['continuation'] = $client['continuation'];
        }
        $headers = ['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2025-11-25'];
        if (($client['tokens']['mcp'] ?? null) !== null) {
            $headers[] = 'Authorization: Bearer ' . $client['tokens']['mcp'];
        }
        $curl = curl_init(Endpoints::baseUrl() . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode([
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $arguments === [] ? new \stdClass() : $arguments],
            ], JSON_THROW_ON_ERROR)]);
        $raw = (string) curl_exec($curl);
        return ['status' => (int) curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => $raw, 'json' => json_decode($raw, true)];
    }

    /** Setup only: an in-process command by the signed-in person, approved in place when the operation is reviewed. */
    protected function seedCommand(ActorContext $session, string $operation, array $input): array
    {
        $definition = $this->registry->get($operation);
        if ($definition->confirmationPolicy !== 'none') {
            $preview = $this->executor->preview($session, $operation, $input);
            (new PreviewStore())->approve($session, ReviewLink::id($preview));
            $input = ['preview_handle' => $preview['preview_handle']];
        }
        return $this->executor->execute($session, $operation, $input, 'seed-' . Ulid::generate());
    }

    /** A trusted in-process read, used only to fetch fresh versions and confirm persisted effects. */
    protected function read(ActorContext $session, string $operation, array $input): array
    {
        return $this->executor->execute($session, $operation, $input)['data'];
    }
}
