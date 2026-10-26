<?php

namespace App\Actions\Workflows;

use App\Enums\WorkflowStatus;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowDraft;
use App\Services\Workflows\WorkflowDefinitionHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateWorkflow
{
    public function __construct(private readonly WorkflowDefinitionHasher $hasher) {}

    public function __invoke(User $user, Organization $organization, string $name, ?string $description): Workflow
    {
        return DB::transaction(function () use ($user, $organization, $name, $description) {
            $workflow = Workflow::query()->create([
                'organization_id' => $organization->id,
                'name' => $name,
                'description' => $description,
                'status' => WorkflowStatus::Draft,
                'created_by' => $user->id,
            ]);

            $graph = [
                'nodes' => [[
                    'id' => (string) Str::uuid(),
                    'type' => 'start',
                    'position' => ['x' => 0, 'y' => 0],
                    'data' => [
                        'label' => 'Start',
                        'description' => '',
                    ],
                ]],
                'edges' => [],
            ];
            $form = ['fields' => []];

            WorkflowDraft::query()->create([
                'workflow_id' => $workflow->id,
                'revision' => 1,
                'graph_definition' => $graph,
                'form_definition' => $form,
                'content_hash' => $this->hasher->hash($graph, $form),
                'updated_by' => $user->id,
            ]);

            return $workflow->fresh(['draft', 'currentPublishedVersion']);
        });
    }
}
