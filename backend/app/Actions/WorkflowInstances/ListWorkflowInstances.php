<?php

namespace App\Actions\WorkflowInstances;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\Workflows\Execution\TaskEligibilityQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

class ListWorkflowInstances
{
    public function __construct(private readonly TaskEligibilityQuery $eligibility) {}

    public function __invoke(
        User $user,
        Organization $organization,
        int $page,
        ?string $status = null,
        ?string $search = null,
        ?string $workflowUuid = null,
    ): LengthAwarePaginator {
        $query = WorkflowInstance::query()
            ->where('workflow_instances.organization_id', $organization->id)
            ->with(['workflow', 'version', 'submitter'])
            ->orderByDesc('workflow_instances.id');

        $this->eligibility->constrainInstances($query, $user, $organization);

        if ($status !== null && $status !== '') {
            $query->where('workflow_instances.status', $status);
        }

        if ($workflowUuid !== null && $workflowUuid !== '') {
            $workflowId = Workflow::query()
                ->where('organization_id', $organization->id)
                ->where('uuid', $workflowUuid)
                ->value('id');
            $query->where('workflow_instances.workflow_id', $workflowId ?? 0);
        }

        if ($search !== null && $search !== '') {
            $operator = Schema::getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->whereHas('workflow', function ($workflow) use ($operator, $search): void {
                $workflow->where('name', $operator, '%'.addcslashes($search, '%_\\').'%');
            });
        }

        return $query->paginate(15, ['*'], 'page', max($page, 1));
    }
}
