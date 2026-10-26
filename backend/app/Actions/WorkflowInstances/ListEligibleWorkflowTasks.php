<?php

namespace App\Actions\WorkflowInstances;

use App\Enums\WorkflowTaskStatus;
use App\Enums\WorkflowTaskType;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkflowTask;
use App\Services\Workflows\Execution\WorkflowInstanceVisibility;
use Illuminate\Pagination\LengthAwarePaginator;

class ListEligibleWorkflowTasks
{
    public function __construct(private readonly WorkflowInstanceVisibility $visibility) {}

    public function __invoke(User $user, Organization $organization, int $page): LengthAwarePaginator
    {
        $tasks = WorkflowTask::query()
            ->where('organization_id', $organization->id)
            ->where('status', WorkflowTaskStatus::Open)
            ->with(['instance.workflow', 'instance.version', 'instance.submitter'])
            ->orderBy('id')
            ->get()
            ->filter(function (WorkflowTask $task) use ($user) {
                return $task->task_type === WorkflowTaskType::Revision
                    ? $this->visibility->canRevise($user, $task)
                    : $this->visibility->canDecide($user, $task);
            })
            ->values();
        $page = max($page, 1);
        $perPage = 15;

        return new LengthAwarePaginator(
            $tasks->slice(($page - 1) * $perPage, $perPage)->values(),
            $tasks->count(),
            $perPage,
            $page,
        );
    }
}
