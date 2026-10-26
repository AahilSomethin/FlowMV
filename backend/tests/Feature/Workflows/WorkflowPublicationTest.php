<?php

namespace Tests\Feature\Workflows;

use App\Enums\OrganizationRole;
use App\Exceptions\WorkflowVersionImmutableException;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\WorkflowGraphs;
use Tests\TestCase;

class WorkflowPublicationTest extends TestCase
{
    use InteractsWithWorkflows, RefreshDatabase;

    public function test_invalid_graphs_cannot_be_published_and_create_no_version(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $uuid = $this->createWorkflow($organization);

        $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/draft/validate", [
            'revision' => 1,
            'graph_definition' => Workflow::query()->where('uuid', $uuid)->firstOrFail()->draft->graph_definition,
            'form_definition' => ['fields' => []],
        ])->assertStatus(422)
            ->assertJsonFragment(['code' => 'end_count']);

        $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/publish", [
            'revision' => 1,
        ])->assertStatus(422);

        $this->assertSame(0, WorkflowVersion::query()->count());
        $this->assertNull(Workflow::query()->where('uuid', $uuid)->value('current_published_version_id'));
    }

    public function test_a_valid_graph_publishes_an_immutable_version_and_keeps_later_drafts_separate(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $uuid = $this->createWorkflow($organization);
        $definition = WorkflowGraphs::publishable();

        $this->putJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/draft", [
            'revision' => 1,
            ...$definition,
        ])->assertOk();

        $published = $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/publish", [
            'revision' => 2,
        ])->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.current_version_number', 1)
            ->assertJsonPath('data.has_unpublished_changes', false)
            ->assertJsonPath('data.draft.revision', 3);

        $versionUuid = $published->json('data.published.uuid');
        $original = $published->json('data.published.graph_definition');

        $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/publish", [
            'revision' => 3,
        ])->assertStatus(422)->assertJsonFragment(['code' => 'unchanged_draft']);

        $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/publish", [
            'revision' => 2,
        ])->assertStatus(409);

        $edited = $definition;
        $edited['graph_definition']['nodes'][1]['data']['label'] = 'Finance approval';
        $this->putJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/draft", [
            'revision' => 3,
            ...$edited,
        ])->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.has_unpublished_changes', true);

        $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/publish", [
            'revision' => 4,
        ])->assertOk()->assertJsonPath('data.current_version_number', 2);

        $this->getJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/versions/{$versionUuid}")
            ->assertOk()
            ->assertJsonPath('data.version_number', 1)
            ->assertJsonPath('data.graph_definition', $original);

        $this->getJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/versions")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.graph_definition');

        $this->putJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/versions/{$versionUuid}", [
            'graph_definition' => ['nodes' => [], 'edges' => []],
        ])->assertStatus(405);
        $this->patchJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/versions/{$versionUuid}", [])
            ->assertStatus(405);
        $this->deleteJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/versions/{$versionUuid}")
            ->assertStatus(405);
    }

    public function test_published_version_rows_cannot_be_updated_through_eloquent(): void
    {
        $version = WorkflowVersion::factory()->create();
        $original = $version->graph_definition;

        try {
            $version->update(['graph_definition' => ['nodes' => [['id' => 'changed']], 'edges' => []]]);
            $this->fail('An update of a published version was accepted.');
        } catch (WorkflowVersionImmutableException) {
            $this->assertSame($original, $version->fresh()->graph_definition);
        }

        try {
            WorkflowVersion::query()->whereKey($version->id)->update(['content_hash' => str_repeat('a', 64)]);
            $this->fail('A query update of a published version was accepted.');
        } catch (WorkflowVersionImmutableException) {
            $this->assertSame($version->content_hash, $version->fresh()->content_hash);
        }
    }

    public function test_version_numbers_are_unique_per_workflow(): void
    {
        $version = WorkflowVersion::factory()->create(['version_number' => 1]);

        $this->expectException(QueryException::class);

        WorkflowVersion::factory()->create([
            'workflow_id' => $version->workflow_id,
            'version_number' => 1,
        ]);
    }

    public function test_archive_keeps_the_snapshot_and_blocks_further_edits(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $uuid = $this->createWorkflow($organization);
        $definition = WorkflowGraphs::publishable();

        $this->putJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/draft", [
            'revision' => 1,
            ...$definition,
        ])->assertOk();
        $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/publish", [
            'revision' => 2,
        ])->assertOk();

        $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.can_update', false)
            ->assertJsonPath('data.can_publish', false)
            ->assertJsonPath('data.current_version_number', 1);

        $this->putJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/draft", [
            'revision' => 3,
            ...$definition,
        ])->assertForbidden();
        $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/publish", [
            'revision' => 3,
        ])->assertForbidden();
        $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/archive")
            ->assertStatus(409);

        $this->assertSame(1, WorkflowVersion::query()->count());
    }

    public function test_a_revision_cannot_return_to_start_or_exhaust_into_a_completed_end(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $uuid = $this->createWorkflow($organization);
        $definition = WorkflowGraphs::publishable();
        $start = $definition['graph_definition']['nodes'][0]['id'];
        $completed = $definition['graph_definition']['nodes'][3]['id'];

        foreach ($definition['graph_definition']['edges'] as &$edge) {
            if ($edge['source_handle'] === 'return') {
                $edge['target'] = $start;
            }
        }
        unset($edge);
        $definition['graph_definition']['nodes'][2]['data']['return_to_node_id'] = $start;

        $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/draft/validate", [
            'revision' => 1,
            ...$definition,
        ])->assertStatus(422)->assertJsonFragment(['code' => 'revision_target']);

        $definition = WorkflowGraphs::publishable();

        foreach ($definition['graph_definition']['edges'] as &$edge) {
            if ($edge['source_handle'] === 'exhausted') {
                $edge['target'] = $completed;
            }
        }
        unset($edge);

        $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/draft/validate", [
            'revision' => 1,
            ...$definition,
        ])->assertStatus(422)->assertJsonFragment(['code' => 'exhausted_result']);

        $this->assertSame(0, WorkflowVersion::query()->count());
        $this->assertNotEmpty(Str::uuid());
    }
}
