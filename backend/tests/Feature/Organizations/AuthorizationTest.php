<?php

namespace Tests\Feature\Organizations;

use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use InteractsWithOrganizations, RefreshDatabase;

    public function test_managers_and_employees_cannot_manage_memberships(): void
    {
        foreach (['manager', 'employee'] as $role) {
            $owner = User::factory()->create();
            $member = User::factory()->create();

            $this->signIn($owner);
            $organization = $this->createOrganization(ucfirst($role).' Org', $role.'-org');
            $token = $this->invitationToken($organization, $member->email, $role);

            $this->signIn($member);
            $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertSuccessful();

            $this->getJson("/api/v1/organizations/{$organization}/members")->assertOk();
            $this->getJson("/api/v1/organizations/{$organization}/invitations")->assertForbidden();
            $this->postJson("/api/v1/organizations/{$organization}/invitations", [
                'email' => 'new@example.com',
                'role' => 'employee',
            ])->assertForbidden();

            $ownerMembership = OrganizationMembership::query()->where('user_id', $owner->id)->firstOrFail();
            $this->patchJson("/api/v1/organizations/{$organization}/members/{$ownerMembership->uuid}", [
                'role' => 'employee',
            ])->assertForbidden();
            $this->deleteJson("/api/v1/organizations/{$organization}/members/{$ownerMembership->uuid}")
                ->assertForbidden();
        }
    }

    public function test_cross_organization_access_is_not_found(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->signIn($owner);
        $organization = $this->createOrganization('Visible', 'visible');
        $membership = OrganizationMembership::query()->where('user_id', $owner->id)->firstOrFail();

        $this->signIn($other);
        $this->createOrganization('Separate', 'separate');

        $this->getJson("/api/v1/organizations/{$organization}/members")->assertNotFound();
        $this->getJson("/api/v1/organizations/{$organization}/invitations")->assertNotFound();
        $this->patchJson("/api/v1/organizations/{$organization}/members/{$membership->uuid}", [
            'role' => 'employee',
        ])->assertNotFound();
        $this->deleteJson("/api/v1/organizations/{$organization}/members/{$membership->uuid}")
            ->assertNotFound();

        $this->getJson('/api/v1/organizations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'separate');
    }

    public function test_revocation_and_role_changes_apply_on_the_next_request(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $employee = User::factory()->create();

        $this->signIn($owner);
        $organization = $this->createOrganization('Live', 'live');
        $adminToken = $this->invitationToken($organization, $admin->email, 'admin');
        $employeeToken = $this->invitationToken($organization, $employee->email, 'employee');

        $this->signIn($admin);
        $this->postJson('/api/v1/invitations/accept', ['token' => $adminToken])->assertSuccessful();
        $this->postJson("/api/v1/organizations/{$organization}/invitations", [
            'email' => 'later@example.com',
            'role' => 'employee',
        ])->assertCreated();

        $this->signIn($employee);
        $this->postJson('/api/v1/invitations/accept', ['token' => $employeeToken])->assertSuccessful();
        $this->getJson("/api/v1/organizations/{$organization}/members")->assertOk();

        $this->signIn($owner);
        $adminMembership = OrganizationMembership::query()->where('user_id', $admin->id)->firstOrFail();
        $employeeMembership = OrganizationMembership::query()->where('user_id', $employee->id)->firstOrFail();

        $this->patchJson("/api/v1/organizations/{$organization}/members/{$adminMembership->uuid}", [
            'role' => 'employee',
        ])->assertOk()->assertJsonPath('data.role', 'employee');

        $this->signIn($admin);
        $this->postJson("/api/v1/organizations/{$organization}/invitations", [
            'email' => 'blocked@example.com',
            'role' => 'employee',
        ])->assertForbidden();

        $this->signIn($owner);
        $this->deleteJson("/api/v1/organizations/{$organization}/members/{$employeeMembership->uuid}")
            ->assertNoContent();

        $this->signIn($employee);
        $this->getJson("/api/v1/organizations/{$organization}/members")->assertNotFound();
        $this->getJson('/api/v1/me')->assertJsonPath('data.current_organization', null);
    }
}
