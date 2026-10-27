<?php

namespace App\Services\Workflows\Execution;

use App\Enums\OrganizationRole;
use App\Enums\WorkflowTaskStatus;
use App\Enums\WorkflowTaskType;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Models\WorkflowVersion;

class WorkflowInstanceVisibility
{
    public function __construct(private readonly ApprovalEligibility $eligibility) {}

    public function canView(User $user, WorkflowInstance $instance): bool
    {
        $role = $this->eligibility->role($user, $instance->organization_id);

        if ($role === null) {
            return false;
        }

        if ($role === OrganizationRole::Admin) {
            return true;
        }

        if ($instance->submitted_by !== null && $user->id === (int) $instance->submitted_by) {
            return true;
        }

        $related = $instance->tasks()->where(function ($query) use ($user) {
            $query->where('completed_by', $user->id)->orWhere('assigned_user_id', $user->id);
        })->exists();

        if ($related) {
            return true;
        }

        $open = $instance->tasks()->where('status', WorkflowTaskStatus::Open)->get();

        foreach ($open as $task) {
            if ($task->task_type === WorkflowTaskType::Approval && $this->canDecide($user, $task)) {
                return true;
            }

            if ($task->task_type === WorkflowTaskType::Assignment && $this->canCompleteAssignment($user, $task)) {
                return true;
            }
        }

        return false;
    }

    public function canViewTask(User $user, WorkflowTask $task): bool
    {
        $instance = $task->instance;

        return $instance !== null && $this->canView($user, $instance);
    }

    public function canDecide(User $user, WorkflowTask $task): bool
    {
        if ($task->task_type !== WorkflowTaskType::Approval) {
            return false;
        }

        $instance = $task->instance;

        if ($instance === null) {
            return false;
        }

        return $this->eligibility->canApprove($user, $instance, $this->roles($instance, $task->node_id));
    }

    public function canCompleteAssignment(User $user, WorkflowTask $task): bool
    {
        if ($task->task_type !== WorkflowTaskType::Assignment) {
            return false;
        }

        $instance = $task->instance;

        if ($instance === null) {
            return false;
        }

        return app(AssignmentNodeExecutor::class)->canComplete(
            $user,
            $instance,
            $task,
            $this->nodeData($instance, $task->node_id),
        );
    }

    public function canRevise(User $user, WorkflowTask $task): bool
    {
        if ($task->task_type !== WorkflowTaskType::Revision) {
            return false;
        }

        $instance = $task->instance;

        if ($instance === null || $instance->submitted_by === null || $user->id !== (int) $instance->submitted_by) {
            return false;
        }

        return $this->eligibility->role($user, $instance->organization_id) !== null;
    }

    /**
     * @return list<string>
     */
    private function roles(WorkflowInstance $instance, string $nodeId): array
    {
        $data = $this->nodeData($instance, $nodeId);
        $roles = is_array($data['assignee_roles'] ?? null) ? $data['assignee_roles'] : [];

        return array_values(array_filter($roles, is_string(...)));
    }

    /**
     * @return array<string, mixed>
     */
    private function nodeData(WorkflowInstance $instance, string $nodeId): array
    {
        $version = $instance->relationLoaded('version') ? $instance->version : $instance->version()->first();

        if (! $version instanceof WorkflowVersion || $version->id !== $instance->workflow_version_id) {
            return [];
        }

        $node = PublishedGraph::fromVersion($version)->node($nodeId);

        return is_array($node['data'] ?? null) ? $node['data'] : [];
    }
}
