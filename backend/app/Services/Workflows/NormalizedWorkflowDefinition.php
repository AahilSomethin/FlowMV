<?php

namespace App\Services\Workflows;

class NormalizedWorkflowDefinition
{
    /**
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @param  array{fields: list<array<string, mixed>>}  $form
     * @param  list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>  $violations
     */
    public function __construct(
        public readonly array $graph,
        public readonly array $form,
        public readonly array $violations,
        public readonly string $hash,
    ) {}

    public function ok(): bool
    {
        return $this->violations === [];
    }
}
