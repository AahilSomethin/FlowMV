<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\User;

class OrganizationMembershipPolicy
{
    public function update(User $user, OrganizationMembership $membership): bool
    {
        return $this->adminOfActiveMembership($user, $membership);
    }

    public function delete(User $user, OrganizationMembership $membership): bool
    {
        return $this->adminOfActiveMembership($user, $membership);
    }

    private function adminOfActiveMembership(User $user, OrganizationMembership $membership): bool
    {
        if ($membership->revoked_at !== null) {
            return false;
        }

        return OrganizationMembership::query()
            ->where('organization_id', $membership->organization_id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('role', OrganizationRole::Admin->value)
            ->exists();
    }
}
