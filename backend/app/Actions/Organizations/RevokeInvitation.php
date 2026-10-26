<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Support\OrganizationAdminGuard;
use Illuminate\Support\Facades\DB;

class RevokeInvitation
{
    public function __construct(private OrganizationAdminGuard $guard) {}

    public function __invoke(User $actor, Organization $organization, OrganizationInvitation $invitation): void
    {
        DB::transaction(function () use ($actor, $organization, $invitation) {
            $this->guard->assertActiveAdmin($actor, $organization);

            $updated = OrganizationInvitation::query()
                ->whereKey($invitation->id)
                ->where('organization_id', $organization->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update([
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                abort(404);
            }
        });
    }
}
