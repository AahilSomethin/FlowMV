<?php

namespace Database\Factories;

use App\Models\Workflow;
use App\Models\WorkflowDraft;
use App\Services\Workflows\WorkflowDefinitionHasher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkflowDraft>
 */
class WorkflowDraftFactory extends Factory
{
    protected $model = WorkflowDraft::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $graph = [
            'nodes' => [[
                'id' => (string) Str::uuid(),
                'type' => 'start',
                'position' => ['x' => 0, 'y' => 0],
                'data' => ['label' => 'Start', 'description' => ''],
            ]],
            'edges' => [],
        ];
        $form = ['fields' => []];

        return [
            'workflow_id' => Workflow::factory(),
            'revision' => 1,
            'graph_definition' => $graph,
            'form_definition' => $form,
            'content_hash' => app(WorkflowDefinitionHasher::class)->hash($graph, $form),
            'updated_by' => null,
        ];
    }
}
