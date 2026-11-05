<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Services\Workflows\WorkflowDefinitionNormalizer;
use App\Services\Workflows\WorkflowDefinitionValidator;
use App\Services\Workflows\WorkflowTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Workflows\InteractsWithExecution;
use Tests\TestCase;

class WorkflowTemplatesTest extends TestCase
{
    use InteractsWithExecution;
    use RefreshDatabase;

    public function test_all_ten_recipes_use_valid_existing_graph_contracts(): void
    {
        $templates = app(WorkflowTemplates::class);
        $this->assertCount(10, $templates->catalog());
        foreach ($templates->catalog() as $template) {
            $b = ['connection_id' => (string) Str::uuid(), 'destination' => $template['id'] === 'slack-intake' ? 'C12345678' : '888', 'document_template_id' => (string) Str::uuid(), 'email_sender' => 'applicant@example.test', 'document_fields' => [['id' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true], ['id' => 'evidence', 'label' => 'Evidence', 'type' => 'supporting_document', 'required' => true]]];
            $d = $templates->definition($template['id'], $b);
            $n = app(WorkflowDefinitionNormalizer::class)->normalize($d['graph_definition'], $d['form_definition']);
            $this->assertTrue($n->ok(), $template['id'].': '.json_encode($n->violations));
            $this->assertSame([], app(WorkflowDefinitionValidator::class)->validate($n->graph, $n->form), $template['id']);
        }
    }

    public function test_template_creation_is_an_unpublished_draft_without_grants_or_jobs(): void
    {
        $org = $this->signInMember(OrganizationRole::Manager);
        Queue::fake();
        $base = "/api/v1/organizations/{$org->uuid}/workflow-templates";
        $this->getJson($base)->assertOk()->assertJsonCount(10, 'data.items')->assertJsonPath('data.can_create', true);
        $this->postJson("$base/expense-reimbursement", ['name' => 'Team reimbursement', 'bindings' => []])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.current_version_number', null)->assertJsonPath('data.draft.revision', 2);
        foreach (['workflow_versions', 'workflow_instances', 'workflow_connection_grants', 'integration_trigger_subscriptions', 'slack_trigger_subscriptions', 'email_trigger_bindings'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('workflows', 1);
        Queue::assertNothingPushed();
    }

    public function test_employee_cannot_instantiate_and_missing_provider_bindings_do_not_create_placeholder_drafts(): void
    {
        $org = $this->signInMember(OrganizationRole::Admin);
        $base = "/api/v1/organizations/{$org->uuid}/workflow-templates";
        $this->postJson("$base/slack-intake", ['name' => 'Missing setup', 'bindings' => []])->assertUnprocessable();
        $this->postJson("$base/leave-request", ['name' => 'No secrets', 'bindings' => ['bot_token' => 'not-allowed']])->assertUnprocessable();
        $this->signIn($this->addMember($org, OrganizationRole::Employee));
        $this->getJson($base)->assertOk()->assertJsonPath('data.can_create', false);
        $this->postJson("$base/leave-request", ['name' => 'Denied', 'bindings' => []])->assertForbidden();
        $this->assertDatabaseCount('workflows', 0);
    }
}
