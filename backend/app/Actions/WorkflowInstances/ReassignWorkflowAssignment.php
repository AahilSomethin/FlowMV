<?php

namespace App\Actions\WorkflowInstances;

use App\Enums\OrganizationRole;
use App\Enums\WorkflowEventType;
use App\Enums\WorkflowInstanceStatus;
use App\Enums\WorkflowTaskStatus;
use App\Enums\WorkflowTaskType;
use App\Exceptions\WorkflowConflictException;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Services\Workflows\Execution\ApprovalEligibility;
use App\Services\Workflows\Execution\AssignmentNodeExecutor;
use App\Services\Workflows\Execution\PinnedWorkflowVersion;
use App\Services\Workflows\Execution\PublishedGraph;
use App\Services\Workflows\Execution\WorkflowEventWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class ReassignWorkflowAssignment
{
    public function __construct(
        private readonly ApprovalEligibility $roles,
        private readonly AssignmentNodeExecutor $assignments,
        private readonly PinnedWorkflowVersion $versions,
        private readonly WorkflowEventWriter $events,
        private readonly LoadWorkflowInstance $loader,
    ) {}

    public function __invoke(User $admin, WorkflowTask $task, int $assigneeUserId): WorkflowInstance
    {
        return DB::transaction(function () use ($admin, $task, $assigneeUserId) {
            $instance = WorkflowInstance::query()->whereKey($task->workflow_instance_id)->lockForUpdate()->firstOrFail();
            $lockedTask = WorkflowTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($instance->organization_id !== $lockedTask->organization_id) {
                throw (new ModelNotFoundException)->setModel(WorkflowTask::class, [$task->uuid]);
            }

            if ($this->roles->role($admin, $instance->organization_id) !== OrganizationRole::Admin) {
                throw new AuthorizationException;
            }

            if ($lockedTask->status !== WorkflowTaskStatus::Open
                || $lockedTask->task_type !== WorkflowTaskType::Assignment
                || $instance->status !== WorkflowInstanceStatus::AwaitingAssignment) {
                throw new WorkflowConflictException('This task is no longer open.');
            }

            $version = $this->versions->forInstance($instance);
            $node = PublishedGraph::fromVersion($version)->node($lockedTask->node_id);
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            $excludeSubmitter = ($data['exclude_submitter'] ?? true) === true;

            if (! $this->assignments->memberEligible($instance, $assigneeUserId, $excludeSubmitter)) {
                throw new WorkflowConflictException('That member is not eligible for this assignment.');
            }

            $previous = $lockedTask->assigned_user_id;
            $lockedTask->forceFill([
                'assigned_user_id' => $assigneeUserId,
                'assigned_role' => null,
                'assigned_at' => now(),
            ])->save();

            // Force member mode for the remainder of this task's life.
            $this->events->record($instance, $admin, WorkflowEventType::TaskReassigned, $lockedTask->node_id, [
                'task_uuid' => $lockedTask->uuid,
                'previous_user_id' => $previous,
                'assignee_user_id' => $assigneeUserId,
            ]);

            return ($this->loader)($instance);
        });
    }
}
