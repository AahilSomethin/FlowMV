<?php

namespace App\Services\Workflows\Execution;

use App\Enums\OrganizationRole;
use App\Enums\WorkflowTaskStatus;
use App\Enums\WorkflowTaskType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Database equivalent of WorkflowInstanceVisibility for lists.
 *
 * Open rows are tasks the caller can act on. Completed rows are tasks the
 * caller can already view. Both stay inside one organization.
 */
class TaskEligibilityQuery
{
    public function __construct(private readonly ApprovalEligibility $eligibility) {}

    /**
     * @param  Builder<\App\Models\WorkflowTask>  $query
     */
    public function constrainTasks(Builder $query, User $user, Organization $organization, WorkflowTaskStatus $status): void
    {
        $query->where('workflow_tasks.organization_id', $organization->id)
            ->where('workflow_tasks.status', $status->value);

        $role = $this->eligibility->role($user, $organization->id);

        if ($role === null) {
            $query->whereRaw('0 = 1');

            return;
        }

        if ($status === WorkflowTaskStatus::Completed) {
            $this->constrainViewable($query, $user, $organization, $role);

            return;
        }

        $this->constrainActionable($query, $user, $role);
    }

    /**
     * @param  Builder<\App\Models\WorkflowInstance>  $query
     */
    public function constrainInstances(Builder $query, User $user, Organization $organization): void
    {
        $role = $this->eligibility->role($user, $organization->id);

        if ($role === null) {
            $query->whereRaw('0 = 1');

            return;
        }

        if ($role === OrganizationRole::Admin) {
            return;
        }

        $query->where(function (Builder $visible) use ($user, $organization, $role): void {
            $visible->where('workflow_instances.submitted_by', $user->id)
                ->orWhereExists(function ($related) use ($user, $organization): void {
                    $related->selectRaw('1')
                        ->from('workflow_tasks')
                        ->whereColumn('workflow_tasks.workflow_instance_id', 'workflow_instances.id')
                        ->where('workflow_tasks.organization_id', $organization->id)
                        ->where(function ($who) use ($user): void {
                            $who->where('workflow_tasks.completed_by', $user->id)
                                ->orWhere('workflow_tasks.assigned_user_id', $user->id);
                        });
                })
                ->orWhereExists(function ($open) use ($user, $organization, $role): void {
                    $open->selectRaw('1')
                        ->from('workflow_tasks')
                        ->whereColumn('workflow_tasks.workflow_instance_id', 'workflow_instances.id')
                        ->where('workflow_tasks.organization_id', $organization->id)
                        ->where('workflow_tasks.status', WorkflowTaskStatus::Open->value)
                        ->where('workflow_tasks.task_type', WorkflowTaskType::Approval->value);
                    $this->whereApprovalMatchesRole($open, 'workflow_tasks', $user, $role->value);
                });
        });
    }

    /**
     * @param  Builder<\App\Models\WorkflowTask>  $query
     */
    private function constrainActionable(Builder $query, User $user, OrganizationRole $role): void
    {
        $query->where(function (Builder $actionable) use ($user, $role): void {
            $actionable->where(function (Builder $revision) use ($user): void {
                $revision->where('workflow_tasks.task_type', WorkflowTaskType::Revision->value)
                    ->where('workflow_tasks.assigned_user_id', $user->id);
            })->orWhere(function (Builder $approval) use ($user, $role): void {
                $approval->where('workflow_tasks.task_type', WorkflowTaskType::Approval->value);
                $this->whereApprovalMatchesRole($approval, 'workflow_tasks', $user, $role->value);
            });
        });
    }

    /**
     * @param  Builder<\App\Models\WorkflowTask>  $query
     */
    private function constrainViewable(Builder $query, User $user, Organization $organization, OrganizationRole $role): void
    {
        if ($role === OrganizationRole::Admin) {
            return;
        }

        $query->where(function (Builder $visible) use ($user, $organization, $role): void {
            $visible->where('workflow_tasks.completed_by', $user->id)
                ->orWhere('workflow_tasks.assigned_user_id', $user->id)
                ->orWhereExists(function ($owned) use ($user): void {
                    $owned->selectRaw('1')
                        ->from('workflow_instances')
                        ->whereColumn('workflow_instances.id', 'workflow_tasks.workflow_instance_id')
                        ->whereColumn('workflow_instances.organization_id', 'workflow_tasks.organization_id')
                        ->where('workflow_instances.submitted_by', $user->id);
                })
                ->orWhereExists(function ($related) use ($user, $organization): void {
                    $related->selectRaw('1')
                        ->from('workflow_tasks as related_tasks')
                        ->whereColumn('related_tasks.workflow_instance_id', 'workflow_tasks.workflow_instance_id')
                        ->where('related_tasks.organization_id', $organization->id)
                        ->where(function ($who) use ($user): void {
                            $who->where('related_tasks.completed_by', $user->id)
                                ->orWhere('related_tasks.assigned_user_id', $user->id);
                        });
                })
                ->orWhereExists(function ($open) use ($user, $organization, $role): void {
                    $open->selectRaw('1')
                        ->from('workflow_tasks as open_tasks')
                        ->whereColumn('open_tasks.workflow_instance_id', 'workflow_tasks.workflow_instance_id')
                        ->where('open_tasks.organization_id', $organization->id)
                        ->where('open_tasks.status', WorkflowTaskStatus::Open->value)
                        ->where('open_tasks.task_type', WorkflowTaskType::Approval->value);
                    $this->whereApprovalMatchesRole($open, 'open_tasks', $user, $role->value);
                });
        });
    }

    /**
     * The submitter is never eligible. The role must appear on the pinned approval node.
     *
     * @param  Builder<\App\Models\WorkflowTask>|object  $query
     */
    private function whereApprovalMatchesRole(object $query, string $taskAlias, User $user, string $role): void
    {
        if (preg_match('/^[a-z_]+$/', $taskAlias) !== 1) {
            $query->whereRaw('0 = 1');

            return;
        }

        $driver = $query->getConnection()->getDriverName();
        $nodes = $driver === 'pgsql'
            ? "case when jsonb_typeof(workflow_versions.graph_definition->'nodes') = 'array' then workflow_versions.graph_definition->'nodes' else '[]'::jsonb end"
            : "case when json_type(workflow_versions.graph_definition, '$.nodes') = 'array' then json_extract(workflow_versions.graph_definition, '$.nodes') else '[]' end";
        $match = $driver === 'pgsql'
            ? "exists (
                select 1
                from jsonb_array_elements({$nodes}) as node
                where node->>'id' = {$taskAlias}.node_id
                and exists (
                    select 1
                    from jsonb_array_elements_text(
                        case when jsonb_typeof(node->'data'->'assignee_roles') = 'array'
                            then node->'data'->'assignee_roles'
                            else '[]'::jsonb end
                    ) as assignee_role
                    where assignee_role = ?
                )
            )"
            : "exists (
                select 1
                from json_each({$nodes}) as node
                where json_extract(node.value, '$.id') = {$taskAlias}.node_id
                and exists (
                    select 1
                    from json_each(
                        case when json_type(node.value, '$.data.assignee_roles') = 'array'
                            then json_extract(node.value, '$.data.assignee_roles')
                            else '[]' end
                    ) as assignee_role
                    where assignee_role.value = ?
                )
            )";

        $query->whereExists(function ($eligible) use ($user, $role, $taskAlias, $match): void {
            $eligible->selectRaw('1')
                ->from('workflow_instances as eligible_instances')
                ->join('workflow_versions', 'workflow_versions.id', '=', 'eligible_instances.workflow_version_id')
                ->whereColumn('eligible_instances.id', "{$taskAlias}.workflow_instance_id")
                ->whereColumn('eligible_instances.organization_id', "{$taskAlias}.organization_id")
                ->where(function ($submitter) use ($user): void {
                    $submitter->whereNull('eligible_instances.submitted_by')
                        ->orWhere('eligible_instances.submitted_by', '!=', $user->id);
                })
                ->whereRaw($match, [$role]);
        });
    }
}
