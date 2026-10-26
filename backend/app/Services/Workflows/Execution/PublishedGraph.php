<?php

namespace App\Services\Workflows\Execution;

use App\Models\WorkflowVersion;

/**
 * Read-only view of one published snapshot.
 */
class PublishedGraph
{
    /**
     * @param  array{nodes?: mixed, edges?: mixed}  $graph
     * @param  array{fields?: mixed}  $form
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @param  array<string, array<string, mixed>>  $fields
     */
    private function __construct(
        private readonly array $nodes,
        private readonly array $edges,
        private readonly array $fields,
    ) {}

    public static function fromVersion(WorkflowVersion $version): self
    {
        $graph = is_array($version->graph_definition) ? $version->graph_definition : [];
        $form = is_array($version->form_definition) ? $version->form_definition : [];

        return self::fromArrays($graph, $form);
    }

    /**
     * @param  array<string, mixed>  $graph
     * @param  array<string, mixed>  $form
     */
    public static function fromArrays(array $graph, array $form): self
    {
        $nodes = [];
        $edges = [];
        $fields = [];

        foreach (is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [] as $node) {
            if (is_array($node) && is_string($node['id'] ?? null)) {
                $nodes[$node['id']] = $node;
            }
        }

        foreach (is_array($graph['edges'] ?? null) ? $graph['edges'] : [] as $edge) {
            if (is_array($edge)) {
                $edges[] = $edge;
            }
        }

        foreach (is_array($form['fields'] ?? null) ? $form['fields'] : [] as $field) {
            if (is_array($field) && is_string($field['id'] ?? null)) {
                $fields[$field['id']] = $field;
            }
        }

        return new self($nodes, $edges, $fields);
    }

    public function nodeCount(): int
    {
        return count($this->nodes);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function node(string $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    public function startId(): ?string
    {
        $starts = [];

        foreach ($this->nodes as $node) {
            if (($node['type'] ?? null) === 'start') {
                $starts[] = $node['id'];
            }
        }

        return count($starts) === 1 ? $starts[0] : null;
    }

    public function successor(string $nodeId, string $handle): ?string
    {
        $target = null;

        foreach ($this->edges as $edge) {
            if (($edge['source'] ?? null) === $nodeId && ($edge['source_handle'] ?? null) === $handle) {
                if ($target !== null || ! is_string($edge['target'] ?? null)) {
                    return null;
                }

                $target = $edge['target'];
            }
        }

        return $target;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function requiresUpload(): bool
    {
        foreach ($this->fields as $field) {
            if (($field['type'] ?? null) === 'file') {
                return true;
            }
        }

        return false;
    }
}
