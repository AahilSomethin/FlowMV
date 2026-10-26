<?php

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\OrganizationAdminGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AcceptOrganizationInvitation
{
    public function __construct(private OrganizationAdminGuard $guard) {}

    public function __invoke(User $user, #[\SensitiveParameter] string $token): OrganizationMembership
    {
        if ($user->email_verified_at === null) {
            throw ValidationException::withMessages([
                'token' => 'Verify your email before accepting this invitation.',
            ]);
        }

        if ($token === '') {
            throw ValidationException::withMessages([
                'token' => 'This invitation is no longer valid.',
            ]);
        }

        return DB::transaction(function () use ($user, $token) {
            $invitation = OrganizationInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || ! $invitation->isPending()) {
                throw ValidationException::withMessages([
                    'token' => 'This invitation is no longer valid.',
                ]);
            }

            if (Str::lower($invitation->email) !== Str::lower($user->email)) {
                throw ValidationException::withMessages([
                    'token' => 'This invitation was sent to a different email address.',
                ]);
            }

            $claimed = OrganizationInvitation::query()
                ->whereKey($invitation->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update([
                    'accepted_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                throw ValidationException::withMessages([
                    'token' => 'This invitation is no longer valid.',
                ]);
            }

            $membership = OrganizationMembership::query()
                ->where('organization_id', $invitation->organization_id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($membership?->revoked_at === null && $membership !== null) {
                throw ValidationException::withMessages([
                    'token' => 'You are already a member of this organization.',
                ]);
            }

            if ($membership === null) {
                $membership = OrganizationMembership::query()->create([
                    'organization_id' => $invitation->organization_id,
                    'user_id' => $user->id,
                    'role' => $invitation->role,
                ]);
            } else {
                $membership->forceFill([
                    'role' => $invitation->role,
                    'revoked_at' => null,
                ])->save();
            }

            if ($invitation->role === OrganizationRole::Admin) {
                $this->guard->claim($invitation->organization);
            }

            if ($user->current_organization_id === null) {
                $user->forceFill([
                    'current_organization_id' => $invitation->organization_id,
                ])->save();
            }

            return $membership->load('organization');
        });
    }
}
