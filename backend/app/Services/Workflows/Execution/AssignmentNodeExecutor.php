<?php

namespace App\Services\Workflows\Execution;

use App\Enums\OrganizationRole;
use App\Enums\WorkflowFailureCode;
use App\Enums\WorkflowInstanceStatus;
use App\Enums\WorkflowTaskStatus;
use App\Enums\WorkflowTaskType;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;

class AssignmentNodeExecutor
{
    public function __construct(private readonly ApprovalEligibility $eligibility) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public function execute(WorkflowInstance $instance, User $actor, array $node, string $nodeId, WorkflowEngine $engine): void
    {
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        $mode = ($data['assignee_mode'] ?? 'role') === 'member' ? 'member' : 'role';
        $excludeSubmitter = ($data['exclude_submitter'] ?? true) === true;

        if ($mode === 'member') {
            $userId = $data['assignee_user_id'] ?? null;

            if (! is_int($userId) && ! (is_string($userId) && ctype_digit((string) $userId))) {
                $engine->fail($instance, $actor, WorkflowFailureCode::NoEligibleAssignee, $nodeId);

                return;
            }

            $userId = (int) $userId;

            if (! $this->memberEligible($instance, $userId, $excludeSubmitter)) {
                $engine->fail($instance, $actor, WorkflowFailureCode::NoEligibleAssignee, $nodeId);

                return;
            }

            $engine->openHumanTask($instance, $actor, [
                'node_id' => $nodeId,
                'task_type' => WorkflowTaskType::Assignment,
                'assigned_user_id' => $userId,
                'assigned_role' => null,
                'status' => WorkflowTaskStatus::Open,
                'assigned_at' => now(),
            ], WorkflowInstanceStatus::AwaitingAssignment);

            return;
        }

        $roles = array_values(array_filter($data['assignee_roles'] ?? [], is_string(...)));

        if (! $this->roleEligibleExists($instance, $roles, $excludeSubmitter)) {
            $engine->fail($instance, $actor, WorkflowFailureCode::NoEligibleAssignee, $nodeId);

            return;
        }

        $engine->openHumanTask($instance, $actor, [
            'node_id' => $nodeId,
            'task_type' => WorkflowTaskType::Assignment,
            'assigned_user_id' => null,
            'assigned_role' => count($roles) === 1 ? $roles[0] : null,
            'status' => WorkflowTaskStatus::Open,
            'assigned_at' => now(),
        ], WorkflowInstanceStatus::AwaitingAssignment);
    }

    public function canComplete(User $user, WorkflowInstance $instance, WorkflowTask $task, array $nodeData): bool
    {
        if ($task->task_type !== WorkflowTaskType::Assignment) {
            return false;
        }

        $mode = ($nodeData['assignee_mode'] ?? 'role') === 'member' ? 'member' : 'role';
        $excludeSubmitter = ($nodeData['exclude_submitter'] ?? true) === true;

        if ($excludeSubmitter && $instance->submitted_by !== null && $user->id === (int) $instance->submitted_by) {
            return false;
        }

        if ($this->eligibility->role($user, $instance->organization_id) === null) {
            return false;
        }

        if ($task->assigned_user_id !== null) {
            return (int) $task->assigned_user_id === $user->id
                && $this->memberEligible($instance, $user->id, $excludeSubmitter);
        }

        if ($mode === 'member') {
            return false;
        }

        $roles = array_values(array_filter($nodeData['assignee_roles'] ?? [], is_string(...)));
        $role = $this->eligibility->role($user, $instance->organization_id);

        return $role instanceof OrganizationRole
            && in_array($role->value, $roles, true)
            && $this->memberEligible($instance, $user->id, $excludeSubmitter);
    }

    public function memberEligible(WorkflowInstance $instance, int $userId, bool $excludeSubmitter): bool
    {
        if ($excludeSubmitter && $instance->submitted_by !== null && $userId === (int) $instance->submitted_by) {
            return false;
        }

        return OrganizationMembership::query()
            ->where('organization_id', $instance->organization_id)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * @param  list<string>  $roles
     */
    public function roleEligibleExists(WorkflowInstance $instance, array $roles, bool $excludeSubmitter): bool
    {
        if ($roles === []) {
            return false;
        }

        return OrganizationMembership::query()
            ->where('organization_id', $instance->organization_id)
            ->whereNull('revoked_at')
            ->whereIn('role', $roles)
            ->when(
                $excludeSubmitter && $instance->submitted_by !== null,
                fn ($query) => $query->where('user_id', '!=', $instance->submitted_by),
            )
            ->exists();
    }
}
