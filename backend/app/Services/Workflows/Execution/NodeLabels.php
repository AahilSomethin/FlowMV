<?php

namespace App\Services\Workflows\Execution;

use App\Models\WorkflowVersion;

/**
 * Display names from a pinned workflow graph.
 */
class NodeLabels
{
    /**
     * @return array<string, string>
     */
    public static function forVersion(?WorkflowVersion $version): array
    {
        if (! $version instanceof WorkflowVersion) {
            return [];
        }

        $graph = is_array($version->graph_definition) ? $version->graph_definition : [];
        $labels = [];

        foreach (is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [] as $node) {
            if (! is_array($node) || ! is_string($node['id'] ?? null)) {
                continue;
            }

            $label = is_array($node['data'] ?? null) ? ($node['data']['label'] ?? null) : null;
            $labels[$node['id']] = is_string($label) && $label !== '' ? $label : $node['id'];
        }

        return $labels;
    }

    /**
     * @param  array<string, string>  $labels
     */
    public static function text(array $labels, ?string $nodeId): ?string
    {
        if ($nodeId === null || $nodeId === '') {
            return null;
        }

        return $labels[$nodeId] ?? $nodeId;
    }
}
