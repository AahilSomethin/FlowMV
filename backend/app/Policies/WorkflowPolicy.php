<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Enums\WorkflowStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\Workflow;

class WorkflowPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->role($user, $organization) !== null;
    }

    public function view(User $user, Workflow $workflow): bool
    {
        $role = $this->role($user, $workflow->organization);

        if ($role === null) {
            return false;
        }

        if ($role === OrganizationRole::Employee) {
            return $workflow->status === WorkflowStatus::Published;
        }

        return true;
    }

    public function create(User $user, Organization $organization): bool
    {
        return in_array($this->role($user, $organization), [OrganizationRole::Admin, OrganizationRole::Manager], true);
    }

    public function update(User $user, Workflow $workflow): bool
    {
        $role = $this->role($user, $workflow->organization);

        return in_array($role, [OrganizationRole::Admin, OrganizationRole::Manager], true)
            && $workflow->status !== WorkflowStatus::Archived;
    }

    public function publish(User $user, Workflow $workflow): bool
    {
        return $this->role($user, $workflow->organization) === OrganizationRole::Admin
            && $workflow->status !== WorkflowStatus::Archived;
    }

    public function archive(User $user, Workflow $workflow): bool
    {
        return $this->role($user, $workflow->organization) === OrganizationRole::Admin;
    }

    public function role(User $user, Organization $organization): ?OrganizationRole
    {
        $role = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->value('role');

        return $role instanceof OrganizationRole ? $role : (is_string($role) ? OrganizationRole::tryFrom($role) : null);
    }
}
