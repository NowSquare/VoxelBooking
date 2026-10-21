<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Agent\ActorContext;
use App\Agent\Endpoints;
use App\Agent\Executor;
use App\Agent\Mcp\ContractGenerator;
use App\Agent\OperationDefinition;
use App\Agent\OperationException;
use App\Agent\OperationRegistry;
use App\Agent\Operations;
use App\Agent\PayloadCipher;
use App\Agent\PrincipalResolver;
use App\Agent\SchemaValidator;
use App\Controllers\AgentApi\V2\PublicSessionController;
use App\Controllers\Mcp\McpController;
use App\Engine\Database;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * Everything the agent transports send back is checked against the contract they publish.
 *
 * A response that breaks its published schema is a server bug. The caller gets a
 * 500 `response_contract_failed` problem, never a body its generated client would reject.
 */
final class AgentResponseContractTest extends TestCase
{
    private const SLUG = 'test-fixture';

    protected function setUp(): void
    {
        TestFixtures::provision();
        \Tests\Support\CustomerFixtureState::restore();
        \Tests\Support\TestEnvironment::assertDatabase();
        Database::execute('DELETE FROM agent_rate_budgets');
    }

    protected function tearDown(): void
    {
        Database::execute(
            "DELETE FROM services WHERE tenant_id = ? AND name LIKE 'Contract fixture%'",
            [TestFixtures::BUSINESS_TENANT_ID]
        );
    }

    public function test_a_preview_that_breaks_the_published_preview_schema_is_a_server_error(): void
    {
        // A list where the contract promises an object for `review`.
        $executor = new Executor($this->registry(static fn (): array => ['first', 'second']));

        try {
            $executor->preview($this->actor(), 'fixture_reviewed_create', $this->arguments('Contract fixture broken preview'));
            $this->fail('A preview outside its published schema must not reach the caller.');
        } catch (OperationException $error) {
            $this->assertSame('response_contract_failed', $error->problemCode);
            $this->assertSame(500, $error->status);
            $this->assertSame([], $error->fieldErrors);
        }
    }

    public function test_a_valid_preview_matches_the_published_preview_tool(): void
    {
        $registry = $this->registry(static fn ($actor, array $arguments): array => ['name' => $arguments['name']]);
        $preview = (new Executor($registry))->preview(
            $this->actor(),
            'fixture_reviewed_create',
            $this->arguments('Contract fixture preview')
        );

        $tools = (new ContractGenerator())->tools($registry->all());
        $previewTool = array_values(array_filter($tools, static fn (array $tool): bool => $tool['name'] === 'fixture_reviewed_create_preview'))[0];
        (new SchemaValidator())->validate($previewTool['outputSchema'], $preview, true);
        $this->assertSame('Contract fixture preview', $preview['review']['name']);
    }

    public function test_a_replayed_envelope_that_breaks_the_result_schema_is_a_server_error(): void
    {
        $executor = new Executor($this->registry());
        $key = 'contract-' . Ulid::generate();
        $first = $executor->execute($this->actor(), 'fixture_create', $this->arguments('Contract fixture replay'), $key);
        (new SchemaValidator())->validate($this->registry()->get('fixture_create')->resultSchema(), $first, true);

        // The stored envelope drifts from the contract: the receipt ID is no longer a string or null.
        $tampered = $first;
        $tampered['receipt_id'] = 42;
        Database::execute(
            'UPDATE agent_operation_receipts SET result_cipher = ? WHERE id = ?',
            [(new PayloadCipher())->encrypt($tampered), $first['receipt_id']]
        );

        try {
            $executor->execute($this->actor(), 'fixture_create', $this->arguments('Contract fixture replay'), $key);
            $this->fail('A replay outside the result schema must not reach the caller.');
        } catch (OperationException $error) {
            $this->assertSame('response_contract_failed', $error->problemCode);
            $this->assertSame(500, $error->status);
        }
    }

    public function test_public_session_contract_is_declared_once_for_both_transports(): void
    {
        $schema = PublicSessionController::resultSchema();
        $document = (new ContractGenerator())->openApi(Operations::registry()->all(), 'https://booking.example/api/agent/v2', '2');
        foreach (['/public/session', '/public/{slug}/session'] as $path) {
            $declared = $document['paths'][$path]['post']['responses']['200']['content']['application/json']['schema'];
            $this->assertEquals($declared, $schema, $path);
        }

        $startTool = (new \ReflectionMethod(McpController::class, 'startTool'))->invoke(null);
        $this->assertEquals($schema, $startTool['outputSchema']);
    }

    public function test_a_public_session_outside_its_schema_is_a_server_error(): void
    {
        $this->expectExceptionObject(new OperationException('response_contract_failed', 500));
        (new SchemaValidator())->validate(PublicSessionController::resultSchema(), [
            'continuation' => 'token',
            'expires_at' => 'in two hours',
            'business_id' => TestFixtures::BUSINESS_TENANT_ID,
            'permissions' => ['public:read', 'public:book'],
        ], true);
    }

    public function test_public_session_start_responses_match_the_declared_schema_on_both_transports(): void
    {
        $schema = PublicSessionController::resultSchema();
        $http = $this->post('/api/agent/v2/public/' . self::SLUG . '/session', []);
        (new SchemaValidator())->validate($schema, $http, true);
        $this->assertSame(TestFixtures::BUSINESS_TENANT_ID, $http['business_id']);

        $native = $this->post('/mcp/public/' . self::SLUG, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'public_session_start', 'arguments' => (object) []],
        ]);
        $this->assertFalse($native['result']['isError'], json_encode($native));
        (new SchemaValidator())->validate($schema, $native['result']['structuredContent'], true);
    }

    private function post(string $path, array $body): array
    {
        $curl = curl_init(Endpoints::baseUrl() . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json, text/event-stream',
                'MCP-Protocol-Version: 2025-11-25',
            ],
        ]);
        $raw = (string) curl_exec($curl);
        $this->assertSame(200, curl_getinfo($curl, CURLINFO_HTTP_CODE), $raw);

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    private function actor(): ActorContext
    {
        return (new PrincipalResolver())->resolve(
            'business_user',
            TestFixtures::BUSINESS_USER_ID,
            [TestFixtures::BUSINESS_TENANT_ID],
            ['services:manage'],
            authMode: 'session'
        );
    }

    private function arguments(string $name): array
    {
        return ['business_id' => TestFixtures::BUSINESS_TENANT_ID, 'name' => $name];
    }

    private function registry(?\Closure $previewHandler = null): OperationRegistry
    {
        $input = [
            'type' => 'object',
            'required' => ['business_id', 'name'],
            'properties' => ['business_id' => ['type' => 'string'], 'name' => ['type' => 'string']],
            'additionalProperties' => false,
        ];
        $output = ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'string']]];
        $handler = static function ($actor, array $arguments): array {
            $id = Ulid::generate();
            Database::execute(
                'INSERT INTO services (id, tenant_id, name, duration_minutes) VALUES (?, ?, ?, 30)',
                [$id, $arguments['business_id'], $arguments['name']]
            );

            return ['id' => $id];
        };
        $definition = static fn (string $id, string $policy, ?\Closure $preview): OperationDefinition => new OperationDefinition(
            $id,
            'Contract fixture',
            'Synthetic response contract command',
            $input,
            $output,
            ['services:manage'],
            'owner',
            false,
            false,
            $policy,
            ['timeslot'],
            'fixture',
            ['C12'],
            ['A13'],
            'POST',
            '/' . $id,
            $handler,
            previewHandler: $preview
        );

        return new OperationRegistry(
            $definition('fixture_create', 'none', null),
            $definition('fixture_reviewed_create', 'trusted', $previewHandler ?? static fn (): array => ['ok' => true]),
        );
    }
}
