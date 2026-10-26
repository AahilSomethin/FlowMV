<?php

namespace Tests\Support;

use Illuminate\Support\Str;

class WorkflowGraphs
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function node(string $id, string $type, float $x, float $y, array $data): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'position' => ['x' => $x, 'y' => $y],
            'data' => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function edge(string $source, string $target, string $handle): array
    {
        return [
            'id' => (string) Str::uuid(),
            'source' => $source,
            'target' => $target,
            'source_handle' => $handle,
            'target_handle' => 'in',
        ];
    }

    /**
     * Start, one approval, a bounded revision back to that approval, and two ends.
     *
     * @return array{graph_definition: array<string, mixed>, form_definition: array<string, mixed>}
     */
    public static function publishable(): array
    {
        $start = (string) Str::uuid();
        $approval = (string) Str::uuid();
        $revision = (string) Str::uuid();
        $completed = (string) Str::uuid();
        $rejected = (string) Str::uuid();

        return [
            'graph_definition' => [
                'nodes' => [
                    self::node($start, 'start', 0, 0, ['label' => 'Start', 'description' => '']),
                    self::node($approval, 'approval', 220, 0, [
                        'label' => 'Manager approval',
                        'description' => '',
                        'assignee_roles' => ['manager'],
                    ]),
                    self::node($revision, 'revision', 220, 180, [
                        'label' => 'Send back',
                        'description' => 'The submitter corrects the request.',
                        'max_returns' => 3,
                        'return_to_node_id' => $approval,
                    ]),
                    self::node($completed, 'end', 460, 0, [
                        'label' => 'Completed',
                        'description' => '',
                        'result' => 'completed',
                    ]),
                    self::node($rejected, 'end', 460, 180, [
                        'label' => 'Rejected',
                        'description' => '',
                        'result' => 'rejected',
                    ]),
                ],
                'edges' => [
                    self::edge($start, $approval, 'out'),
                    self::edge($approval, $completed, 'approved'),
                    self::edge($approval, $revision, 'rejected'),
                    self::edge($revision, $approval, 'return'),
                    self::edge($revision, $rejected, 'exhausted'),
                ],
            ],
            'form_definition' => ['fields' => []],
        ];
    }
}
