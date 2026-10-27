<?php

namespace App\Services\Workflows\Nodes;

use App\Enums\WorkflowNodeType;
use InvalidArgumentException;

/**
 * Versioned catalog of workflow node contracts.
 *
 * V1 covers the original five types with frozen handles and config shapes.
 * V2 adds notification, assignment, and delay. Published snapshots stamp the
 * registry version used at publish time. Execution never reinterprets a V1
 * graph under V2-only rules or types.
 */
class NodeRegistry
{
    public const V1 = 1;

    public const V2 = 2;

    public const CURRENT = self::V2;

    /**
     * @var array<string, list<string>>
     */
    private const HANDLES = [
        'start' => ['out'],
        'approval' => ['approved', 'rejected'],
        'condition' => ['true', 'false'],
        'revision' => ['return', 'exhausted'],
        'end' => [],
        'notification' => ['out'],
        'assignment' => ['out'],
        'delay' => ['out'],
    ];

    /**
     * @return list<string>
     */
    public function typesFor(int $registryVersion): array
    {
        $types = array_map(
            fn (WorkflowNodeType $type) => $type->value,
            WorkflowNodeType::v1(),
        );

        if ($registryVersion >= self::V2) {
            foreach (WorkflowNodeType::v2Additions() as $type) {
                $types[] = $type->value;
            }
        }

        return $types;
    }

    public function allows(int $registryVersion, string $type): bool
    {
        return in_array($type, $this->typesFor($registryVersion), true);
    }

    /**
     * @return list<string>
     */
    public function sourceHandles(string $type): array
    {
        if (! array_key_exists($type, self::HANDLES)) {
            throw new InvalidArgumentException("Unknown node type [{$type}].");
        }

        return self::HANDLES[$type];
    }

    /**
     * @return array<string, list<string>>
     */
    public function allSourceHandles(int $registryVersion): array
    {
        $handles = [];

        foreach ($this->typesFor($registryVersion) as $type) {
            $handles[$type] = $this->sourceHandles($type);
        }

        return $handles;
    }

    public function introducedIn(string $type): int
    {
        $enum = WorkflowNodeType::tryFrom($type);

        return $enum?->introducedIn() ?? PHP_INT_MAX;
    }
}
