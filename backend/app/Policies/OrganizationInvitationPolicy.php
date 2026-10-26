<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;

class OrganizationInvitationPolicy
{
    public function delete(User $user, OrganizationInvitation $invitation): bool
    {
        if (! $invitation->isPending()) {
            return false;
        }

        return OrganizationMembership::query()
            ->where('organization_id', $invitation->organization_id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('role', OrganizationRole::Admin->value)
            ->exists();
    }
}
