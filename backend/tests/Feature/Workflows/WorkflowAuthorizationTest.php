<?php

namespace Tests\Feature\Workflows;

use App\Enums\OrganizationRole;
use App\Enums\WorkflowStatus;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WorkflowGraphs;
use Tests\TestCase;

class WorkflowAuthorizationTest extends TestCase
{
    use InteractsWithWorkflows, RefreshDatabase;

    public function test_managers_can_edit_drafts_but_cannot_publish_or_archive(): void
    {
        $organization = $this->signInMember(OrganizationRole::Manager);
        $uuid = $this->createWorkflow($organization);
        $definition = WorkflowGraphs::publishable();

        $this->putJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/draft", [
            'revision' => 1,
            ...$definition,
        ])->assertOk();

        $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/publish", [
            'revision' => 2,
        ])->assertForbidden();

        $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/archive")
            ->assertForbidden();

        $this->assertSame(0, WorkflowVersion::query()->count());
        $this->assertSame(WorkflowStatus::Draft, Workflow::query()->where('uuid', $uuid)->firstOrFail()->status);
    }

    public function test_employees_can_view_only_published_workflows(): void
    {
        $adminOrganization = $this->signInMember(OrganizationRole::Admin);
        $draftId = $this->createWorkflow($adminOrganization, 'Still drafting');
        $publishedId = $this->createWorkflow($adminOrganization, 'Live approval');
        $definition = WorkflowGraphs::publishable();

        $this->putJson("/api/v1/organizations/{$adminOrganization->uuid}/workflows/{$publishedId}/draft", [
            'revision' => 1,
            ...$definition,
        ])->assertOk();
        $this->postJson("/api/v1/organizations/{$adminOrganization->uuid}/workflows/{$publishedId}/publish", [
            'revision' => 2,
        ])->assertOk();

        $changed = $definition;
        $changed['graph_definition']['nodes'][1]['data']['label'] = 'Unpublished manager edit';
        $this->putJson("/api/v1/organizations/{$adminOrganization->uuid}/workflows/{$publishedId}/draft", [
            'revision' => 3,
            ...$changed,
        ])->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.has_unpublished_changes', true)
            ->assertJsonPath('data.current_version_number', 1);

        $this->signInMember(OrganizationRole::Employee);
        $employee = \App\Models\User::query()->orderByDesc('id')->firstOrFail();
        \App\Models\OrganizationMembership::factory()->create([
            'organization_id' => $adminOrganization->id,
            'user_id' => $employee->id,
            'role' => OrganizationRole::Employee,
        ]);
        $employee->forceFill(['current_organization_id' => $adminOrganization->id])->save();
        $this->signIn($employee);

        $this->getJson("/api/v1/organizations/{$adminOrganization->uuid}/workflows?status=draft")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.uuid', $publishedId);

        $this->getJson("/api/v1/organizations/{$adminOrganization->uuid}/workflows/{$draftId}")
            ->assertForbidden();

        $this->getJson("/api/v1/organizations/{$adminOrganization->uuid}/workflows/{$publishedId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.has_unpublished_changes', false)
            ->assertJsonPath('data.published.graph_definition.nodes.1.data.label', 'Manager approval')
            ->assertJsonMissingPath('data.draft');

        $this->putJson("/api/v1/organizations/{$adminOrganization->uuid}/workflows/{$publishedId}/draft", [
            'revision' => 4,
            ...$definition,
        ])->assertForbidden();
        $this->postJson("/api/v1/organizations/{$adminOrganization->uuid}/workflows/{$publishedId}/publish", [
            'revision' => 4,
        ])->assertForbidden();
        $this->postJson("/api/v1/organizations/{$adminOrganization->uuid}/workflows/{$publishedId}/archive")
            ->assertForbidden();
    }

    public function test_cross_organization_workflow_access_is_not_found(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $uuid = $this->createWorkflow($organization);
        $other = $this->signInMember(OrganizationRole::Admin);

        $this->getJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}")->assertNotFound();
        $this->getJson("/api/v1/organizations/{$other->uuid}/workflows/{$uuid}")->assertNotFound();
        $this->putJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/draft", [
            'revision' => 1,
            'graph_definition' => ['nodes' => [], 'edges' => []],
            'form_definition' => ['fields' => []],
        ])->assertNotFound();
        $this->getJson("/api/v1/organizations/{$other->uuid}/workflows")
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }
}
