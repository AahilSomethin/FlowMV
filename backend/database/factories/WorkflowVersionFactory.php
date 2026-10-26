<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Services\Workflows\WorkflowDefinitionHasher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowVersion>
 */
class WorkflowVersionFactory extends Factory
{
    protected $model = WorkflowVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $graph = ['nodes' => [], 'edges' => []];
        $form = ['fields' => []];

        return [
            'workflow_id' => Workflow::factory(),
            'version_number' => 1,
            'graph_definition' => $graph,
            'form_definition' => $form,
            'content_hash' => app(WorkflowDefinitionHasher::class)->hash($graph, $form),
            'published_by' => User::factory(),
            'published_at' => now(),
        ];
    }
}
