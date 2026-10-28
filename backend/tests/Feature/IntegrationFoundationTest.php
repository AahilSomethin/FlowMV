<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Jobs\ResumeDelayedWorkflowInstance;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowVersion;
use App\Services\Integrations\ConnectionManager;
use App\Services\Integrations\CredentialVault;
use App\Services\Integrations\IntegrationRuntime;
use App\Services\Workflows\Nodes\NodeRegistry;
use App\Services\Workflows\WorkflowDefinitionHasher;
use App\Services\Workflows\WorkflowDefinitionNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Workflows\InteractsWithExecution;
use Tests\Support\WorkflowGraphs;
use Tests\TestCase;

class IntegrationFoundationTest extends TestCase
{
    use InteractsWithExecution, RefreshDatabase;

    private function foundation(): array
    {
        config(['integrations.mock_enabled' => true, 'integrations.keys.v1' => base64_encode(random_bytes(32))]);
        $org = $this->signInMember(OrganizationRole::Admin);
        DB::table('integration_policies')->insert(['organization_id' => $org->id, 'personal_connections_allowed' => true]);
        DB::table('integration_approvals')->insert(['organization_id' => $org->id, 'provider_id' => 'local_mock', 'approved' => true]);

        return [$org, User::findOrFail($org->created_by)];
    }

    private function connect(Organization $org, User $user, string $ownership = 'organization'): string
    {
        return app(ConnectionManager::class)->create($user->id, $org->id, ['name' => 'Synthetic credential', 'ownership' => $ownership, 'credential_type' => 'api_key'], ['api_key' => 'synthetic-only-key-0123456789']);
    }

    private function confirm(): void
    {
        $this->postJson('/api/v1/integration-auth/confirm', ['password' => 'password'])->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->withCredentials();
    }

    private function runtimeFixture(): array
    {
        [$org, $user] = $this->foundation();
        $id = $this->connect($org, $user);
        $workflow = Workflow::factory()->create(['organization_id' => $org->id]);
        $node = (string) Str::uuid();
        $config = ['connection_id' => $id, 'operation' => 'mock.notify', 'destination' => 'mock:inbox'];
        app(ConnectionManager::class)->act($user->id, $org->id, $id, 'share', ['workflow_id' => $workflow->uuid, 'operation' => 'mock.notify', 'destination' => 'mock:inbox']);
        $version = WorkflowVersion::factory()->create(['workflow_id' => $workflow->id, 'node_registry_version' => 3, 'graph_definition' => ['nodes' => [['id' => $node, 'type' => 'integration_action', 'data' => $config]], 'edges' => []]]);
        $instance = WorkflowInstance::query()->create(['organization_id' => $org->id, 'workflow_id' => $workflow->id, 'workflow_version_id' => $version->id, 'submitted_by' => $user->id, 'status' => 'running', 'current_node_id' => $node, 'submitted_data' => [], 'started_at' => now()]);

        return [$org, $user, $id, $workflow, $instance, $config, $node];
    }

    public function test_credentials_are_encrypted_context_bound_and_never_in_catalog_or_audit(): void
    {
        [$org, $user] = $this->foundation();
        $id = $this->connect($org, $user);
        $record = DB::table('credential_secrets')->first();
        $this->assertSame('v1', $record->key_version);
        $this->assertStringNotContainsString('synthetic-only-key', $record->ciphertext);
        $this->assertSame(['api_key' => 'synthetic-only-key-0123456789'], app(CredentialVault::class)->decrypt($record, $id));
        $response = $this->getJson("/api/v1/organizations/{$org->uuid}/connections")->assertOk();
        $this->assertStringNotContainsString('synthetic-only-key', $response->getContent());
        $this->assertStringNotContainsString('ciphertext', $response->getContent());
        $this->assertStringNotContainsString('synthetic-only-key', json_encode(DB::table('integration_audit_events')->get()));
        $this->expectException(HttpException::class);
        app(CredentialVault::class)->decrypt($record, (string) Str::uuid());
    }

    public function test_personal_privacy_cross_tenant_and_unauthorized_sharing(): void
    {
        [$org, $admin] = $this->foundation();
        $member = $this->addMember($org, OrganizationRole::Employee);
        $id = $this->connect($org, $member, 'personal');
        $workflow = Workflow::factory()->create(['organization_id' => $org->id]);
        $this->getJson("/api/v1/organizations/{$org->uuid}/connections")->assertOk()->assertJsonCount(0, 'data.items');
        $this->confirm();
        $this->postJson("/api/v1/organizations/{$org->uuid}/connections/{$id}/test")->assertNotFound();
        $this->signIn($member);
        $this->confirm();
        $this->postJson("/api/v1/organizations/{$org->uuid}/connections/{$id}/share", ['workflow_id' => $workflow->uuid, 'operation' => 'mock.notify', 'destination' => 'mock:inbox'])->assertOk();
        $shared = $this->connect($org, $admin);
        $this->postJson("/api/v1/organizations/{$org->uuid}/connections/{$shared}/share", ['workflow_id' => $workflow->uuid, 'operation' => 'mock.notify', 'destination' => 'mock:inbox'])->assertNotFound();
        $other = Organization::factory()->create();
        $this->getJson("/api/v1/organizations/{$other->uuid}/connections")->assertNotFound();
        $this->getJson("/api/v1/organizations/{$org->uuid}/connections")->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_step_up_expires_and_policy_and_csrf_are_enforced(): void
    {
        [$org, $admin] = $this->foundation();
        $payload = ['name' => 'Local key', 'ownership' => 'personal', 'credential_type' => 'api_key', 'credentials' => ['api_key' => 'synthetic-only-key-0123456789']];
        $url = "/api/v1/organizations/{$org->uuid}/connections";
        $this->postJson($url, $payload)->assertStatus(428);
        $this->postJson('/api/v1/integration-auth/confirm', ['password' => 'wrong'])->assertForbidden();
        $this->confirm();
        $this->postJson($url, $payload)->assertCreated();
        $this->travel(301)->seconds();
        $this->postJson($url, $payload)->assertStatus(428);
        $this->confirm();
        DB::table('integration_policies')->where('organization_id', $org->id)->update(['personal_connections_allowed' => false]);
        $this->postJson($url, $payload)->assertForbidden();
        $this->withHeaders(['Origin' => 'https://attacker.invalid', 'Referer' => 'https://attacker.invalid/'])->postJson($url, $payload)->assertStatus(419);
    }

    public function test_oauth_checks_state_pkce_issuer_session_expiry_and_single_use(): void
    {
        [$org] = $this->foundation();
        $this->confirm();
        $base = "/api/v1/organizations/{$org->uuid}/integration-oauth";
        $flow = $this->postJson("$base/start", ['name' => 'Mock OAuth', 'ownership' => 'personal'])->assertOk()->json('data');
        $this->assertSame(DB::table('integration_oauth_requests')->value('session_hash'), hash('sha256', session()->getId()));
        $this->postJson("$base/authorize", ['state' => $flow['state'], 'code_challenge' => str_repeat('x', 43)])->assertForbidden();
        $this->assertSame(DB::table('integration_oauth_requests')->value('session_hash'), hash('sha256', session()->getId()), 'Session changed after challenge refusal');
        $this->postJson("$base/authorize", ['state' => str_repeat('f', 64), 'code_challenge' => $flow['code_challenge']])->assertForbidden();
        $this->assertSame(DB::table('integration_oauth_requests')->value('session_hash'), hash('sha256', session()->getId()), 'Session changed after state refusal');
        $code = $this->postJson("$base/authorize", $flow)->assertOk()->json('data');
        $this->postJson("$base/callback", [...$code, 'issuer' => 'attacker'])->assertForbidden();
        $this->postJson("$base/callback", [...$code, 'code' => 'injected'])->assertForbidden();
        $this->postJson("$base/callback", $code)->assertOk();
        $this->postJson("$base/callback", $code)->assertForbidden();
        $this->assertSame(1, DB::table('connections')->count());
        $this->assertSame(1, DB::table('integration_oauth_requests')->whereNotNull('consumed_at')->count());
    }

    public function test_oauth_cannot_cross_a_session_or_outlive_expiry(): void
    {
        [$org, $admin] = $this->foundation();
        $this->confirm();
        $base = "/api/v1/organizations/{$org->uuid}/integration-oauth";
        $flow = $this->postJson("$base/start", ['name' => 'Mock OAuth', 'ownership' => 'personal'])->assertOk()->json('data');
        $code = $this->postJson("$base/authorize", $flow)->assertOk()->json('data');
        session()->migrate(true);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->confirm();
        $this->postJson("$base/callback", $code)->assertForbidden();
        DB::table('integration_oauth_requests')->update(['session_hash' => hash('sha256', session()->getId()), 'expires_at' => now()->subMinute()]);
        $this->postJson("$base/callback", $code)->assertForbidden();
        $this->assertSame(0, DB::table('connections')->count());
    }

    public function test_key_rotation_preserves_credential_and_old_key_removal_fails_closed(): void
    {
        [$org, $user] = $this->foundation();
        $id = $this->connect($org, $user);
        $old = DB::table('credential_secrets')->first();
        config(['integrations.keys.v2' => base64_encode(random_bytes(32)), 'integrations.active_key' => 'v2']);
        $this->artisan('integrations:rotate-keys')->assertSuccessful();
        $record = DB::table('credential_secrets')->first();
        $this->assertSame('v2', $record->key_version);
        $this->assertNotSame($old->ciphertext, $record->ciphertext);
        config(['integrations.keys.v1' => null]);
        $this->assertSame('synthetic-only-key-0123456789', app(CredentialVault::class)->decrypt($record, $id)['api_key']);
        $this->expectException(HttpException::class);
        app(CredentialVault::class)->decrypt($old, $id);
    }

    public function test_action_retries_deduplicate_and_revocation_is_checked_before_receipts(): void
    {
        [$org, $user, $id, $workflow, $instance, $config, $node] = $this->runtimeFixture();
        $runtime = app(IntegrationRuntime::class);
        $this->assertTrue($runtime->action($instance, $config, $node));
        $this->assertFalse($runtime->action($instance, $config, $node));
        $this->assertSame(1, DB::table('integration_receipts')->count());
        app(ConnectionManager::class)->act($user->id, $org->id, $id, 'revoke', []);
        $this->assertSame(0, DB::table('credential_secrets')->count());
        $this->expectException(HttpException::class);
        $runtime->action($instance, $config, $node);
    }

    public function test_runtime_rechecks_submitter_membership_grant_approval_and_destination(): void
    {
        [$org, $user, $id, $workflow, $instance, $config, $node] = $this->runtimeFixture();
        foreach (['destination', 'grant', 'approval', 'membership', 'disabled'] as $case) {
            DB::beginTransaction();
            if ($case === 'destination') {
                DB::table('authorized_destinations')->delete();
            }
            if ($case === 'grant') {
                DB::table('workflow_connection_grants')->delete();
            }
            if ($case === 'approval') {
                DB::table('integration_approvals')->update(['approved' => false]);
            }
            if ($case === 'membership') {
                DB::table('organization_memberships')->where('user_id', $user->id)->update(['revoked_at' => now()]);
            }
            if ($case === 'disabled') {
                DB::table('connections')->update(['status' => 'disabled']);
            }
            try {
                app(IntegrationRuntime::class)->action($instance, $config, $node);
                $this->fail("Runtime allowed missing {$case}");
            } catch (HttpException $exception) {
                $this->assertContains($exception->getStatusCode(), [403, 404]);
            } finally {
                DB::rollBack();
            }
        }
        $this->assertSame(0, DB::table('integration_receipts')->count());
    }

    public function test_webhook_signature_timestamp_and_event_deduplication(): void
    {
        [$org, $user, $id, $workflow] = $this->runtimeFixture();
        app(ConnectionManager::class)->act($user->id, $org->id, $id, 'share', ['workflow_id' => $workflow->uuid, 'operation' => 'mock.event', 'destination' => 'mock:inbox']);
        $event = (string) Str::uuid();
        $time = now()->timestamp;
        $signature = hash_hmac('sha256', $time.'.'.$event, 'synthetic-only-key-0123456789');
        $url = "/api/v1/integration-webhooks/local-mock/{$id}";
        $this->postJson($url, ['event_id' => $event, 'timestamp' => $time, 'signature' => 'bad'])->assertForbidden();
        $this->postJson($url, ['event_id' => $event, 'timestamp' => $time - 301, 'signature' => hash_hmac('sha256', ($time - 301).'.'.$event, 'synthetic-only-key-0123456789')])->assertForbidden();
        $this->postJson($url, ['event_id' => $event, 'timestamp' => $time, 'signature' => $signature])->assertOk()->assertJsonPath('data.accepted', true);
        $this->postJson($url, ['event_id' => $event, 'timestamp' => $time, 'signature' => $signature])->assertOk()->assertJsonPath('data.accepted', false);
        $this->assertSame(1, DB::table('integration_receipts')->count());
    }

    public function test_v3_rejects_credentials_and_preserves_v1_v2_catalogs(): void
    {
        $registry = app(NodeRegistry::class);
        $this->assertFalse($registry->allows(1, 'integration_action'));
        $this->assertFalse($registry->allows(2, 'integration_action'));
        $this->assertTrue($registry->allows(3, 'integration_action'));
        $this->assertFalse($registry->allows(999, 'integration_action'));
        $graph = ['nodes' => [WorkflowGraphs::node((string) Str::uuid(), 'integration_action', 0, 0, ['label' => 'Action', 'connection_id' => (string) Str::uuid(), 'operation' => 'mock.notify', 'destination' => 'mock:inbox', 'api_key' => 'must-not-persist'])], 'edges' => []];
        $result = app(WorkflowDefinitionNormalizer::class)->normalize($graph, ['fields' => []]);
        $this->assertFalse($result->ok());
        $this->assertStringNotContainsString('must-not-persist', json_encode($result->graph));
    }

    public function test_error_details_do_not_echo_secrets_even_with_debug_enabled(): void
    {
        [$org] = $this->foundation();
        $this->confirm();
        config(['app.debug' => true]);
        $response = $this->postJson("/api/v1/organizations/{$org->uuid}/connections", ['name' => 'Local', 'ownership' => 'personal', 'credential_type' => 'api_key', 'credentials' => ['api_key' => 'secret-marker', 'base_url' => 'http://attacker.invalid']])->assertUnprocessable();
        $this->assertStringNotContainsString('secret-marker', $response->getContent());
        $this->assertStringNotContainsString('trace', $response->getContent());
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_published_v3_executes_and_revoked_connection_fails_closed_without_rewriting_snapshot(): void
    {
        [$org, $user] = $this->foundation();
        $id = $this->connect($org, $user);
        $workflowId = $this->createWorkflow($org);
        app(ConnectionManager::class)->act($user->id, $org->id, $id, 'share', ['workflow_id' => $workflowId, 'operation' => 'mock.notify', 'destination' => 'mock:inbox']);
        $start = (string) Str::uuid();
        $action = (string) Str::uuid();
        $end = (string) Str::uuid();
        $graph = ['nodes' => [
            WorkflowGraphs::node($start, 'start', 0, 0, ['label' => 'Start']),
            WorkflowGraphs::node($action, 'integration_action', 200, 0, ['label' => 'Notify', 'connection_id' => $id, 'operation' => 'mock.notify', 'destination' => 'mock:inbox']),
            WorkflowGraphs::node($end, 'end', 400, 0, ['label' => 'End', 'result' => 'completed']),
        ], 'edges' => [WorkflowGraphs::edge($start, $action, 'out'), WorkflowGraphs::edge($action, $end, 'out')]];
        $base = "/api/v1/organizations/{$org->uuid}/workflows/{$workflowId}";
        $this->putJson("$base/draft", ['revision' => 1, 'graph_definition' => $graph, 'form_definition' => ['fields' => []]])->assertOk();
        $this->postJson("$base/publish", ['revision' => 2])->assertOk();
        $version = WorkflowVersion::query()->firstOrFail();
        $hash = $version->content_hash;
        $this->assertSame(3, $version->node_registry_version);
        $this->startInstance($org, $workflowId)->assertCreated()->assertJsonPath('data.status', 'completed');
        $this->assertSame(1, DB::table('integration_receipts')->count());
        app(ConnectionManager::class)->act($user->id, $org->id, $id, 'revoke', []);
        $this->startInstance($org, $workflowId)->assertCreated()->assertJsonPath('data.status', 'failed');
        $this->assertSame($hash, $version->fresh()->content_hash);
        $this->assertSame(1, DB::table('integration_receipts')->count());
    }

    public function test_bot_rotation_disable_enable_and_cross_tenant_grant_denial(): void
    {
        [$org, $user] = $this->foundation();
        $manager = app(ConnectionManager::class);
        $id = $manager->create($user->id, $org->id, ['name' => 'Bot', 'ownership' => 'organization', 'credential_type' => 'bot_token'], ['bot_token' => 'synthetic-bot-token-only']);
        $manager->act($user->id, $org->id, $id, 'disable', []);
        $this->assertSame('disabled', DB::table('connections')->value('status'));
        $manager->act($user->id, $org->id, $id, 'enable', []);
        $manager->act($user->id, $org->id, $id, 'rename', ['name' => 'New bot']);
        $manager->act($user->id, $org->id, $id, 'rotate', [], ['bot_token' => 'synthetic-replacement-token']);
        $manager->act($user->id, $org->id, $id, 'test', []);
        $this->assertSame('New bot', DB::table('connections')->value('name'));
        $this->assertSame(['bot_token' => 'synthetic-replacement-token'], app(CredentialVault::class)->decrypt(DB::table('credential_secrets')->first(), $id));
        $other = Workflow::factory()->create();
        $this->expectException(HttpException::class);
        $manager->act($user->id, $org->id, $id, 'share', ['workflow_id' => $other->uuid, 'operation' => 'mock.notify', 'destination' => 'mock:inbox']);
    }

    public function test_step_up_rate_limit_and_append_only_audit(): void
    {
        [$org, $user] = $this->foundation();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/integration-auth/confirm', ['password' => 'wrong'])->assertForbidden();
        }
        $this->postJson('/api/v1/integration-auth/confirm', ['password' => 'password'])->assertStatus(429);
        $this->connect($org, $user);
        $this->expectException(QueryException::class);
        DB::table('integration_audit_events')->update(['event' => 'forged']);
    }

    public function test_personal_workflow_grants_never_authorize_another_submitter(): void
    {
        [$org, $admin, $id, $workflow, $instance, $config, $node] = $this->runtimeFixture();
        $owner = $this->addMember($org, OrganizationRole::Employee);
        DB::table('connections')->where('id', $id)->update(['owner_user_id' => $owner->id]);
        DB::table('workflow_connection_grants')->where('connection_id', $id)->update(['authorized_by' => $owner->id]);
        try {
            app(IntegrationRuntime::class)->action($instance, $config, $node);
            $this->fail('Another submitter used a personal credential.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $ownedInstance = WorkflowInstance::query()->create(['organization_id' => $org->id, 'workflow_id' => $workflow->id, 'workflow_version_id' => $instance->workflow_version_id, 'submitted_by' => $owner->id, 'status' => 'running', 'current_node_id' => $node, 'submitted_data' => [], 'started_at' => now()]);
        $this->assertTrue(app(IntegrationRuntime::class)->action($ownedInstance, $config, $node));
    }

    public function test_oauth_reauthorization_rotates_in_place_and_cannot_resurrect_revoked_connections(): void
    {
        [$org, $user] = $this->foundation();
        $this->confirm();
        $base = "/api/v1/organizations/{$org->uuid}/integration-oauth";
        $flow = $this->postJson("$base/start", ['name' => 'OAuth', 'ownership' => 'personal'])->assertOk()->json('data');
        $code = $this->postJson("$base/authorize", $flow)->assertOk()->json('data');
        $id = $this->postJson("$base/callback", $code)->assertOk()->json('data.id');
        $old = DB::table('credential_secrets')->value('ciphertext');
        $flow = $this->postJson("$base/start", ['name' => 'OAuth', 'ownership' => 'personal', 'connection_id' => $id])->assertOk()->json('data');
        $code = $this->postJson("$base/authorize", $flow)->assertOk()->json('data');
        $this->postJson("$base/callback", $code)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, DB::table('connections')->count());
        $this->assertNotSame($old, DB::table('credential_secrets')->value('ciphertext'));
        $flow = $this->postJson("$base/start", ['name' => 'OAuth', 'ownership' => 'personal', 'connection_id' => $id])->assertOk()->json('data');
        $code = $this->postJson("$base/authorize", $flow)->assertOk()->json('data');
        app(ConnectionManager::class)->act($user->id, $org->id, $id, 'revoke', []);
        $this->postJson("$base/callback", $code)->assertNotFound();
        $this->assertSame(0, DB::table('credential_secrets')->count());
    }

    public function test_queued_delay_resume_and_retry_revalidate_revocation(): void
    {
        [$org, $user, $id, $workflow] = $this->runtimeFixture();
        Queue::fake();
        $start = (string) Str::uuid();
        $delay = (string) Str::uuid();
        $action = (string) Str::uuid();
        $end = (string) Str::uuid();
        $graph = ['nodes' => [
            WorkflowGraphs::node($start, 'start', 0, 0, ['label' => 'Start']),
            WorkflowGraphs::node($delay, 'delay', 100, 0, ['label' => 'Wait', 'resume_mode' => 'duration', 'duration_seconds' => 1, 'resume_at' => null]),
            WorkflowGraphs::node($action, 'integration_action', 200, 0, ['label' => 'Notify', 'connection_id' => $id, 'operation' => 'mock.notify', 'destination' => 'mock:inbox']),
            WorkflowGraphs::node($end, 'end', 300, 0, ['label' => 'End', 'result' => 'completed']),
        ], 'edges' => [WorkflowGraphs::edge($start, $delay, 'out'), WorkflowGraphs::edge($delay, $action, 'out'), WorkflowGraphs::edge($action, $end, 'out')]];
        $version = WorkflowVersion::factory()->create(['workflow_id' => $workflow->id, 'version_number' => 2, 'node_registry_version' => 3, 'graph_definition' => $graph, 'content_hash' => app(WorkflowDefinitionHasher::class)->hash($graph, ['fields' => []])]);
        $workflow->forceFill(['status' => 'published', 'current_published_version_id' => $version->id])->save();
        $started = $this->startInstance($org, $workflow->uuid)->assertCreated()->assertJsonPath('data.status', 'awaiting_delay');
        $job = new ResumeDelayedWorkflowInstance(DB::table('workflow_delays')->value('id'));
        $this->assertStringNotContainsString('synthetic-only-key', serialize($job));
        app(ConnectionManager::class)->act($user->id, $org->id, $id, 'revoke', []);
        $this->travel(2)->seconds();
        $job->handle(app(\App\Actions\WorkflowInstances\ResumeDelayedWorkflowInstance::class));
        $job->handle(app(\App\Actions\WorkflowInstances\ResumeDelayedWorkflowInstance::class));
        $instance = WorkflowInstance::query()->where('uuid', $started->json('data.uuid'))->firstOrFail();
        $this->assertSame('failed', $instance->status->value);
        $this->assertSame(0, DB::table('integration_receipts')->count());
    }

    public function test_workflow_connection_selection_does_not_expose_other_draft_grants(): void
    {
        [$org, $admin] = $this->foundation();
        $id = $this->connect($org, $admin);
        $published = Workflow::factory()->create(['organization_id' => $org->id, 'status' => 'published']);
        $draft = Workflow::factory()->create(['organization_id' => $org->id, 'name' => 'Private draft', 'status' => 'draft']);
        foreach ([$published, $draft] as $workflow) {
            app(ConnectionManager::class)->act($admin->id, $org->id, $id, 'share', ['workflow_id' => $workflow->uuid, 'operation' => 'mock.notify', 'destination' => 'mock:inbox']);
        }
        $employee = $this->addMember($org, OrganizationRole::Employee);
        $this->signIn($employee);
        $base = "/api/v1/organizations/{$org->uuid}/connections";
        $this->getJson($base)->assertOk()->assertJsonCount(0, 'data.items');
        $response = $this->getJson("$base?workflow_id={$published->uuid}")->assertOk()->assertJsonCount(1, 'data.items')->assertJsonCount(1, 'data.items.0.workflows');
        $this->assertStringNotContainsString('Private draft', $response->getContent());
        $this->getJson("$base?workflow_id={$draft->uuid}")->assertNotFound();
    }
}
