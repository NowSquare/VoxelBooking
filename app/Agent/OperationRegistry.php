<?php

declare(strict_types=1);

namespace App\Agent;

final class OperationRegistry
{
    /** @var array<string, OperationDefinition> */
    private array $operations = [];

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
