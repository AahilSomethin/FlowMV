<?php

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\OrganizationAdminGuard;
use Illuminate\Support\Facades\DB;

class ChangeMembershipRole
{
    public function __construct(private OrganizationAdminGuard $guard) {}

    public function __invoke(
        User $actor,
        Organization $organization,
        OrganizationMembership $membership,
        OrganizationRole $role,
    ): OrganizationMembership {
        return DB::transaction(function () use ($actor, $organization, $membership, $role) {
            $this->guard->assertActiveAdmin($actor, $organization);

            $membership = OrganizationMembership::query()
                ->whereKey($membership->id)
                ->where('organization_id', $organization->id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->firstOrFail();

            if ($membership->role === $role) {
                return $membership->load('user');
            }

            if ($membership->role === OrganizationRole::Admin && $role !== OrganizationRole::Admin) {
                $this->guard->release($organization);
            }

            if ($membership->role !== OrganizationRole::Admin && $role === OrganizationRole::Admin) {
                $this->guard->claim($organization);
            }

            $membership->role = $role;
            $membership->save();

            return $membership->load('user');
        });
    }
}
