<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\OrganizationAdminGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LastAdminTest extends TestCase
{
    use InteractsWithOrganizations, RefreshDatabase;

    public function test_the_last_admin_cannot_be_demoted_or_removed(): void
    {
        $owner = User::factory()->create();
        $this->signIn($owner);
        $organization = $this->createOrganization('Solo', 'solo');
        $membership = OrganizationMembership::query()->where('user_id', $owner->id)->firstOrFail();

        $this->patchJson("/api/v1/organizations/{$organization}/members/{$membership->uuid}", [
            'role' => 'manager',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.role.0', 'The organization must keep at least one admin.');

        $this->deleteJson("/api/v1/organizations/{$organization}/members/{$membership->uuid}")
            ->assertUnprocessable()
            ->assertJsonPath('errors.role.0', 'The organization must keep at least one admin.');

        $this->assertSame(1, Organization::query()->where('uuid', $organization)->value('admin_count'));
        $this->assertSame('admin', $membership->fresh()->role->value);
        $this->assertNull($membership->fresh()->revoked_at);
    }

    public function test_an_admin_can_be_demoted_when_another_admin_remains(): void
    {
        $owner = User::factory()->create();
        $second = User::factory()->create();
        $this->signIn($owner);
        $organization = $this->createOrganization('Pair', 'pair');
        $token = $this->invitationToken($organization, $second->email, 'admin');

        $this->signIn($second);
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertSuccessful();

        $this->signIn($owner);
        $secondMembership = OrganizationMembership::query()->where('user_id', $second->id)->firstOrFail();
        $this->patchJson("/api/v1/organizations/{$organization}/members/{$secondMembership->uuid}", [
            'role' => 'employee',
        ])->assertOk();

        $this->assertSame(1, Organization::query()->where('uuid', $organization)->value('admin_count'));

        $ownerMembership = OrganizationMembership::query()->where('user_id', $owner->id)->firstOrFail();
        $this->patchJson("/api/v1/organizations/{$organization}/members/{$ownerMembership->uuid}", [
            'role' => 'employee',
        ])->assertUnprocessable();

        $this->assertSame(1, Organization::query()->where('uuid', $organization)->value('admin_count'));
    }

    public function test_a_second_admin_release_cannot_drop_the_count_below_one(): void
    {
        $organization = Organization::factory()->create(['admin_count' => 2]);
        $guard = app(OrganizationAdminGuard::class);

        $guard->release($organization);
        $this->assertSame(1, $organization->fresh()->admin_count);

        try {
            $guard->release($organization->fresh());
            $this->fail('The second admin release was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'The organization must keep at least one admin.',
                $exception->errors()['role'][0],
            );
        }

        $this->assertSame(1, $organization->fresh()->admin_count);
    }
}
