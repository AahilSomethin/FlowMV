<?php

namespace Tests\Feature\Workflows;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait InteractsWithExecution
{
    use InteractsWithWorkflows;

    protected function addMember(Organization $organization, OrganizationRole $role): User
    {
        $user = User::factory()->create();
        OrganizationMembership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return $user;
    }

    /**
     * @param  array{graph_definition: array<string, mixed>, form_definition: array<string, mixed>}  $definition
     */
    protected function publishWorkflow(Organization $organization, array $definition): string
    {
        $uuid = $this->createWorkflow($organization);
        $this->putJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/draft", [
            'revision' => 1,
            ...$definition,
        ])->assertOk();
        $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/publish", [
            'revision' => 2,
        ])->assertOk();

        return $uuid;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function startInstance(Organization $organization, string $workflow, array $data = [], ?string $key = null): TestResponse
    {
        return $this->postJson(
            "/api/v1/organizations/{$organization->uuid}/workflows/{$workflow}/instances",
            ['data' => $data],
            ['Idempotency-Key' => $key ?? (string) Str::uuid()],
        );
    }

    protected function decide(Organization $organization, string $task, string $decision, ?string $key = null): TestResponse
    {
        return $this->postJson(
            "/api/v1/organizations/{$organization->uuid}/tasks/{$task}/decisions",
            ['decision' => $decision],
            ['Idempotency-Key' => $key ?? (string) Str::uuid()],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function revise(Organization $organization, string $task, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson(
            "/api/v1/organizations/{$organization->uuid}/tasks/{$task}/revisions",
            ['data' => $data],
            ['Idempotency-Key' => $key ?? (string) Str::uuid()],
        );
    }
}
