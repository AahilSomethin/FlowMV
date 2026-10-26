<?php

namespace Tests\Feature\Organizations;

use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use InteractsWithOrganizations, RefreshDatabase;

    public function test_a_verified_member_can_accept_an_invitation_without_changing_workspace(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $this->signIn($member);
        $home = $this->createOrganization('Home', 'home');

        $this->signIn($owner);
        $guestOrg = $this->createOrganization('Guest Org', 'guest-org');
        $token = $this->invitationToken($guestOrg, $member->email, 'manager');

        $this->assertSame(2, User::query()->count());

        $this->signIn($member);
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertSuccessful()
            ->assertJsonPath('data.uuid', $guestOrg)
            ->assertJsonPath('data.role', 'manager');

        $this->assertSame(2, User::query()->count());
        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.current_organization.uuid', $home)
            ->assertJsonCount(2, 'data.organizations');

        $this->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertUnprocessable()
            ->assertJsonPath('errors.token.0', 'This invitation is no longer valid.');
    }

    public function test_an_expired_invitation_cannot_be_accepted(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $this->signIn($owner);
        $organization = $this->createOrganization();
        $token = $this->invitationToken($organization, $member->email);
        OrganizationInvitation::query()->update(['expires_at' => now()->subMinute()]);

        $this->signIn($member);
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertUnprocessable()
            ->assertJsonPath('errors.token.0', 'This invitation is no longer valid.');

        $this->assertDatabaseMissing('organization_memberships', [
            'user_id' => $member->id,
        ]);
    }

    public function test_the_wrong_email_cannot_accept_an_invitation(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $stranger = User::factory()->create();

        $this->signIn($owner);
        $organization = $this->createOrganization();
        $token = $this->invitationToken($organization, $member->email);

        $this->signIn($stranger);
        $response = $this->postJson('/api/v1/invitations/accept', ['token' => $token]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.token.0', 'This invitation was sent to a different email address.');
        $this->assertStringNotContainsString($token, (string) json_encode($response->json()));

        $this->assertNull(OrganizationInvitation::query()->firstOrFail()->accepted_at);
        $this->assertDatabaseMissing('organization_memberships', [
            'user_id' => $stranger->id,
        ]);
    }

    public function test_an_unverified_email_cannot_accept_an_invitation(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->unverified()->create();

        $this->signIn($owner);
        $organization = $this->createOrganization();
        $token = $this->invitationToken($organization, $member->email);

        $this->signIn($member);
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertUnprocessable()
            ->assertJsonPath('errors.token.0', 'Verify your email before accepting this invitation.');

        $this->assertNull(OrganizationInvitation::query()->firstOrFail()->accepted_at);
    }

    public function test_invitation_creation_and_acceptance_are_rate_limited(): void
    {
        Cache::flush();

        $owner = User::factory()->create();
        $this->signIn($owner);
        $organization = $this->createOrganization();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->invitationToken($organization, "person{$attempt}@example.com");
        }

        $this->postJson("/api/v1/organizations/{$organization}/invitations", [
            'email' => 'overflow@example.com',
            'role' => 'employee',
        ])->assertTooManyRequests();

        Cache::flush();
        $this->signIn($owner);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/invitations/accept', [
                'token' => "not-a-real-token-{$attempt}",
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/invitations/accept', [
            'token' => 'not-a-real-token-final',
        ])->assertTooManyRequests();
    }
}
