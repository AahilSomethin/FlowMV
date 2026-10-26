<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SwitchOrganization
{
    public function __invoke(User $user, Organization $organization): void
    {
        $active = $organization->activeMemberships()
            ->where('user_id', $user->id)
            ->exists();

        if (! $active) {
            throw (new ModelNotFoundException)->setModel(Organization::class);
        }

        $user->forceFill(['current_organization_id' => $organization->id])->save();
    }
}
