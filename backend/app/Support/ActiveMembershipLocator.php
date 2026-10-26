<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ActiveMembershipLocator
{
    public function organization(User $user, string $uuid): Organization
    {
        $this->guardUuid(Organization::class, $uuid);

        return Organization::query()
            ->where('uuid', $uuid)
            ->whereHas('activeMemberships', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->firstOrFail();
    }

    public function organizationFrom(Request $request): Organization
    {
        $resolved = $request->attributes->get('resolved_organization');

        if ($resolved instanceof Organization) {
            return $resolved;
        }

        $organization = $this->organization($request->user(), (string) $request->route('organization'));
        $request->attributes->set('resolved_organization', $organization);

        return $organization;
    }

    public function membership(Organization $organization, string $uuid): OrganizationMembership
    {
        $this->guardUuid(OrganizationMembership::class, $uuid);

        return $organization->activeMemberships()->where('uuid', $uuid)->firstOrFail();
    }

    public function invitation(Organization $organization, string $uuid): OrganizationInvitation
    {
        $this->guardUuid(OrganizationInvitation::class, $uuid);

        return $organization->invitations()
            ->where('uuid', $uuid)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();
    }

    private function guardUuid(string $model, string $uuid): void
    {
        if (! Str::isUuid($uuid)) {
            throw (new ModelNotFoundException)->setModel($model, [$uuid]);
        }
    }
}
