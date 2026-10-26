<?php

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\OrganizationAdminGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class InviteOrganizationMember
{
    public function __construct(private OrganizationAdminGuard $guard) {}

    public function __invoke(User $admin, Organization $organization, string $email, OrganizationRole $role): OrganizationInvitation
    {
        $email = Str::lower($email);

        [$invitation, $token] = DB::transaction(function () use ($admin, $organization, $email, $role) {
            $this->guard->assertActiveAdmin($admin, $organization);

            $alreadyMember = OrganizationMembership::query()
                ->where('organization_id', $organization->id)
                ->whereNull('revoked_at')
                ->whereHas('user', function ($query) use ($email) {
                    $query->whereRaw('lower(email) = ?', [$email]);
                })
                ->exists();

            if ($alreadyMember) {
                throw ValidationException::withMessages([
                    'email' => 'That person is already a member of this organization.',
                ]);
            }

            $pending = OrganizationInvitation::query()
                ->where('organization_id', $organization->id)
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists();

            if ($pending) {
                throw ValidationException::withMessages([
                    'email' => 'An invitation is already pending for that email address.',
                ]);
            }

            $token = $this->token();

            $invitation = OrganizationInvitation::query()->create([
                'organization_id' => $organization->id,
                'email' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays((int) config('flowmv.invitation_ttl_days', 7)),
                'invited_by' => $admin->id,
            ]);

            return [$invitation, $token];
        });

        $this->send($organization, $email, $role, $invitation, $token);

        return $invitation;
    }

    private function send(
        Organization $organization,
        string $email,
        OrganizationRole $role,
        OrganizationInvitation $invitation,
        #[\SensitiveParameter] string $token,
    ): void {
        try {
            Mail::to($email)->send(new OrganizationInvitationMail($organization, $role->value, $token));
        } catch (Throwable) {
            Log::warning('Invitation mail could not be sent.', [
                'invitation' => $invitation->uuid,
            ]);
        }
    }

    private function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
