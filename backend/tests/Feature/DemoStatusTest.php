<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\DemoEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_requires_membership_and_does_not_enable_demo_from_client_input(): void
    {
        $org = Organization::factory()->create();
        $url = "/api/v1/organizations/{$org->uuid}/demo-status?demo=true";
        $this->getJson($url)->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user)->getJson($url)->assertNotFound();
        OrganizationMembership::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
        $this->getJson($url)->assertOk()->assertExactJson(['data' => ['enabled' => false, 'notice' => null]]);
    }

    public function test_all_workspaces_on_an_owned_demo_show_simulated_transport_notice(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        OrganizationMembership::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
        $this->app->detectEnvironment(fn () => 'demo');
        $this->mock(DemoEnvironment::class)->shouldReceive('assertOwned')->once();
        $this->actingAs($user)->getJson("/api/v1/organizations/{$org->uuid}/demo-status")
            ->assertOk()->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.notice', 'Fictional demo workspace. Internal requests and decisions are real; provider transports and document scanning are simulated. Sign out to change roles.');
        $this->assertDatabaseCount('demo_datasets', 0);
    }
}
