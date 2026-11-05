<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\WorkflowVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workflows\InteractsWithExecution;
use Tests\Support\WorkflowGraphs;
use Tests\TestCase;

class WorkflowToolsTest extends TestCase
{
    use InteractsWithExecution;
    use RefreshDatabase;

    public function test_comparison_returns_structure_without_configuration_values(): void
    {
        $org = $this->signInMember(OrganizationRole::Admin);
        $definition = WorkflowGraphs::publishable();
        $uuid = $this->publishWorkflow($org, $definition);
        $first = WorkflowVersion::firstOrFail();
        $definition['graph_definition']['nodes'][1]['data']['description'] = 'PRIVATE_DESCRIPTION_SENTINEL';
        $this->putJson("/api/v1/organizations/{$org->uuid}/workflows/$uuid/draft", ['revision' => 3, ...$definition])->assertOk();
        $this->postJson("/api/v1/organizations/{$org->uuid}/workflows/$uuid/publish", ['revision' => 4])->assertOk();
        $second = WorkflowVersion::orderByDesc('id')->firstOrFail();
        $response = $this->getJson("/api/v1/organizations/{$org->uuid}/workflows/$uuid/compare?from={$first->uuid}&to={$second->uuid}")->assertOk()->assertJsonPath('data.from', 1)->assertJsonPath('data.to', 2)->assertJsonPath('data.changes.0.kind', 'configuration_changed')->assertJsonPath('data.changes.0.fields', ['description']);
        $this->assertStringNotContainsString('PRIVATE_DESCRIPTION_SENTINEL', $response->getContent());
        $this->assertStringNotContainsString('graph_definition', $response->getContent());
        $this->assertDatabaseCount('workflow_versions', 2);
        $this->assertSame('', $first->fresh()->graph_definition['nodes'][1]['data']['description']);
    }

    public function test_comparison_rejects_other_workflow_versions_and_other_tenants(): void
    {
        $org = $this->signInMember(OrganizationRole::Admin);
        $uuid = $this->publishWorkflow($org, WorkflowGraphs::publishable());
        $version = WorkflowVersion::firstOrFail();
        $this->publishWorkflow($org, WorkflowGraphs::publishable());
        $other = WorkflowVersion::orderByDesc('id')->firstOrFail();
        $url = "/api/v1/organizations/{$org->uuid}/workflows/$uuid/compare";
        $this->getJson("$url?from={$version->uuid}&to={$other->uuid}")->assertNotFound();
        $this->signInMember(OrganizationRole::Admin);
        $this->getJson("$url?from={$version->uuid}&to={$version->uuid}")->assertNotFound();
    }
}
