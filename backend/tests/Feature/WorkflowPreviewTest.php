<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Services\Integrations\CredentialVault;
use App\Services\Integrations\EmailAuthorization;
use App\Services\Integrations\EmailHttp;
use App\Services\Integrations\TelegramApi;
use App\Services\Workflows\WorkflowTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Workflows\InteractsWithExecution;
use Tests\Support\WorkflowGraphs;
use Tests\TestCase;

class WorkflowPreviewTest extends TestCase
{
    use InteractsWithExecution;
    use RefreshDatabase;

    public function test_invalid_synthetic_answers_return_safe_field_findings_without_work(): void
    {
        $org = $this->signInMember(OrganizationRole::Admin);
        $uuid = $this->createWorkflow($org);
        $definition = app(WorkflowTemplates::class)->definition('leave-request');
        $this->mock(CredentialVault::class)->shouldNotReceive('decrypt');
        Queue::fake();
        $response = $this->postJson("/api/v1/organizations/{$org->uuid}/workflows/$uuid/preview", [...$definition, 'preview_inputs' => ['details' => 'PRIVATE_SAMPLE_SENTINEL', 'start_date' => 'INVALID_PRIVATE_VALUE', 'end_date' => '2026-11-03']])
            ->assertOk()->assertJsonPath('data.result', 'invalid')->assertJsonPath('data.findings.0.code', 'preview_input_invalid')
            ->assertJsonPath('data.findings.0.field_id', 'start_date')->assertJsonCount(0, 'data.trace')->assertJsonCount(4, 'data.skipped');
        $this->assertStringNotContainsString('PRIVATE_SAMPLE_SENTINEL', $response->getContent());
        $this->assertStringNotContainsString('INVALID_PRIVATE_VALUE', $response->getContent());
        $definition['form_definition']['fields'][0]['type'] = 'file';
        $definition['form_definition']['fields'][0]['validation'] = [];
        $this->postJson("/api/v1/organizations/{$org->uuid}/workflows/$uuid/preview", $definition)
            ->assertOk()->assertJsonPath('data.result', 'unavailable')->assertJsonPath('data.findings.0.code', 'preview_upload_unavailable')
            ->assertJsonCount(0, 'data.trace');
        foreach (['workflow_instances', 'workflow_tasks', 'workflow_events', 'integration_audit_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Queue::assertNothingPushed();
    }

    public function test_hypothetical_approval_creates_no_requests_decisions_jobs_or_mail(): void
    {
        $org = $this->signInMember(OrganizationRole::Admin);
        $definition = WorkflowGraphs::publishable();
        $uuid = $this->createWorkflow($org);
        $approval = $definition['graph_definition']['nodes'][1]['id'];
        Queue::fake();
        Mail::fake();
        $this->mock(CredentialVault::class)->shouldNotReceive('decrypt');
        $before = DB::table('workflow_drafts')->value('content_hash');
        $response = $this->postJson("/api/v1/organizations/{$org->uuid}/workflows/$uuid/preview", [...$definition, 'preview_inputs' => [], 'scenario' => ['decisions' => [$approval => 'approved']]])->assertOk()->assertJsonPath('data.result', 'completed')->assertJsonPath('data.persisted', false)->assertJsonPath('data.external_delivery', false);
        $this->assertCount(3, $response->json('data.trace'));
        foreach (['workflow_instances', 'workflow_tasks', 'workflow_events', 'workflow_submission_snapshots', 'workflow_versions', 'integration_audit_events', 'integration_action_outbox', 'slack_action_outbox', 'sms_action_outbox', 'email_action_outbox'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($before, DB::table('workflow_drafts')->value('content_hash'));
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_external_action_checks_grants_without_reading_live_credentials_or_sending(): void
    {
        $org = $this->signInMember(OrganizationRole::Admin);
        config(['integrations.telegram_enabled' => true, 'integrations.telegram_simulated' => false, 'integrations.keys.v1' => base64_encode(random_bytes(32))]);
        $uuid = $this->createWorkflow($org);
        $workflow = DB::table('workflows')->first();
        $id = (string) Str::uuid();
        DB::table('integration_approvals')->insert(['organization_id' => $org->id, 'provider_id' => 'telegram', 'approved' => true]);
        DB::table('connections')->insert(['id' => $id, 'organization_id' => $org->id, 'provider_id' => 'telegram', 'name' => 'Configured credentials', 'credential_type' => 'bot_token', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('credential_secrets')->insert(['connection_id' => $id, ...app(CredentialVault::class)->encrypt(['bot_token' => '123456:'.str_repeat('SYNTHETIC_', 4)], $id), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('telegram_bots')->insert(['connection_id' => $id, 'bot_id' => '123456', 'active_bot_id' => '123456', 'username' => 'preview_bot', 'approved_by' => auth()->id()]);
        DB::table('telegram_destinations')->insert(['connection_id' => $id, 'chat_id' => '888', 'chat_type' => 'private', 'label' => 'Synthetic destination', 'approved_by' => auth()->id()]);
        DB::table('workflow_connection_grants')->insert(['organization_id' => $org->id, 'connection_id' => $id, 'workflow_id' => $workflow->id, 'operation' => 'telegram.notification', 'authorized_by' => auth()->id()]);
        [$start,$send,$end] = array_map(fn () => (string) Str::uuid(), range(1, 3));
        $definition = ['graph_definition' => ['nodes' => [WorkflowGraphs::node($start, 'start', 0, 0, ['label' => 'Start']), WorkflowGraphs::node($send, 'telegram_notification', 200, 0, ['label' => 'Notify', 'connection_id' => $id, 'destination' => '888', 'text' => 'PRIVATE_MESSAGE_SENTINEL', 'allowed_field_ids' => []]), WorkflowGraphs::node($end, 'end', 400, 0, ['label' => 'End', 'result' => 'completed'])], 'edges' => [WorkflowGraphs::edge($start, $send, 'out'), WorkflowGraphs::edge($send, $end, 'out')]], 'form_definition' => ['fields' => []]];
        $api = new TelegramApi(new Factory);
        $api->http->preventStrayRequests();
        $this->app->instance(TelegramApi::class, $api);
        $this->mock(CredentialVault::class)->shouldNotReceive('decrypt');
        Queue::fake();
        $url = "/api/v1/organizations/{$org->uuid}/workflows/$uuid/preview";
        $response = $this->postJson($url, $definition)->assertOk()->assertJsonPath('data.result', 'completed');
        $this->assertStringNotContainsString('PRIVATE_MESSAGE_SENTINEL', $response->getContent());
        $this->assertStringNotContainsString('SYNTHETIC_', $response->getContent());
        DB::table('workflow_connection_grants')->delete();
        $this->postJson($url, $definition)->assertOk()->assertJsonPath('data.result', 'blocked');
        $api->http->assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('workflow_instances', 0);
        $this->assertDatabaseCount('integration_audit_events', 0);
    }

    public function test_employee_and_other_tenants_cannot_preview_and_source_versions_are_scoped(): void
    {
        $org = $this->signInMember(OrganizationRole::Admin);
        $uuid = $this->publishWorkflow($org, WorkflowGraphs::publishable());
        $url = "/api/v1/organizations/{$org->uuid}/workflows/$uuid/preview";
        $this->signIn($this->addMember($org, OrganizationRole::Employee));
        $this->postJson($url, [])->assertForbidden();
        $this->signInMember(OrganizationRole::Admin);
        $this->postJson($url, [])->assertNotFound();
    }

    public function test_revision_preview_is_bounded_without_persisting_corrections(): void
    {
        $org = $this->signInMember(OrganizationRole::Admin);
        $uuid = $this->createWorkflow($org);
        $d = WorkflowGraphs::publishable();
        $approval = $d['graph_definition']['nodes'][1]['id'];
        $revision = $d['graph_definition']['nodes'][2]['id'];
        $d['graph_definition']['nodes'][2]['data']['max_returns'] = 10;
        $first = (string) Str::uuid();
        $start = $d['graph_definition']['nodes'][0]['id'];
        $rejected = $d['graph_definition']['nodes'][4]['id'];
        $d['graph_definition']['nodes'][] = WorkflowGraphs::node($first, 'approval', 0, 0, ['label' => 'Earlier approval', 'assignee_roles' => ['manager']]);
        $previous = $first;
        $d['graph_definition']['edges'] = array_values(array_filter($d['graph_definition']['edges'], fn ($e) => $e['source'] !== $start && $e['source'] !== $revision));
        $d['graph_definition']['edges'][] = WorkflowGraphs::edge($start, $first, 'out');
        $d['graph_definition']['edges'][] = WorkflowGraphs::edge($first, $rejected, 'rejected');
        for ($i = 0; $i < 74; $i++) {
            $next = (string) Str::uuid();
            $d['graph_definition']['nodes'][] = WorkflowGraphs::node($next, 'notification', 0, 0, ['label' => 'Synthetic update', 'channels' => ['in_app'], 'recipient_roles' => [], 'include_submitter' => true]);
            $d['graph_definition']['edges'][] = WorkflowGraphs::edge($previous, $next, $previous === $first ? 'approved' : 'out');
            $previous = $next;
        }
        $d['graph_definition']['edges'][] = WorkflowGraphs::edge($previous, $approval, 'out');
        $d['graph_definition']['edges'][] = WorkflowGraphs::edge($revision, $first, 'return');
        $d['graph_definition']['edges'][] = WorkflowGraphs::edge($revision, $rejected, 'exhausted');
        $d['graph_definition']['nodes'][2]['data']['return_to_node_id'] = $first;
        $this->postJson("/api/v1/organizations/{$org->uuid}/workflows/$uuid/preview", [...$d, 'scenario' => ['decisions' => [$first => 'approved', $approval => 'rejected', $revision => 'return']]])->assertOk()->assertJsonPath('data.result', 'incomplete')->assertJsonCount(320, 'data.trace');
        $this->assertDatabaseCount('workflow_instances', 0);
    }

    public function test_email_preview_checks_exact_reply_destination_and_trigger_rules_without_io(): void
    {
        $org = $this->signInMember(OrganizationRole::Admin);
        $uuid = $this->createWorkflow($org);
        $workflow = DB::table('workflows')->first();
        $id = (string) Str::uuid();
        $destination = 'synthetic@example.com';
        $definition = app(WorkflowTemplates::class)->definition('email-application', ['connection_id' => $id, 'document_template_id' => (string) Str::uuid(), 'email_sender' => $destination, 'document_fields' => [['id' => 'employee_id', 'label' => 'Employee ID', 'type' => 'identifier', 'required' => true]]]);
        $approval = collect($definition['graph_definition']['nodes'])->firstWhere('type', 'approval')['id'];
        $authorization = $this->mock(EmailAuthorization::class);
        $authorization->shouldReceive('workflow')->with($org->id, $workflow->id, $id, 'email.receive', '', auth()->id())->twice()->andReturn((object) []);
        $authorization->shouldReceive('workflow')->with($org->id, $workflow->id, $id, 'email.reply', $destination, auth()->id())->once()->andReturn((object) []);
        $this->mock(CredentialVault::class)->shouldNotReceive('decrypt');
        $this->mock(EmailHttp::class)->shouldNotReceive('api', 'token', 'identity');
        Queue::fake();
        Mail::fake();
        $scenario = ['attachment_count' => 1, 'document_values' => ['employee_id' => 'SYNTHETIC-900'], 'decisions' => [$approval => 'approved'], 'event' => ['subject' => 'Application']];
        $url = "/api/v1/organizations/{$org->uuid}/workflows/$uuid/preview";
        $response = $this->postJson($url, [...$definition, 'scenario' => $scenario])->assertOk()->assertJsonPath('data.result', 'completed');
        $this->assertStringNotContainsString($destination, $response->getContent());
        $this->assertStringNotContainsString('SYNTHETIC-900', $response->getContent());
        $definition['graph_definition']['nodes'][0]['data']['rules'] = [['field' => 'subject', 'operator' => 'contains', 'value' => 'never matches']];
        $this->postJson($url, [...$definition, 'scenario' => $scenario])->assertOk()->assertJsonPath('data.result', 'blocked')->assertJsonCount(1, 'data.trace');
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        foreach (['workflow_instances', 'workflow_events', 'email_intakes', 'email_action_outbox', 'integration_audit_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
