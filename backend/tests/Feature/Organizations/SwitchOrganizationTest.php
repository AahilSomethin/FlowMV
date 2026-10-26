<?php

namespace Tests\Feature\Organizations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SwitchOrganizationTest extends TestCase
{
    use InteractsWithOrganizations, RefreshDatabase;

    public function test_a_member_can_switch_workspaces(): void
    {
        $user = User::factory()->create();
        $colleague = User::factory()->create();
        $this->signIn($user);

        $alpha = $this->createOrganization('Alpha', 'alpha');
        $beta = $this->createOrganization('Beta', 'beta');
        $betaToken = $this->invitationToken($beta, $colleague->email);

        $this->signIn($colleague);
        $this->postJson('/api/v1/invitations/accept', ['token' => $betaToken])->assertSuccessful();

        $this->signIn($user);
        $this->postJson("/api/v1/organizations/{$alpha}/switch")->assertOk()
            ->assertJsonPath('data.current_organization.uuid', $alpha);

        $this->refreshGuards();

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.current_organization.uuid', $alpha);

        $alphaMembers = $this->getJson("/api/v1/organizations/{$alpha}/members")->assertOk()->json('data');
        $betaMembers = $this->getJson("/api/v1/organizations/{$beta}/members")->assertOk()->json('data');

        $this->assertCount(1, $alphaMembers);
        $this->assertSame($user->email, $alphaMembers[0]['email']);
        $this->assertTrue(collect($betaMembers)->contains(
            fn (array $member) => $member['email'] === $colleague->email,
        ));
        $this->assertFalse(collect($alphaMembers)->contains(
            fn (array $member) => $member['email'] === $colleague->email,
        ));
    }

    public function test_a_user_cannot_switch_into_another_organization(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $this->signIn($owner);
        $organization = $this->createOrganization('Private', 'private');

        $this->signIn($stranger);
        $this->createOrganization('Other', 'other');

        $this->postJson("/api/v1/organizations/{$organization}/switch")->assertNotFound();
        $this->getJson('/api/v1/me')->assertJsonPath('data.current_organization.slug', 'other');
    }
}
