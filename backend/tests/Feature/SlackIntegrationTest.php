<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Exceptions\SlackApiFailure;
use App\Jobs\ActivateSlackEvent;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\Integrations\CredentialVault;
use App\Services\Integrations\SlackActivation;
use App\Services\Integrations\SlackApi;
use App\Services\Integrations\SlackDelivery;
use App\Services\Integrations\SlackSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Workflows\InteractsWithExecution;
use Tests\Support\WorkflowGraphs;
use Tests\TestCase;

class SlackIntegrationTest extends TestCase
{
    use InteractsWithExecution { setUp as protected spaSetUp; }
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->spaSetUp();
        config(['slack.enabled' => true, 'slack.client_id' => 'synthetic-client', 'slack.client_secret' => 'synthetic-client-secret', 'slack.signing_secret' => 'synthetic-signing-secret-only', 'slack.app_id' => 'A12345678', 'slack.redirect_uri' => 'https://flowmv.example.test/integrations/slack-callback', 'integrations.keys.v1' => base64_encode(random_bytes(32))]);
    }

    private function confirm(): void
    {
        $this->postJson('/api/v1/integration-auth/confirm', ['password' => 'password'])->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->withCredentials();
    }

    private function fixture(): array
    {
        $org = $this->signInMember(OrganizationRole::Admin);
        $this->confirm();
        $base = "/api/v1/organizations/{$org->uuid}/integrations/slack";
        $this->putJson("$base/policy", ['approved' => true])->assertOk();
        $api = app(SlackApi::class);
        $api->http->preventStrayRequests();
        $api->http->fake([
            'https://slack.com/api/oauth.v2.access' => Factory::response(['ok' => true, 'token_type' => 'bot', 'access_token' => 'synthetic-bot-token', 'refresh_token' => 'synthetic-refresh-token', 'expires_in' => 43200, 'scope' => implode(',', config('slack.scopes')), 'bot_user_id' => 'U99999999', 'team' => ['id' => 'T12345678']]),
            'https://slack.com/api/auth.revoke' => Factory::response(['ok' => true, 'revoked' => true]),
            'https://slack.com/api/auth.test' => Factory::response(['ok' => true, 'team_id' => 'T12345678', 'user_id' => 'U99999999']),
            'https://slack.com/api/conversations.info' => Factory::response(['ok' => true, 'channel' => ['id' => 'C12345678', 'name' => 'requests', 'is_member' => true, 'is_archived' => false]]),
            'https://slack.com/api/chat.postMessage' => Factory::response(['ok' => true, 'channel' => 'C12345678', 'ts' => '123.456']),
        ]);
        $url = $this->postJson("$base/oauth/start", ['name' => 'QA Slack', 'ownership' => 'organization'])->assertOk()->json('data.authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $id = $this->postJson("$base/oauth/finish", ['state' => $q['state'], 'code' => 'synthetic-code'])->assertOk()->json('data.id');
        $this->postJson("$base/$id/approve")->assertOk();
        $this->postJson("$base/$id/approve-channel", ['destination' => 'C12345678'])->assertOk();

        return [$org, $id, $base, $q['state']];
    }

    private function incoming(string $event = 'Ev12345678', string $channel = 'C12345678'): TestResponse
    {
        $raw = json_encode(['type' => 'event_callback', 'api_app_id' => 'A12345678', 'team_id' => 'T12345678', 'event_id' => $event, 'event' => ['type' => 'app_mention', 'user' => 'U12345678', 'channel' => $channel, 'text' => 'private synthetic request', 'ts' => '123.45']]);
        $stamp = (string) now()->timestamp;

        return $this->call('POST', '/api/v1/integration-webhooks/slack/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $stamp, 'HTTP_X_SLACK_SIGNATURE' => 'v0='.hash_hmac('sha256', 'v0:'.$stamp.':'.$raw, config('slack.signing_secret'))], $raw);
    }

    public function test_oauth_encrypts_tokens_consumes_state_and_returns_safe_catalog(): void
    {
        [, $id, $base, $state] = $this->fixture();
        $row = DB::table('credential_secrets')->where('connection_id', $id)->first();
        $this->assertStringNotContainsString('synthetic-bot-token', $row->ciphertext);
        $this->assertSame('synthetic-bot-token', app(CredentialVault::class)->decrypt($row, $id)['access_token']);
        $catalog = $this->getJson($base)->assertOk()->getContent();
        $this->assertStringNotContainsString('synthetic-bot-token', $catalog);
        $this->assertStringNotContainsString('ciphertext', $catalog);
        $this->postJson("$base/oauth/finish", ['state' => $state, 'code' => 'synthetic-code'])->assertForbidden();
    }

    public function test_signed_linked_event_creates_pinned_request_manager_decides_and_slack_accepts_once(): void
    {
        Queue::fake();
        [$org, $id, $base] = $this->fixture();
        $employee = $this->addMember($org, OrganizationRole::Employee);
        $manager = $this->addMember($org, OrganizationRole::Manager);
        DB::table('slack_identity_links')->insert(['connection_id' => $id, 'user_id' => $employee->id, 'slack_user_id' => 'U12345678', 'confirmed' => true]);
        $workflow = $this->createWorkflow($org, 'Slack approval');
        foreach (['slack.message', 'slack.notification'] as $operation) {
            $this->postJson("$base/$id/grant", ['workflow_id' => $workflow, 'operation' => $operation, 'destination' => 'C12345678'])->assertOk();
        }
        [$trigger, $approval, $send, $done, $rejected] = array_map(fn () => (string) Str::uuid(), range(1, 5));
        $graph = ['nodes' => [WorkflowGraphs::node($trigger, 'slack_trigger', 0, 0, ['label' => 'Slack Trigger', 'connection_id' => $id, 'destination' => 'C12345678', 'input_mapping' => []]), WorkflowGraphs::node($approval, 'approval', 200, 0, ['label' => 'Approval', 'assignee_roles' => ['manager']]), WorkflowGraphs::node($send, 'slack_notification', 400, 0, ['label' => 'Outcome', 'connection_id' => $id, 'destination' => 'C12345678', 'text' => 'Request {{request.uuid}} {{approval.decision}}', 'allowed_field_ids' => []]), WorkflowGraphs::node($done, 'end', 600, 0, ['label' => 'Done', 'result' => 'completed']), WorkflowGraphs::node($rejected, 'end', 600, 200, ['label' => 'Rejected', 'result' => 'rejected'])], 'edges' => [WorkflowGraphs::edge($trigger, $approval, 'out'), WorkflowGraphs::edge($approval, $send, 'approved'), WorkflowGraphs::edge($approval, $rejected, 'rejected'), WorkflowGraphs::edge($send, $done, 'out')]];
        $this->putJson("/api/v1/organizations/{$org->uuid}/workflows/$workflow/draft", ['revision' => 1, 'graph_definition' => $graph, 'form_definition' => ['fields' => []]])->assertOk();
        $this->postJson("/api/v1/organizations/{$org->uuid}/workflows/$workflow/publish", ['revision' => 2])->assertOk();
        $this->incoming()->assertOk();
        $a = DB::table('slack_trigger_activations')->first();
        $this->assertNotNull($a);
        app(SlackActivation::class)->process($a->id);
        $a = DB::table('slack_trigger_activations')->first();
        $this->assertSame('done', $a->status);
        $instance = WorkflowInstance::findOrFail($a->workflow_instance_id);
        $this->incoming()->assertOk();
        $this->assertDatabaseCount('slack_trigger_activations', 1);
        $this->signIn($manager);
        $task = $instance->tasks()->firstOrFail();
        $this->decide($org, $task->uuid, 'approved')->assertOk();
        $outbox = DB::table('slack_action_outbox')->first();
        $this->assertNotNull($outbox);
        app(SlackDelivery::class)->process($outbox->id);
        app(SlackDelivery::class)->process($outbox->id);
        $this->assertDatabaseHas('slack_action_outbox', ['id' => $outbox->id, 'status' => 'accepted', 'attempt_count' => 1]);
        $this->assertSame('completed', $instance->fresh()->status->value);
        Queue::assertPushed(ActivateSlackEvent::class);
    }

    public function test_forged_and_stale_webhooks_are_rejected(): void
    {
        $this->freezeTime();
        $raw = '{"private":"content"}';
        $stamp = (string) now()->timestamp;
        $valid = 'v0='.hash_hmac('sha256', 'v0:'.$stamp.':'.$raw, config('slack.signing_secret'));
        $signature = app(SlackSignature::class);
        $this->assertTrue($signature->verify($raw, $stamp, $valid));
        $this->assertFalse($signature->verify($raw.'x', $stamp, $valid));
        $this->travel(301)->seconds();
        $this->assertFalse($signature->verify($raw, $stamp, $valid));
        $this->postJson('/api/v1/integration-webhooks/slack/events', ['text' => 'private'])->assertForbidden();
    }

    public function test_unauthorized_channels_unlinked_members_and_revoked_connections_cannot_activate(): void
    {
        [$org, $id, $base] = $this->fixture();
        $this->incoming()->assertForbidden();
        $employee = $this->addMember($org, OrganizationRole::Employee);
        DB::table('slack_identity_links')->insert(['connection_id' => $id, 'user_id' => $employee->id, 'slack_user_id' => 'U12345678', 'confirmed' => true]);
        $this->incoming(channel: 'C87654321')->assertForbidden();
        $this->postJson("$base/$id/revoke")->assertOk();
        $this->assertDatabaseHas('integration_audit_events', ['connection_id' => $id, 'event' => 'slack.provider.revoked']);
        $this->incoming()->assertForbidden();
        $this->assertDatabaseMissing('credential_secrets', ['connection_id' => $id]);
        $this->assertDatabaseCount('slack_inbound_events', 0);
    }

    public function test_connection_management_requires_recent_step_up_and_tenant_membership(): void
    {
        [$org, $id, $base] = $this->fixture();
        $member = $this->addMember($org, OrganizationRole::Employee);
        $this->signIn($member);
        $this->confirm();
        $this->postJson("$base/$id/disable")->assertNotFound();
        $this->postJson("$base/oauth/start", ['name' => 'Denied', 'ownership' => 'organization'])->assertForbidden();
        $other = $this->signInMember(OrganizationRole::Admin);
        $this->confirm();
        $this->postJson("$base/$id/disable")->assertNotFound();
        $this->travel(6)->minutes();
        $this->postJson("/api/v1/organizations/{$other->uuid}/integrations/slack/oauth/start", ['name' => 'Expired', 'ownership' => 'organization'])->assertStatus(428);
    }

    public function test_oauth_state_is_session_bound_and_expired_attempt_cannot_exchange(): void
    {
        [$org, , $base] = $this->fixture();
        $url = $this->postJson("$base/oauth/start", ['name' => 'Attempt', 'ownership' => 'organization'])->assertOk()->json('data.authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        DB::table('slack_oauth_attempts')->where('state_hash', hash('sha256', $q['state']))->update(['session_hash' => str_repeat('a', 64)]);
        $this->postJson("$base/oauth/finish", ['state' => $q['state'], 'code' => 'synthetic'])->assertForbidden();
        DB::table('slack_oauth_attempts')->where('state_hash', hash('sha256', $q['state']))->update(['session_hash' => hash('sha256', session()->getId()), 'expires_at' => now()->subSecond()]);
        $this->postJson("$base/oauth/finish", ['state' => $q['state'], 'code' => 'synthetic'])->assertForbidden();
        $this->assertDatabaseCount('slack_installations', 1);
    }

    public function test_pkce_is_used_only_for_configured_slack_public_client(): void
    {
        [, , $base] = $this->fixture();
        config(['slack.pkce' => true]);
        $url = $this->postJson("$base/oauth/start", ['name' => 'PKCE', 'ownership' => 'organization'])->assertOk()->json('data.authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertArrayNotHasKey('client_secret', $q);
        $this->assertArrayNotHasKey('code_verifier', $q);
        $this->assertSame(43, strlen($q['code_challenge']));
    }

    public function test_provider_failures_are_safe_and_ambiguous_send_is_not_retryable(): void
    {
        $api = app(SlackApi::class);
        $api->http->preventStrayRequests();
        $api->http->fake(['https://slack.com/api/chat.postMessage' => Factory::response(['ok' => false, 'error' => 'secret-private-provider-detail'], 503)]);
        try {
            $api->send('synthetic-token', 'C12345678', 'synthetic private body', (string) Str::uuid());
            $this->fail('Expected safe provider failure.');
        } catch (SlackApiFailure $failure) {
            $this->assertSame('provider_unavailable', $failure->reason);
            $this->assertTrue($failure->ambiguous);
            $this->assertStringNotContainsString('private', $failure->getMessage());
            $this->assertStringNotContainsString('synthetic-token', $failure->getMessage());
        }
        $api->http->assertSentCount(1);
    }

    public function test_simulator_fails_closed_outside_exact_local_qa_environment(): void
    {
        [, , $base] = $this->fixture();
        $this->postJson("$base/simulation/connect", ['name' => 'Denied simulator', 'ownership' => 'organization'])->assertStatus(503);
        $this->assertDatabaseCount('slack_installations', 1);
    }

    public function test_personal_installation_requires_policy_admin_approval_and_stays_private_to_other_members(): void
    {
        [$org, $id, $base] = $this->fixture();
        $admin = User::findOrFail($org->created_by);
        $this->postJson("$base/$id/revoke")->assertOk();
        DB::table('integration_policies')->insert(['organization_id' => $org->id, 'personal_connections_allowed' => true]);
        $owner = $this->addMember($org, OrganizationRole::Employee);
        $other = $this->addMember($org, OrganizationRole::Employee);
        $this->signIn($owner);
        $this->confirm();
        $url = $this->postJson("$base/oauth/start", ['name' => 'Private workspace', 'ownership' => 'personal'])->assertOk()->json('data.authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $personal = $this->postJson("$base/oauth/finish", ['state' => $q['state'], 'code' => 'synthetic-personal'])->assertOk()->json('data.id');
        $this->postJson("$base/$personal/approve")->assertForbidden();
        $this->signIn($admin);
        $this->confirm();
        $this->postJson("$base/$personal/approve")->assertOk();
        $this->postJson("$base/$personal/test")->assertNotFound();
        $this->signIn($other);
        $items = $this->getJson($base)->assertOk()->json('data.items');
        $this->assertNotContains($personal, array_column($items, 'id'));
    }
}
