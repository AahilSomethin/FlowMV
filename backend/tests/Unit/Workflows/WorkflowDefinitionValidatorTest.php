<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowDefinitionNormalizer;
use App\Services\Workflows\WorkflowDefinitionValidator;
use Illuminate\Support\Str;
use Tests\Support\WorkflowGraphs;
use Tests\TestCase;

class WorkflowDefinitionValidatorTest extends TestCase
{
    public function test_a_bounded_revision_back_to_an_ancestor_approval_is_valid(): void
    {
        $definition = WorkflowGraphs::publishable();
        $normalized = app(WorkflowDefinitionNormalizer::class)->normalize(
            $definition['graph_definition'],
            $definition['form_definition'],
        );

        $this->assertTrue($normalized->ok(), json_encode($normalized->violations));
        $this->assertSame([], app(WorkflowDefinitionValidator::class)->validate($normalized->graph, $normalized->form));
    }

    public function test_condition_operators_must_match_the_field_type(): void
    {
        $definition = WorkflowGraphs::publishable();
        $approval = $definition['graph_definition']['nodes'][1]['id'];
        $condition = (string) Str::uuid();
        $definition['graph_definition']['nodes'][] = WorkflowGraphs::node($condition, 'condition', 80, 0, [
            'label' => 'High value',
            'description' => '',
            'match' => 'all',
            'rules' => [[
                'id' => (string) Str::uuid(),
                'field_id' => 'purchase_amount',
                'operator' => 'is_true',
                'value' => null,
            ]],
        ]);
        $definition['graph_definition']['edges'][0] = WorkflowGraphs::edge(
            $definition['graph_definition']['nodes'][0]['id'],
            $condition,
            'out',
        );
        $definition['graph_definition']['edges'][] = WorkflowGraphs::edge($condition, $approval, 'true');
        $definition['graph_definition']['edges'][] = WorkflowGraphs::edge($condition, $approval, 'false');
        $definition['form_definition'] = [
            'fields' => [[
                'id' => 'purchase_amount',
                'type' => 'number',
                'label' => 'Purchase amount',
                'description' => '',
                'required' => true,
                'default_value' => null,
                'validation' => ['min' => 0],
                'options' => [],
            ]],
        ];

        $normalized = app(WorkflowDefinitionNormalizer::class)->normalize(
            $definition['graph_definition'],
            $definition['form_definition'],
        );
        $codes = array_column(
            app(WorkflowDefinitionValidator::class)->validate($normalized->graph, $normalized->form),
            'code',
        );

        $this->assertContains('operator_mismatch', $codes);
    }

    public function test_a_forward_cycle_is_rejected(): void
    {
        $definition = WorkflowGraphs::publishable();
        $approval = $definition['graph_definition']['nodes'][1]['id'];
        $completed = $definition['graph_definition']['nodes'][3]['id'];
        $second = (string) Str::uuid();
        $definition['graph_definition']['nodes'][] = WorkflowGraphs::node($second, 'approval', 300, 80, [
            'label' => 'Second approval',
            'description' => '',
            'assignee_roles' => ['admin'],
        ]);

        foreach ($definition['graph_definition']['edges'] as &$edge) {
            if ($edge['source_handle'] === 'approved') {
                $edge['target'] = $second;
            }
        }
        unset($edge);

        $definition['graph_definition']['edges'][] = WorkflowGraphs::edge($second, $approval, 'approved');
        $definition['graph_definition']['edges'][] = WorkflowGraphs::edge($second, $completed, 'rejected');

        $normalized = app(WorkflowDefinitionNormalizer::class)->normalize(
            $definition['graph_definition'],
            $definition['form_definition'],
        );
        $codes = array_column(
            app(WorkflowDefinitionValidator::class)->validate($normalized->graph, $normalized->form),
            'code',
        );

        $this->assertContains('cycle', $codes);
    }

    public function test_revision_return_must_target_an_ancestor_approval(): void
    {
        $definition = WorkflowGraphs::publishable();
        $start = $definition['graph_definition']['nodes'][0]['id'];
        $completed = $definition['graph_definition']['nodes'][3]['id'];
        $rejected = $definition['graph_definition']['nodes'][4]['id'];
        $other = (string) Str::uuid();
        $condition = (string) Str::uuid();

        $definition['form_definition'] = [
            'fields' => [[
                'id' => 'urgent',
                'type' => 'checkbox',
                'label' => 'Urgent',
                'description' => '',
                'required' => false,
                'default_value' => false,
                'validation' => [],
                'options' => [],
            ]],
        ];
        $definition['graph_definition']['nodes'][] = WorkflowGraphs::node($condition, 'condition', 40, 0, [
            'label' => 'Urgent request',
            'description' => '',
            'match' => 'all',
            'rules' => [[
                'id' => (string) Str::uuid(),
                'field_id' => 'urgent',
                'operator' => 'is_true',
                'value' => null,
            ]],
        ]);
        $definition['graph_definition']['nodes'][] = WorkflowGraphs::node($other, 'approval', 300, 220, [
            'label' => 'Other approval',
            'description' => '',
            'assignee_roles' => ['employee'],
        ]);
        $definition['graph_definition']['edges'][0] = WorkflowGraphs::edge($start, $condition, 'out');
        $definition['graph_definition']['edges'][] = WorkflowGraphs::edge($condition, $definition['graph_definition']['nodes'][1]['id'], 'true');
        $definition['graph_definition']['edges'][] = WorkflowGraphs::edge($condition, $other, 'false');
        $definition['graph_definition']['edges'][] = WorkflowGraphs::edge($other, $completed, 'approved');
        $definition['graph_definition']['edges'][] = WorkflowGraphs::edge($other, $rejected, 'rejected');

        foreach ($definition['graph_definition']['edges'] as &$edge) {
            if ($edge['source_handle'] === 'return') {
                $edge['target'] = $other;
            }
        }
        unset($edge);
        $definition['graph_definition']['nodes'][2]['data']['return_to_node_id'] = $other;

        $normalized = app(WorkflowDefinitionNormalizer::class)->normalize(
            $definition['graph_definition'],
            $definition['form_definition'],
        );
        $this->assertTrue($normalized->ok(), json_encode($normalized->violations));
        $codes = array_column(
            app(WorkflowDefinitionValidator::class)->validate($normalized->graph, $normalized->form),
            'code',
        );

        $this->assertContains('revision_target', $codes);
    }
}
