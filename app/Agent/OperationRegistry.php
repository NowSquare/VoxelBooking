<?php

declare(strict_types=1);

namespace App\Agent;

final class OperationRegistry
{
    /** @var array<string, OperationDefinition> */
    private array $operations = [];

    /** @var array<string, string> MCP tool name, including each "_preview" alias, to operation id */
    private array $toolNames = [];

    public function __construct(OperationDefinition ...$definitions)
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }

    public function register(OperationDefinition $definition): void
    {
        if (isset($this->operations[$definition->operationId])) {
            throw new \LogicException('Duplicate operation: ' . $definition->operationId);
        }
        // A reviewed write also publishes "<name>_preview", so both names must be free.
        foreach ([$definition->mcpToolName, $definition->mcpToolName . '_preview'] as $toolName) {
            if (isset($this->toolNames[$toolName])) {
                throw new \LogicException('Duplicate MCP tool name: ' . $toolName);
            }
            $this->toolNames[$toolName] = $definition->operationId;
        }
        $this->operations[$definition->operationId] = $definition;
        ksort($this->operations);
    }

    public function get(string $name): ?OperationDefinition
    {
        return $this->operations[$name] ?? null;
    }

    public function all(): array
    {
        return array_values($this->operations);
    }

    public function visible(ActorContext $actor): array
    {
        $policy = new AuthorizationPolicy();
        return array_values(array_filter(
            $this->operations,
            static fn ($definition) => $policy->permits($actor, $definition)
        ));
    }
}
