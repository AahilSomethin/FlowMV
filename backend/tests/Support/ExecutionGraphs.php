<?php

namespace Tests\Support;

use Illuminate\Support\Str;

class ExecutionGraphs
{
    /**
     * @param  list<string>  $roles
     * @param  list<array<string, mixed>>  $fields
     * @return array{graph_definition: array<string, mixed>, form_definition: array<string, mixed>}
     */
    public static function approval(array $roles = ['manager'], int $maxReturns = 3, array $fields = []): array
    {
        $definition = WorkflowGraphs::publishable();

        foreach ($definition['graph_definition']['nodes'] as &$node) {
            if ($node['type'] === 'approval') {
                $node['data']['assignee_roles'] = $roles;
            }

            if ($node['type'] === 'revision') {
                $node['data']['max_returns'] = $maxReturns;
            }
        }

        unset($node);
        $definition['form_definition']['fields'] = $fields;

        return $definition;
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @param  list<array<string, mixed>>  $fields
     * @return array{graph_definition: array<string, mixed>, form_definition: array<string, mixed>}
     */
    public static function condition(array $rules, string $match = 'all', array $fields = []): array
    {
        $start = (string) Str::uuid();
        $condition = (string) Str::uuid();
        $completed = (string) Str::uuid();
        $rejected = (string) Str::uuid();

        return [
            'graph_definition' => [
                'nodes' => [
                    WorkflowGraphs::node($start, 'start', 0, 0, ['label' => 'Start', 'description' => '']),
                    WorkflowGraphs::node($condition, 'condition', 220, 0, [
                        'label' => 'Check',
                        'description' => '',
                        'match' => $match,
                        'rules' => $rules,
                    ]),
                    WorkflowGraphs::node($completed, 'end', 440, 0, [
                        'label' => 'Completed',
                        'description' => '',
                        'result' => 'completed',
                    ]),
                    WorkflowGraphs::node($rejected, 'end', 440, 180, [
                        'label' => 'Rejected',
                        'description' => '',
                        'result' => 'rejected',
                    ]),
                ],
                'edges' => [
                    WorkflowGraphs::edge($start, $condition, 'out'),
                    WorkflowGraphs::edge($condition, $completed, 'true'),
                    WorkflowGraphs::edge($condition, $rejected, 'false'),
                ],
            ],
            'form_definition' => ['fields' => $fields],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function field(string $id, string $type, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'type' => $type,
            'label' => ucfirst(str_replace('_', ' ', $id)),
            'description' => '',
            'required' => true,
            'default_value' => null,
            'validation' => [],
            'options' => [],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function rule(string $fieldId, string $operator, mixed $value = null): array
    {
        return [
            'id' => (string) Str::uuid(),
            'field_id' => $fieldId,
            'operator' => $operator,
            'value' => $value,
        ];
    }
}
