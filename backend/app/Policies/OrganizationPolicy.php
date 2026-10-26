<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $this->activeRole($user, $organization) !== null;
    }

    public function invite(User $user, Organization $organization): bool
    {
        return $this->activeRole($user, $organization) === OrganizationRole::Admin;
    }

    public function viewInvitations(User $user, Organization $organization): bool
    {
        return $this->invite($user, $organization);
    }

    private function activeRole(User $user, Organization $organization): ?OrganizationRole
    {
        $role = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->value('role');

        return $role instanceof OrganizationRole ? $role : (is_string($role) ? OrganizationRole::tryFrom($role) : null);
    }
}
