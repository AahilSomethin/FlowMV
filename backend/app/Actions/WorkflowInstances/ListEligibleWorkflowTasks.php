<?php

namespace App\Actions\WorkflowInstances;

use App\Enums\WorkflowTaskStatus;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkflowTask;
use App\Services\Workflows\Execution\TaskEligibilityQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

class ListEligibleWorkflowTasks
{
    public function __construct(private readonly TaskEligibilityQuery $eligibility) {}

    public function __invoke(
        User $user,
        Organization $organization,
        int $page,
        WorkflowTaskStatus $status = WorkflowTaskStatus::Open,
        ?string $search = null,
    ): LengthAwarePaginator {
        $query = WorkflowTask::query()
            ->with(['instance.workflow', 'instance.version', 'instance.submitter'])
            ->orderBy('workflow_tasks.id', $status === WorkflowTaskStatus::Completed ? 'desc' : 'asc');

        $this->eligibility->constrainTasks($query, $user, $organization, $status);

        if ($search !== null && $search !== '') {
            $operator = Schema::getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->whereHas('instance', function ($instance) use ($operator, $like): void {
                $instance->whereHas('workflow', function ($workflow) use ($operator, $like): void {
                    $workflow->where('name', $operator, $like);
                });
            });
        }

        return $query->paginate(15, ['workflow_tasks.*'], 'page', max($page, 1));
    }
}
