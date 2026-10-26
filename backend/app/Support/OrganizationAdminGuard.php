<?php

namespace App\Support;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationAdminGuard
{
    public function assertActiveAdmin(User $user, Organization $organization): void
    {
        $isAdmin = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('role', OrganizationRole::Admin->value)
            ->lockForUpdate()
            ->exists();

        if (! $isAdmin) {
            abort(403);
        }
    }

    public function release(Organization $organization): void
    {
        $updated = DB::table('organizations')
            ->where('id', $organization->id)
            ->where('admin_count', '>', 1)
            ->decrement('admin_count');

        if ($updated !== 1) {
            throw ValidationException::withMessages([
                'role' => 'The organization must keep at least one admin.',
            ]);
        }
    }

    public function claim(Organization $organization): void
    {
        DB::table('organizations')
            ->where('id', $organization->id)
            ->increment('admin_count');
    }
}
