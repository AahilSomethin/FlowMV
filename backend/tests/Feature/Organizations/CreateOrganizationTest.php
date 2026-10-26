<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateOrganizationTest extends TestCase
{
    use InteractsWithOrganizations, RefreshDatabase;

    public function test_an_authenticated_user_can_create_an_organization(): void
    {
        $user = User::factory()->create();
        $this->signIn($user);

        $response = $this->postJson('/api/v1/organizations', [
            'name' => 'Ada Labs',
            'slug' => 'ada-labs',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Ada Labs')
            ->assertJsonPath('data.slug', 'ada-labs')
            ->assertJsonPath('data.role', 'admin');

        $this->assertDatabaseHas('organizations', [
            'slug' => 'ada-labs',
            'created_by' => $user->id,
            'admin_count' => 1,
        ]);
    }

    public function test_the_creator_becomes_the_first_admin(): void
    {
        $user = User::factory()->create();
        $this->signIn($user);

        $uuid = $this->createOrganization('Flow Lab', 'flow-lab');
        $organization = Organization::query()->where('uuid', $uuid)->firstOrFail();

        $this->assertSame($user->id, $organization->created_by);
        $this->assertSame(1, $organization->admin_count);
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'admin',
            'revoked_at' => null,
        ]);
        $this->assertSame($organization->id, $user->fresh()->current_organization_id);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.current_organization.uuid', $uuid)
            ->assertJsonPath('data.current_organization.role', 'admin');
    }

    public function test_guests_cannot_create_an_organization(): void
    {
        $this->postJson('/api/v1/organizations', [
            'name' => 'Hidden',
            'slug' => 'hidden',
        ])->assertUnauthorized();
    }

    public function test_a_duplicate_slug_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->signIn($user);
        $this->createOrganization('First', 'shared');

        $this->postJson('/api/v1/organizations', [
            'name' => 'Second',
            'slug' => 'shared',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }
}
