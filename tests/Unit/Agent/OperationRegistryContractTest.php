<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\Mcp\ContractGenerator;
use App\Agent\OperationDefinition;
use App\Agent\OperationRegistry;
use App\Agent\Operations;
use PHPUnit\Framework\TestCase;

/**
 * The registry is the contract every transport publishes, so its metadata has to
 * describe what the handlers actually do: the failures a caller can meet, the
 * effects a write leaves behind, and how safely a call can be repeated.
 */
final class OperationRegistryContractTest extends TestCase
{
    private static ?OperationRegistry $registry = null;

    private function registry(): OperationRegistry
    {
        return self::$registry ??= Operations::registry();
    }

    private function operation(string $operationId): OperationDefinition
    {
        $operation = $this->registry()->get($operationId);
        self::assertNotNull($operation, 'Missing operation ' . $operationId);
        return $operation;
    }

    private function tool(string $name): array
    {
        $tools = (new ContractGenerator())->tools($this->registry()->all());
        foreach ($tools as $tool) {
            if ($tool['name'] === $name) {
                return $tool;
            }
        }
        self::fail('Missing tool ' . $name);
    }

    public function testEveryOperationDeclaresTheProblemsItsHandlerRaises(): void
    {
        $missing = [];
        foreach ($this->registry()->all() as $operation) {
            if ($operation->errorCodes === []) {
                $missing[] = $operation->operationId;
            }
        }
        self::assertSame([], $missing);
    }

    public function testEveryWriteDeclaresItsSideEffects(): void
    {
        $missing = [];
        foreach ($this->registry()->all() as $operation) {
            if (!$operation->readOnly && $operation->sideEffects === []) {
                $missing[] = $operation->operationId;
            }
        }
        self::assertSame([], $missing);
    }

    public function testEveryMcpToolNameIsValidUniqueAndRoutable(): void
    {
        $names = [];
        foreach ($this->registry()->all() as $operation) {
            self::assertMatchesRegularExpression('/\A[a-zA-Z0-9_-]{1,64}\z/D', $operation->mcpToolName);
            self::assertMatchesRegularExpression('/\A[a-zA-Z0-9_-]{1,64}\z/D', $operation->mcpToolName . '_preview');
            // The MCP adapter routes a tool call by operation id, so the names stay equal until it maps them.
            self::assertSame($operation->operationId, $operation->mcpToolName);
            $names[] = $operation->mcpToolName;
        }
        self::assertSame(count($names), count(array_unique($names)));
    }

    public function testInvalidMcpToolNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->definition(mcpToolName: 'booking get');
    }

    public function testDuplicateMcpToolNameIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        new OperationRegistry(
            $this->definition(operationId: 'first_operation', mcpToolName: 'shared_name'),
            $this->definition(operationId: 'second_operation', mcpToolName: 'shared_name'),
        );
    }

    public function testUnknownSideEffectIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->definition(sideEffects: ['telepathy']);
    }

    public function testIdempotencyPolicyMatchesHowTheExecutorTreatsTheOperation(): void
    {
        foreach ($this->registry()->all() as $operation) {
            // Reads take no key; every write needs one because the executor demands it.
            self::assertSame(
                $operation->readOnly,
                $operation->idempotencyPolicy === 'none',
                $operation->operationId
            );
            self::assertContains($operation->idempotencyPolicy, ['none', 'required', 'natural'], $operation->operationId);
        }
        self::assertSame('none', $this->operation('availability_search')->idempotencyPolicy);
        self::assertSame('required', $this->operation('booking_create')->idempotencyPolicy);
        self::assertSame('natural', $this->operation('connection_revoke')->idempotencyPolicy);
    }

    public function testReadOnlyWriteAndDestructiveHintsFollowTheDefinition(): void
    {
        foreach ($this->registry()->all() as $operation) {
            $annotations = $operation->tool()['annotations'];
            self::assertSame($operation->readOnly, $annotations['readOnlyHint'], $operation->operationId);
            self::assertSame($operation->destructive, $annotations['destructiveHint'], $operation->operationId);
            self::assertFalse($operation->readOnly && $operation->destructive, $operation->operationId);
        }
    }

    public function testBookingCreateIsARepeatSensitiveWriteThatReachesCustomers(): void
    {
        $annotations = $this->tool('booking_create')['annotations'];
        self::assertFalse($annotations['readOnlyHint']);
        self::assertFalse($annotations['destructiveHint']);
        self::assertFalse($annotations['idempotentHint']);
        self::assertTrue($annotations['openWorldHint']);
    }

    public function testBookingCancelIsDestructiveConvergentAndNotifies(): void
    {
        $annotations = $this->tool('customer_booking_cancel')['annotations'];
        self::assertFalse($annotations['readOnlyHint']);
        self::assertTrue($annotations['destructiveHint']);
        self::assertTrue($annotations['idempotentHint']);
        self::assertTrue($annotations['openWorldHint']);
    }

    public function testConnectionRevokeIsDestructiveConvergentAndStaysInside(): void
    {
        $annotations = $this->tool('connection_revoke')['annotations'];
        self::assertFalse($annotations['readOnlyHint']);
        self::assertTrue($annotations['destructiveHint']);
        self::assertTrue($annotations['idempotentHint']);
        self::assertFalse($annotations['openWorldHint']);
    }

    public function testWebhookRetryReachesOutAndRepeatsTheDelivery(): void
    {
        $annotations = $this->tool('webhook_delivery_retry')['annotations'];
        self::assertFalse($annotations['readOnlyHint']);
        self::assertFalse($annotations['idempotentHint']);
        self::assertTrue($annotations['openWorldHint']);
    }

    public function testAvailabilitySearchIsAClosedWorldRead(): void
    {
        $annotations = $this->tool('availability_search')['annotations'];
        self::assertTrue($annotations['readOnlyHint']);
        self::assertFalse($annotations['destructiveHint']);
        self::assertTrue($annotations['idempotentHint']);
        self::assertFalse($annotations['openWorldHint']);
    }

    public function testInternalSettingsWriteIsNotOpenWorld(): void
    {
        self::assertFalse($this->tool('system_settings_update')['annotations']['openWorldHint']);
    }

    public function testMcpToolListCarriesTheIdempotencyPolicy(): void
    {
        $tool = $this->tool('booking_create');
        self::assertSame('required', $tool['_meta']['com.voxelbooking/idempotency_policy']);
        self::assertSame('booking_create', $tool['_meta']['com.voxelbooking/operation_id']);
        self::assertSame('none', $this->tool('booking_create_preview')['_meta']['com.voxelbooking/idempotency_policy']);
    }

    public function testOpenApiCarriesToolNameIdempotencyAndExecutorProblems(): void
    {
        $document = (new ContractGenerator())->openApi(
            $this->registry()->all(),
            'https://booking.example/api/agent/v2',
            '2.0.0'
        );
        $create = null;
        $search = null;
        foreach ($document['paths'] as $methods) {
            foreach ($methods as $operation) {
                if (($operation['operationId'] ?? '') === 'booking_create') {
                    $create = $operation;
                }
                if (($operation['operationId'] ?? '') === 'availability_search') {
                    $search = $operation;
                }
            }
        }
        self::assertNotNull($create);
        self::assertNotNull($search);
        self::assertSame('booking_create', $create['x-mcp-tool-name']);
        self::assertSame('required', $create['x-idempotency-policy']);
        self::assertContains('slot_unavailable', $create['x-error-codes']);
        self::assertContains('idempotency_key_required', $create['x-error-codes']);
        self::assertContains('preview_required', $create['x-error-codes']);
        self::assertContains('forbidden', $create['x-error-codes']);
        self::assertSame('none', $search['x-idempotency-policy']);
        self::assertNotContains('idempotency_key_required', $search['x-error-codes']);
        self::assertContains('rate_limited', $search['x-error-codes']);
    }

    private function definition(
        string $operationId = 'sample_operation',
        ?string $mcpToolName = null,
        array $sideEffects = ['audit'],
    ): OperationDefinition {
        return new OperationDefinition(
            $operationId,
            'Sample',
            'Sample operation.',
            ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => []],
            [],
            'owner',
            false,
            false,
            'none',
            [],
            'bookings',
            [],
            [],
            'POST',
            '/api/agent/v2/' . $operationId,
            static fn (): array => [],
            sideEffects: $sideEffects,
            errorCodes: ['not_found'],
            mcpToolName: $mcpToolName,
        );
    }
}
