<?php

namespace Tests\Feature\Workflows;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WorkflowGraphs;
use Tests\TestCase;

class WorkflowManagementTest extends TestCase
{
    use InteractsWithWorkflows, RefreshDatabase;

    public function test_workflows_are_created_and_listed_inside_the_organization(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $other = Organization::factory()->create();
        $uuid = $this->createWorkflow($organization, 'Facilities request');

        Workflow::factory()->create([
            'organization_id' => $other->id,
            'name' => 'Hidden workflow',
        ]);

        $this->getJson("/api/v1/organizations/{$organization->uuid}/workflows")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.uuid', $uuid)
            ->assertJsonPath('data.items.0.status', 'draft')
            ->assertJsonPath('data.items.0.has_unpublished_changes', true)
            ->assertJsonMissingPath('data.items.0.draft');

        $this->getJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Facilities request')
            ->assertJsonPath('data.draft.revision', 1)
            ->assertJsonPath('data.draft.graph_definition.nodes.0.type', 'start')
            ->assertJsonMissingPath('data.published');
    }

    public function test_search_and_status_filters_apply_to_the_current_organization(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $this->createWorkflow($organization, 'Purchase approval');
        $this->createWorkflow($organization, 'Leave request');

        $this->getJson("/api/v1/organizations/{$organization->uuid}/workflows?search=leave&status=draft")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.name', 'Leave request');
    }

    public function test_metadata_and_drafts_round_trip(): void
    {
        $organization = $this->signInMember(OrganizationRole::Manager);
        $uuid = $this->createWorkflow($organization);
        $definition = WorkflowGraphs::publishable();

        $this->patchJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}", [
            'name' => 'Updated purchase approval',
            'description' => 'Includes a revision loop.',
        ])->assertOk()->assertJsonPath('data.name', 'Updated purchase approval');

        $saved = $this->putJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/draft", [
            'revision' => 1,
            ...$definition,
        ])->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.draft.revision', 2)
            ->assertJsonPath('data.draft.graph_definition.nodes.1.type', 'approval');

        $this->getJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.draft.graph_definition', $saved->json('data.draft.graph_definition'))
            ->assertJsonPath('data.draft.form_definition', $saved->json('data.draft.form_definition'));
    }

    public function test_a_stale_draft_revision_is_rejected(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $uuid = $this->createWorkflow($organization);
        $definition = WorkflowGraphs::publishable();

        $this->putJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/draft", [
            'revision' => 1,
            ...$definition,
        ])->assertOk();

        $this->putJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/draft", [
            'revision' => 1,
            ...$definition,
        ])->assertStatus(409);

        $this->assertSame(2, Workflow::query()->where('uuid', $uuid)->firstOrFail()->draft->revision);
    }

    public function test_unsafe_definition_content_is_rejected_before_it_is_stored(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $uuid = $this->createWorkflow($organization);
        $definition = WorkflowGraphs::publishable();
        $definition['graph_definition']['nodes'][0]['data']['label'] = '<script>alert(1)</script>';

        $this->putJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/draft", [
            'revision' => 1,
            ...$definition,
        ])->assertStatus(422)
            ->assertJsonPath('violations.0.code', 'unsafe_content');

        $this->assertSame('Start', Workflow::query()->where('uuid', $uuid)->firstOrFail()->draft->graph_definition['nodes'][0]['data']['label']);
    }
}
