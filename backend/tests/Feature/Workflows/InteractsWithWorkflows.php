<?php

namespace Tests\Feature\Workflows;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Tests\Feature\Organizations\InteractsWithOrganizations;

trait InteractsWithWorkflows
{
    use InteractsWithOrganizations;

    protected function signInMember(OrganizationRole $role): Organization
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create([
            'created_by' => $user->id,
            'admin_count' => 1,
        ]);

        OrganizationMembership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        $user->forceFill(['current_organization_id' => $organization->id])->save();
        $this->signIn($user);

        return $organization;
    }

    protected function createWorkflow(Organization $organization, string $name = 'Purchase approval'): string
    {
        return $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows", [
            'name' => $name,
            'description' => 'Routes a purchase for approval.',
        ])->assertCreated()->json('data.uuid');
    }
}
