<?php

namespace App\Services\Workflows\Execution;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WorkflowInstance;

class ApprovalEligibility
{
    /** @var array<string, OrganizationRole|null> */
    private array $roles = [];

    public function role(User $user, int $organizationId): ?OrganizationRole
    {
        $key = $user->id.':'.$organizationId;

        if (array_key_exists($key, $this->roles)) {
            return $this->roles[$key];
        }

        $role = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->value('role');

        $resolved = $role instanceof OrganizationRole
            ? $role
            : (is_string($role) ? OrganizationRole::tryFrom($role) : null);

        return $this->roles[$key] = $resolved;
    }

    /**
     * The published approval node has no self-approval flag, so the submitter
     * is never eligible to approve their own request.
     *
     * @param  list<string>  $roles
     */
    public function canApprove(User $user, WorkflowInstance $instance, array $roles): bool
    {
        if ($roles === [] || ($instance->submitted_by !== null && $user->id === (int) $instance->submitted_by)) {
            return false;
        }

        $role = $this->role($user, $instance->organization_id);

        return $role !== null && in_array($role->value, $roles, true);
    }

    /**
     * @param  list<string>  $roles
     */
    public function eligibleExists(WorkflowInstance $instance, array $roles): bool
    {
        if ($roles === []) {
            return false;
        }

        return OrganizationMembership::query()
            ->where('organization_id', $instance->organization_id)
            ->whereNull('revoked_at')
            ->whereIn('role', $roles)
            ->when(
                $instance->submitted_by !== null,
                fn ($query) => $query->where('user_id', '!=', $instance->submitted_by),
            )
            ->exists();
    }
}
