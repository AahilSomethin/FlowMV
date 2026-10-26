<?php

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\OrganizationAdminGuard;
use Illuminate\Support\Facades\DB;

class RevokeMembership
{
    public function __construct(private OrganizationAdminGuard $guard) {}

    public function __invoke(User $actor, Organization $organization, OrganizationMembership $membership): void
    {
        DB::transaction(function () use ($actor, $organization, $membership) {
            $this->guard->assertActiveAdmin($actor, $organization);

            $membership = OrganizationMembership::query()
                ->whereKey($membership->id)
                ->where('organization_id', $organization->id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->firstOrFail();

            if ($membership->role === OrganizationRole::Admin) {
                $this->guard->release($organization);
            }

            $membership->forceFill(['revoked_at' => now()])->save();

            User::query()
                ->whereKey($membership->user_id)
                ->where('current_organization_id', $organization->id)
                ->update(['current_organization_id' => null]);
        });
    }
}
