<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Jobs\ProcessAiRun;
use App\Models\Organization;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\AI\AiAccess;
use App\Services\AI\AiClient;
use App\Services\AI\AiConnections;
use App\Services\AI\AiProcessing;
use App\Services\AI\AiSource;
use App\Services\Integrations\CredentialVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Workflows\InteractsWithExecution;
use Tests\Support\ExecutionGraphs;
use Tests\TestCase;

class AiSecurityTest extends TestCase
{
    use InteractsWithExecution;
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['ai.enabled' => true, 'ai.simulated' => false, 'ai.service_key' => str_repeat('a', 64), 'integrations.keys.v1' => base64_encode(random_bytes(32))]);
        $org = $this->signInMember(OrganizationRole::Admin);
        $user = auth()->id();
        DB::table('integration_policies')->updateOrInsert(['organization_id' => $org->id], ['personal_connections_allowed' => true]);
        $service = app(AiConnections::class);
        $policy = ['enabled' => true, 'providers' => ['openai', 'gemini'], 'data_categories' => ['workplace_request', 'document_text', 'email_body', 'slack_message'], 'max_input_bytes' => 20000, 'max_output_tokens' => 1024, 'daily_organization_units' => 200000, 'daily_user_units' => 100000, 'daily_workflow_units' => 100000, 'max_corrections' => 2, 'retention_days' => 7];
        $service->policy($user, $org->id, $policy);
        $uuid = $this->publishWorkflow($org, ExecutionGraphs::approval(fields: [ExecutionGraphs::field('details', 'text')]));
        $workflow = Workflow::where('uuid', $uuid)->firstOrFail();
        $this->startInstance($org, $uuid, ['details' => 'Synthetic request. Contact employee@example.test.'])->assertCreated();
        $instance = WorkflowInstance::where('workflow_id', $workflow->id)->firstOrFail();
        $key = 'sk-FLOWMV_QA_AI_ONLY_'.str_repeat('x', 30);
        $settings = ['name' => 'Synthetic AI', 'provider' => 'openai', 'ownership' => 'organization', 'model' => 'gpt-5.4-mini', 'operations' => ['summarize'], 'max_input_bytes' => 20000, 'max_output_tokens' => 1024, 'daily_units' => 100000];
        $id = $service->create($user, $org->id, $settings, ['api_key' => $key]);
        $service->action($user, $org->id, $id, 'approve', []);
        $service->action($user, $org->id, $id, 'grant', ['workflow_id' => $uuid, 'operation' => 'summarize']);
        Queue::fake([ProcessAiRun::class]);

        return compact('org', 'user', 'workflow', 'instance', 'id', 'key', 'policy', 'settings');
    }

    private function queue(array $f): string
    {
        return app(AiProcessing::class)->request($f['user'], $f['org']->id, $f['workflow']->id, $f['id'], 'summarize', 'request', $f['instance']->uuid, ['input_fields' => ['details'], 'fields' => [], 'categories' => [], 'routes' => [], 'data_categories' => ['workplace_request']]);
    }

    public function test_credentials_are_encrypted_and_sensitive_changes_require_step_up(): void
    {
        $f = $this->fixture();
        $this->assertStringNotContainsString($f['key'], DB::table('credential_secrets')->where('connection_id', $f['id'])->value('ciphertext'));
        $response = $this->getJson('/api/v1/organizations/'.$f['org']->uuid.'/integrations/ai')->assertOk();
        $this->assertStringNotContainsString($f['key'], $response->getContent());
        $this->postJson('/api/v1/organizations/'.$f['org']->uuid.'/integrations/ai/connections/'.$f['id'].'/revoke')->assertStatus(428);
        $other = Organization::factory()->create();
        try {
            app(AiConnections::class)->action($f['user'], $other->id, $f['id'], 'revoke', []);
            $this->fail('Cross-tenant access accepted.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->assertDatabaseHas('connections', ['id' => $f['id'], 'status' => 'enabled']);
    }

    public function test_revocation_cancels_queued_work_and_job_contains_only_safe_reference(): void
    {
        $f = $this->fixture();
        $id = $this->queue($f);
        Queue::assertPushed(ProcessAiRun::class, fn ($job) => $job->runId === $id && ! str_contains(serialize($job), $f['key']) && ! str_contains(serialize($job), 'employee@example.test'));
        app(AiConnections::class)->action($f['user'], $f['org']->id, $f['id'], 'revoke', []);
        app(AiProcessing::class)->process($id);
        $this->assertDatabaseHas('ai_processing_runs', ['id' => $id, 'status' => 'cancelled']);
        $this->assertDatabaseMissing('credential_secrets', ['connection_id' => $f['id']]);
    }

    public function test_deduplication_reserves_once_and_ungranted_operation_is_denied(): void
    {
        $f = $this->fixture();
        $this->assertSame($this->queue($f), $this->queue($f));
        $this->assertDatabaseCount('ai_usage_reservations', 1);
        $this->expectException(HttpException::class);
        app(AiAccess::class)->workflow($f['user'], $f['org']->id, $f['workflow']->id, $f['id'], 'draft/reply');
    }

    public function test_processing_minimizes_input_encrypts_advice_and_never_decides_request(): void
    {
        $f = $this->fixture();
        $client = new AiClient;
        $client->http->preventStrayRequests();
        $client->http->fake(function ($request) {
            if (str_ends_with($request->url(), '/readyz')) {
                return Factory::response(['contract' => 'flowmv.ai.v1', 'audience' => str_repeat('b', 64)]);
            }
            $body = json_decode($request->body(), true);
            $this->assertStringNotContainsString('employee@example.test', $body['text']);
            $this->assertStringContainsString('[email redacted]', $body['text']);

            return Factory::response(['contract' => 'flowmv.ai.v1', 'request_id' => $body['request_id'], 'provider' => 'openai', 'model' => 'gpt-5.4-mini', 'prompt_version' => 'workplace-v1', 'source' => 'provider_response', 'input_tokens' => 10, 'output_tokens' => 20, 'advice' => ['summary' => 'ADVISORY_SENTINEL', 'classification' => 'unknown', 'fields' => [], 'missing' => [], 'inconsistent' => [], 'route' => '', 'draft_reply' => '', 'recommendation' => 'review', 'uncertain' => false]]);
        });
        app()->instance(AiClient::class, $client);
        $id = $this->queue($f);
        app(AiProcessing::class)->process($id);
        $run = DB::table('ai_processing_runs')->where('id', $id)->first();
        $this->assertSame('completed', $run->status);
        $this->assertStringNotContainsString('ADVISORY_SENTINEL', $run->ciphertext);
        $result = app(AiProcessing::class)->result($f['user'], $f['org']->id, $id);
        $this->assertSame('ADVISORY_SENTINEL', $result['advice']['summary']);
        $base = '/api/v1/organizations/'.$f['org']->uuid.'/integrations/ai';
        $this->getJson($base.'/runs/'.$id)->assertOk()->assertJsonPath('data.advice.summary', 'ADVISORY_SENTINEL');
        $this->getJson($base.'/requests/'.$f['instance']->uuid)->assertOk()->assertJsonPath('data.items.0.id', $id);
        $this->assertSame($f['instance']->status, $f['instance']->fresh()->status);
        config(['integrations.keys.v2' => base64_encode(random_bytes(32)), 'integrations.active_key' => 'v2']);
        $this->artisan('integrations:rotate-keys')->assertSuccessful();
        $this->assertDatabaseHas('ai_processing_runs', ['id' => $id, 'key_version' => 'v2']);
        $this->assertSame('ADVISORY_SENTINEL', app(AiProcessing::class)->result($f['user'], $f['org']->id, $id)['advice']['summary']);
        DB::table('ai_processing_runs')->where('id', $id)->update(['expires_at' => now()->subSecond()]);
        $this->artisan('integrations:recover-ai')->assertSuccessful();
        $this->assertDatabaseHas('ai_processing_runs', ['id' => $id, 'status' => 'expired', 'ciphertext' => null, 'key_version' => null]);
    }

    public function test_disabled_policy_does_not_prevent_revocation(): void
    {
        $f = $this->fixture();
        app(AiConnections::class)->policy($f['user'], $f['org']->id, [...$f['policy'], 'enabled' => false]);
        app(AiConnections::class)->action($f['user'], $f['org']->id, $f['id'], 'revoke', []);
        $this->assertDatabaseHas('connections', ['id' => $f['id'], 'status' => 'revoked']);
    }

    public function test_employee_cannot_change_ai_policy(): void
    {
        $f = $this->fixture();
        $employee = $this->addMember($f['org'], OrganizationRole::Employee);
        $this->expectException(HttpException::class);
        app(AiConnections::class)->policy($employee->id, $f['org']->id, $f['policy']);
    }

    public function test_rotating_a_key_invalidates_approval_and_cancels_prior_work(): void
    {
        $f = $this->fixture();
        $id = $this->queue($f);
        $next = 'sk-FLOWMV_QA_ROTATED_'.str_repeat('y', 30);
        app(AiConnections::class)->action($f['user'], $f['org']->id, $f['id'], 'rotate', [], ['api_key' => $next]);
        $this->assertDatabaseHas('ai_connections', ['connection_id' => $f['id'], 'generation' => 2, 'approved_by' => null]);
        $this->assertDatabaseHas('ai_processing_runs', ['id' => $id, 'status' => 'cancelled']);
        $secret = DB::table('credential_secrets')->where('connection_id', $f['id'])->first();
        $this->assertStringNotContainsString($next, $secret->ciphertext);
        $this->assertSame($next, app(CredentialVault::class)->decrypt($secret, $f['id'])['api_key']);
        $this->expectException(HttpException::class);
        app(AiAccess::class)->workflow($f['user'], $f['org']->id, $f['workflow']->id, $f['id'], 'summarize');
    }

    public function test_website_metadata_test_and_disable_bind_the_scoped_connection(): void
    {
        $f = $this->fixture();
        $client = new AiClient;
        $client->http->preventStrayRequests();
        $client->http->fake(function ($request) {
            if (str_ends_with($request->url(), '/readyz')) {
                return Factory::response(['contract' => 'flowmv.ai.v1', 'audience' => str_repeat('b', 64)]);
            }
            $body = json_decode($request->body(), true);
            $this->assertSame('Synthetic connection metadata test.', $body['text']);
            $this->assertSame('connections/test', $body['operation']);

            return Factory::response(['contract' => 'flowmv.ai.v1', 'provider' => 'openai', 'model' => 'gpt-5.4-mini', 'request_id' => $body['request_id'], 'source' => 'provider_response']);
        });
        app()->instance(AiClient::class, $client);
        $this->withSession(['integration_step_up.user' => $f['user'], 'integration_step_up.at' => now()->timestamp]);
        $base = '/api/v1/organizations/'.$f['org']->uuid.'/integrations/ai/connections/'.$f['id'];
        $this->postJson($base.'/test', ['data' => []])->assertOk();
        $this->postJson($base.'/disable', ['data' => []])->assertOk();
        $this->assertDatabaseHas('connections', ['id' => $f['id'], 'status' => 'disabled']);
        $client->http->assertSentCount(2);
    }

    public function test_quarantined_document_cannot_be_read_by_ai_even_by_its_owner(): void
    {
        $f = $this->fixture();
        $id = (string) Str::uuid();
        DB::table('private_documents')->insert(['id' => $id, 'organization_id' => $f['org']->id, 'owner_user_id' => $f['user'], 'workflow_instance_id' => $f['instance']->id, 'name' => 'synthetic.docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'size' => 10, 'content_hash' => str_repeat('a', 64), 'storage_key' => 'synthetic-never-opened', 'key_version' => 'v1', 'status' => 'quarantined', 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(HttpException::class);
        app(AiSource::class)->read($f['user'], $f['org']->id, $f['workflow']->id, 'document', $id);
    }

    public function test_untrusted_provider_schema_fails_closed_without_retaining_raw_output(): void
    {
        $f = $this->fixture();
        $client = new AiClient;
        $client->http->fake(['*/readyz' => Factory::response(['contract' => 'flowmv.ai.v1', 'audience' => str_repeat('b', 64)]), '*' => Factory::response(['private_provider_detail' => 'PRIVATE_BODY_SENTINEL', 'advice' => ['approve' => true]])]);
        app()->instance(AiClient::class, $client);
        $id = $this->queue($f);
        app(AiProcessing::class)->process($id);
        $this->assertDatabaseHas('ai_processing_runs', ['id' => $id, 'status' => 'failed', 'error_code' => 'manual_review_required', 'ciphertext' => null]);
        $this->assertStringNotContainsString('PRIVATE_BODY_SENTINEL', json_encode(DB::table('integration_audit_events')->get()));
        $this->assertSame($f['instance']->status, $f['instance']->fresh()->status);
    }

    public function test_personal_approval_is_opt_in_and_never_allows_admin_management(): void
    {
        $f = $this->fixture();
        $employee = $this->addMember($f['org'], OrganizationRole::Employee);
        $service = app(AiConnections::class);
        $id = $service->create($employee->id, $f['org']->id, [...$f['settings'], 'ownership' => 'personal'], ['api_key' => $f['key']]);
        $url = '/api/v1/organizations/'.$f['org']->uuid.'/integrations/ai';
        $this->assertNotContains($id, array_column($this->getJson($url)->assertOk()->json('data.items'), 'id'));
        try {
            $service->action($f['user'], $f['org']->id, $id, 'approve', []);
            $this->fail('Unrequested private connection exposed.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $service->action($employee->id, $f['org']->id, $id, 'request-approval', []);
        $item = collect($this->getJson($url)->assertOk()->json('data.items'))->firstWhere('id', $id);
        $this->assertTrue($item['can_approve']);
        $this->assertFalse($item['can_manage']);
        $service->action($f['user'], $f['org']->id, $id, 'approve', []);
        $service->action($employee->id, $f['org']->id, $id, 'grant', ['workflow_id' => $f['workflow']->uuid, 'operation' => 'summarize']);
        app(AiAccess::class)->workflow($employee->id, $f['org']->id, $f['workflow']->id, $id, 'summarize');
        $this->expectException(HttpException::class);
        app(AiAccess::class)->workflow($f['user'], $f['org']->id, $f['workflow']->id, $id, 'summarize');
    }

    public function test_budget_exhaustion_rolls_back_run_and_reservation(): void
    {
        $f = $this->fixture();
        DB::table('ai_policies')->where('organization_id', $f['org']->id)->update(['daily_user_units' => 1000]);
        try {
            $this->queue($f);
            $this->fail('Budget exceeded.');
        } catch (HttpException $error) {
            $this->assertSame(429, $error->getStatusCode());
        }
        $this->assertDatabaseCount('ai_processing_runs', 0);
        $this->assertDatabaseCount('ai_usage_reservations', 0);
        Queue::assertNothingPushed();
    }

    public function test_stale_member_cannot_execute_previously_queued_analysis(): void
    {
        $f = $this->fixture();
        $id = $this->queue($f);
        DB::table('organization_memberships')->where('organization_id', $f['org']->id)->where('user_id', $f['user'])->update(['revoked_at' => now()]);
        $client = new AiClient;
        $client->http->preventStrayRequests();
        app()->instance(AiClient::class, $client);
        app(AiProcessing::class)->process($id);
        $client->http->assertNothingSent();
        $this->assertDatabaseHas('ai_processing_runs', ['id' => $id, 'status' => 'failed', 'error_code' => 'authorization_changed']);
    }

    public function test_revocation_during_provider_response_discards_advice(): void
    {
        $f = $this->fixture();
        $client = new AiClient;
        $client->http->fake(function ($request) use ($f) {
            if (str_ends_with($request->url(), '/readyz')) {
                return Factory::response(['contract' => 'flowmv.ai.v1', 'audience' => str_repeat('b', 64)]);
            }
            app(AiConnections::class)->action($f['user'], $f['org']->id, $f['id'], 'revoke', []);

            return Factory::response(['advice' => 'UNTRUSTED_OUTPUT']);
        });
        app()->instance(AiClient::class, $client);
        $id = $this->queue($f);
        app(AiProcessing::class)->process($id);
        $this->assertDatabaseHas('ai_processing_runs', ['id' => $id, 'status' => 'cancelled', 'ciphertext' => null]);
    }
}
