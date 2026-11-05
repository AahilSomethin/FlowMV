<?php

namespace Tests\Feature\Workflows;

use App\Actions\WorkflowInstances\DecideWorkflowTask;
use App\Actions\WorkflowInstances\StartWorkflowInstance;
use App\Enums\OrganizationRole;
use App\Exceptions\WorkflowConflictException;
use App\Jobs\ProcessNotificationOutbox;
use App\Models\NotificationOutbox;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Models\WorkflowVersion;
use App\Services\AI\AiClient;
use App\Services\AI\AiConnections;
use App\Services\AI\AiProcessing;
use App\Services\Integrations\ConnectionManager;
use App\Services\Integrations\CredentialVault;
use App\Services\Integrations\EmailHttp;
use App\Services\Integrations\EmailIngress;
use App\Services\Integrations\EmailTokens;
use App\Services\Integrations\IntegrationRuntime;
use App\Services\Integrations\SlackApi;
use App\Services\Integrations\SlackIngress;
use App\Services\Integrations\SlackTokens;
use App\Services\Integrations\SmsApi;
use App\Services\Integrations\SmsAuthorization;
use App\Services\Integrations\SmsDelivery;
use App\Services\Integrations\SmsWorkflow;
use App\Services\Integrations\TelegramActivation;
use App\Services\Integrations\TelegramApi;
use App\Services\Integrations\TelegramDecisions;
use App\Services\Integrations\TelegramDelivery;
use App\Services\Integrations\TelegramIngress;
use App\Services\Integrations\TelegramWorkflow;
use App\Services\Workflows\Execution\IdempotentAction;
use App\Services\Workflows\Execution\PublishedGraph;
use App\Services\Workflows\Execution\WorkflowEventWriter;
use App\Support\OrganizationAdminGuard;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\AutomationGraphs;
use Tests\Support\ExecutionGraphs;
use Tests\Support\WorkflowGraphs;
use Tests\TestCase;

/**
 * Proves PostgreSQL row locks and the single-open-task index with two sessions.
 * The default suite uses SQLite and skips this test. Run it only with
 * phpunit.pgsql.xml, which forces a local disposable database.
 */
class WorkflowConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_ai_requests_reserve_once_and_workers_claim_once(): void
    {
        $options = ['ai.enabled' => true, 'ai.simulated' => false, 'ai.service_key' => str_repeat('a', 64), 'ai.service_url' => 'http://127.0.0.1:8020', 'integrations.keys.v1' => base64_encode(random_bytes(32)), 'app.key' => 'base64:'.base64_encode(random_bytes(32))];
        config($options);
        Queue::fake();
        $user = User::factory()->create();
        $org = Organization::factory()->create(['created_by' => $user->id]);
        OrganizationMembership::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => OrganizationRole::Admin]);
        $service = app(AiConnections::class);
        $service->policy($user->id, $org->id, ['enabled' => true, 'providers' => ['openai'], 'data_categories' => ['workplace_request'], 'max_input_bytes' => 20000, 'max_output_tokens' => 1024, 'daily_organization_units' => 200000, 'daily_user_units' => 100000, 'daily_workflow_units' => 100000, 'max_corrections' => 2, 'retention_days' => 7]);
        $id = $service->create($user->id, $org->id, ['name' => 'Synthetic race', 'provider' => 'openai', 'ownership' => 'organization', 'model' => 'gpt-5.4-mini', 'operations' => ['summarize'], 'max_input_bytes' => 20000, 'max_output_tokens' => 1024, 'daily_units' => 100000], ['api_key' => 'sk-FLOWMV_QA_AI_ONLY_'.str_repeat('x', 30)]);
        $service->action($user->id, $org->id, $id, 'approve', []);
        $workflow = Workflow::factory()->create(['organization_id' => $org->id]);
        $service->action($user->id, $org->id, $id, 'grant', ['workflow_id' => $workflow->uuid, 'operation' => 'summarize']);
        $version = WorkflowVersion::factory()->create(['workflow_id' => $workflow->id, 'form_definition' => ['fields' => [ExecutionGraphs::field('details', 'text')]]]);
        $instance = WorkflowInstance::create(['organization_id' => $org->id, 'workflow_id' => $workflow->id, 'workflow_version_id' => $version->id, 'submitted_by' => $user->id, 'status' => 'running', 'current_node_id' => (string) Str::uuid(), 'submitted_data' => ['details' => 'Synthetic race request.'], 'started_at' => now()]);
        $database = config('database.connections.pgsql');
        $orgId = $org->id;
        $userId = $user->id;
        $workflowId = $workflow->id;
        $source = $instance->uuid;
        $at = microtime(true) + 1;
        $request = static function () use ($options, $database, $orgId, $userId, $workflowId, $source, $id, $at): string {
            config([...$options, 'database.default' => 'pgsql', 'database.connections.pgsql' => $database]);
            DB::purge('pgsql');
            Queue::fake();
            while (microtime(true) < $at) {
                usleep(1000);
            }

            return app(AiProcessing::class)->request($userId, $orgId, $workflowId, $id, 'summarize', 'request', $source, ['input_fields' => ['details'], 'fields' => [], 'categories' => [], 'routes' => [], 'data_categories' => ['workplace_request']]);
        };
        $ids = Concurrency::driver('process')->run([$request, $request]);
        $this->assertSame($ids[0], $ids[1]);
        $this->assertDatabaseCount('ai_usage_reservations', 1);
        $this->assertDatabaseCount('ai_processing_runs', 1);
        $run = $ids[0];
        $at = microtime(true) + 1;
        $process = static function () use ($options, $database, $orgId, $userId, $id, $run, $at): bool {
            config([...$options, 'database.default' => 'pgsql', 'database.connections.pgsql' => $database]);
            DB::purge('pgsql');
            $client = new AiClient;
            $client->http->preventStrayRequests();
            $client->http->fake(function ($request) use ($orgId, $userId, $id) {
                if (str_ends_with($request->url(), '/readyz')) {
                    return Factory::response(['contract' => 'flowmv.ai.v1', 'audience' => str_repeat('b', 64)]);
                }
                app(ConnectionManager::class)->audit($orgId, $id, $userId, 'ai.qa.transport.called');
                usleep(100000);
                $body = json_decode($request->body(), true);

                return Factory::response(['contract' => 'flowmv.ai.v1', 'request_id' => $body['request_id'], 'provider' => 'openai', 'model' => 'gpt-5.4-mini', 'prompt_version' => 'workplace-v1', 'source' => 'provider_response', 'input_tokens' => 10, 'output_tokens' => 20, 'advice' => ['summary' => 'Synthetic advisory.', 'classification' => 'unknown', 'fields' => [], 'missing' => [], 'inconsistent' => [], 'route' => '', 'draft_reply' => '', 'recommendation' => 'review', 'uncertain' => false]]);
            });
            app()->instance(AiClient::class, $client);
            while (microtime(true) < $at) {
                usleep(1000);
            }
            app(AiProcessing::class)->process($run);

            return true;
        };
        Concurrency::driver('process')->run([$process, $process]);
        $this->assertDatabaseHas('ai_processing_runs', ['id' => $run, 'status' => 'completed', 'attempt_count' => 1]);
        $this->assertSame(1, DB::table('integration_audit_events')->where('connection_id', $id)->where('event', 'ai.qa.transport.called')->count());
    }

    public function test_concurrent_sms_workers_share_one_daily_budget_and_do_not_repeat_a_send(): void
    {
        $key = base64_encode(random_bytes(32));
        config(['sms.enabled' => true, 'integrations.keys.v1' => $key, 'sms.webhook_origin' => 'https://flowmv.example.test']);
        Queue::fake();
        $admin = User::factory()->create();
        $org = Organization::factory()->create(['created_by' => $admin->id]);
        OrganizationMembership::factory()->create(['organization_id' => $org->id, 'user_id' => $admin->id, 'role' => OrganizationRole::Admin]);
        $vault = app(CredentialVault::class);
        $sid = 'AC'.str_repeat('0', 32);
        $id = (string) Str::uuid();
        DB::table('integration_approvals')->insert(['organization_id' => $org->id, 'provider_id' => 'twilio_sms', 'approved' => true]);
        DB::table('sms_policies')->insert(['organization_id' => $org->id, 'enabled' => true, 'allowed_countries' => '["US"]', 'per_minute' => 10, 'daily_limit' => 1, 'monthly_limit' => 10, 'recipient_daily_limit' => 10, 'approved_by' => $admin->id, ...$vault->encrypt(['fingerprint_key' => base64_encode(random_bytes(32))], 'sms-policy:'.$org->id)]);
        DB::table('connections')->insert(['id' => $id, 'organization_id' => $org->id, 'provider_id' => 'twilio_sms', 'name' => 'SMS race', 'credential_type' => 'api_key', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('credential_secrets')->insert(['connection_id' => $id, ...$vault->encrypt(['account_sid' => $sid, 'auth_token' => str_repeat('0', 32)], $id), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sms_accounts')->insert(['connection_id' => $id, 'account_sid' => $sid, 'active_account_sid' => $sid, 'approved_by' => $admin->id, 'health' => 'healthy']);
        $data = ['connection_id' => $id];
        foreach (['sender' => '+15005550006', 'recipient' => '+15005550009'] as $kind => $phone) {
            $resource = (string) Str::uuid();
            $data[$kind.'_id'] = $resource;
            DB::table('sms_'.$kind.'s')->insert(['id' => $resource, 'organization_id' => $org->id, 'connection_id' => $id, 'label' => 'Synthetic', 'country' => 'US', 'fingerprint' => app(SmsAuthorization::class)->fingerprint($org->id, $phone), 'masked_phone' => '...0000', 'approved_by' => $admin->id, 'consent_at' => now(), 'expires_at' => now()->addDay(), ...$vault->encrypt(['phone' => $phone], 'sms-'.$kind.':'.$resource)]);
        }
        $data['template_id'] = (string) Str::uuid();
        DB::table('sms_templates')->insert(['id' => $data['template_id'], 'organization_id' => $org->id, 'name' => 'Safe outcome', 'body' => 'Request {{request.uuid}} updated.', 'approved_by' => $admin->id]);
        $workflow = Workflow::factory()->create(['organization_id' => $org->id]);
        DB::table('sms_workflow_grants')->insert(['organization_id' => $org->id, 'workflow_id' => $workflow->id, 'operation' => 'sms.notification', 'authorized_by' => $admin->id, ...$data]);
        $node = WorkflowGraphs::node((string) Str::uuid(), 'sms_notification', 0, 0, ['label' => 'SMS', ...$data]);
        $version = WorkflowVersion::factory()->create(['workflow_id' => $workflow->id, 'node_registry_version' => 3, 'graph_definition' => ['nodes' => [$node], 'edges' => []], 'form_definition' => ['fields' => []]]);
        foreach (range(1, 2) as $n) {
            $instance = WorkflowInstance::create(['organization_id' => $org->id, 'workflow_id' => $workflow->id, 'workflow_version_id' => $version->id, 'submitted_by' => $admin->id, 'status' => 'running', 'current_node_id' => $node['id'], 'submitted_data' => [], 'started_at' => now()]);
            $node = PublishedGraph::fromVersion($version)->node($node['id']);
            app(SmsWorkflow::class)->enqueue($instance, $version, $admin, $node);
        }
        $ids = DB::table('sms_action_outbox')->orderBy('id')->pluck('id')->all();
        $connection = config('database.connections.pgsql');
        $at = microtime(true) + 1;
        $work = static function () use ($connection, $ids, $key, $sid, $at): bool {
            config(['database.default' => 'pgsql', 'database.connections.pgsql' => $connection, 'sms.enabled' => true, 'integrations.keys.v1' => $key, 'sms.webhook_origin' => 'https://flowmv.example.test']);
            DB::purge('pgsql');
            $api = new SmsApi(new Factory);
            $api->http->preventStrayRequests();
            $api->http->fake(function () use ($sid) {
                usleep(100000);

                return Factory::response(['account_sid' => $sid, 'sid' => 'SM'.bin2hex(random_bytes(16))], 201);
            });
            app()->instance(SmsApi::class, $api);
            while (microtime(true) < $at) {
                usleep(1000);
            }
            foreach ($ids as $id) {
                app(SmsDelivery::class)->process($id);
            }

            return true;
        };
        Concurrency::driver('process')->run([$work, $work]);
        $this->assertSame(1, DB::table('sms_action_outbox')->where('status', 'accepted')->count());
        $this->assertSame(1, DB::table('sms_action_outbox')->where('status', 'blocked')->count());
        $this->assertSame(1, (int) DB::table('sms_action_outbox')->sum('attempt_count'));
        $this->assertSame(1, DB::table('sms_action_outbox')->whereNotNull('budget_reserved_at')->count());
    }

    public function test_concurrent_telegram_buttons_make_exactly_one_internal_decision(): void
    {
        $key = base64_encode(random_bytes(32));
        config(['integrations.telegram_enabled' => true, 'integrations.keys.v1' => $key]);
        Queue::fake();
        $admin = User::factory()->create();
        $manager = User::factory()->create();
        $employee = User::factory()->create();
        $org = Organization::factory()->create(['created_by' => $admin->id]);
        foreach ([[$admin, OrganizationRole::Admin], [$manager, OrganizationRole::Manager], [$employee, OrganizationRole::Employee]] as [$user, $role]) {
            OrganizationMembership::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => $role]);
        }
        DB::table('integration_approvals')->insert(['organization_id' => $org->id, 'provider_id' => 'telegram', 'approved' => true]);
        $id = (string) Str::uuid();
        DB::table('connections')->insert(['id' => $id, 'organization_id' => $org->id, 'provider_id' => 'telegram', 'name' => 'Approval race', 'credential_type' => 'bot_token', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('credential_secrets')->insert(['connection_id' => $id, ...app(CredentialVault::class)->encrypt(['bot_token' => '123456:'.str_repeat('Synthetic_', 4)], $id), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('telegram_bots')->insert(['connection_id' => $id, 'bot_id' => '123456', 'active_bot_id' => '123456', 'username' => 'race_bot', 'approved_by' => $admin->id, 'webhook_configured' => true, 'webhook_secret_hash' => hash('sha256', 'synthetic-race-secret')]);
        DB::table('telegram_destinations')->insert(['connection_id' => $id, 'chat_id' => '888', 'chat_type' => 'private', 'label' => 'Manager QA', 'approved_by' => $admin->id]);
        DB::table('telegram_identity_links')->insert(['connection_id' => $id, 'user_id' => $manager->id, 'telegram_user_id' => '888', 'confirmed' => true]);
        $workflow = Workflow::factory()->create(['organization_id' => $org->id, 'status' => 'published']);
        DB::table('workflow_connection_grants')->insert(['organization_id' => $org->id, 'connection_id' => $id, 'workflow_id' => $workflow->id, 'operation' => 'telegram.approval', 'authorized_by' => $admin->id]);
        $definition = WorkflowGraphs::publishable();
        foreach ($definition['graph_definition']['nodes'] as &$node) {
            if ($node['type'] === 'approval') {
                $node['data']['telegram_approval'] = ['connection_id' => $id, 'destination' => '888'];
            }
        }
        unset($node);
        $version = WorkflowVersion::factory()->create(['workflow_id' => $workflow->id, 'node_registry_version' => 3, ...$definition]);
        $workflow->forceFill(['current_published_version_id' => $version->id])->save();
        $instance = app(StartWorkflowInstance::class)($employee, $org, $workflow, []);
        $delivery = DB::table('telegram_approval_deliveries')->first();
        app(TelegramApi::class)->http->preventStrayRequests();
        app(TelegramApi::class)->http->fake(['https://api.telegram.org/bot*/sendMessage' => Factory::response(['ok' => true, 'result' => ['message_id' => 900]])]);
        app(TelegramDecisions::class)->deliver($delivery->id);
        $payload = app(CredentialVault::class)->decrypt($delivery, 'telegram-approval:'.$delivery->id);
        foreach ([0, 1] as $index) {
            app(TelegramIngress::class)->receive($id, 'synthetic-race-secret', ['update_id' => 100 + $index, 'callback_query' => ['id' => 'race-query-'.$index, 'data' => $payload['keyboard'][0][$index]['callback_data'], 'from' => ['id' => 888, 'is_bot' => false], 'message' => ['message_id' => 900, 'chat' => ['id' => 888, 'type' => 'private'], 'from' => ['id' => 123456, 'is_bot' => true]]]]);
        }
        $ids = DB::table('telegram_decision_callbacks')->orderBy('update_id')->pluck('id')->all();
        $connection = config('database.connections.pgsql');
        $at = microtime(true) + 1;
        $work = static function (int $index) use ($connection, $key, $ids, $at): string {
            config(['database.default' => 'pgsql', 'database.connections.pgsql' => $connection, 'integrations.telegram_enabled' => true, 'integrations.keys.v1' => $key]);
            DB::purge('pgsql');
            Queue::fake();
            app(TelegramApi::class)->http->preventStrayRequests();
            app(TelegramApi::class)->http->fake(['https://api.telegram.org/bot*/answerCallbackQuery' => Factory::response(['ok' => true, 'result' => true])]);
            while (microtime(true) < $at) {
                usleep(1000);
            }
            app(TelegramDecisions::class)->process($ids[$index]);

            return DB::table('telegram_decision_callbacks')->where('id', $ids[$index])->value('status');
        };
        $approve = static function () use ($work): string {
            return $work(0);
        };
        $reject = static function () use ($work): string {
            return $work(1);
        };
        $results = Concurrency::driver('process')->run([$approve, $reject]);
        sort($results);
        $this->assertSame(['done', 'rejected'], $results);
        $this->assertSame(1, DB::table('workflow_submission_snapshots')->where('workflow_task_id', $delivery->workflow_task_id)->count());
        $this->assertSame(1, DB::table('integration_audit_events')->where('event', 'telegram.decision.accepted')->count());
        $this->assertSame(2, DB::table('telegram_decision_capabilities')->whereNotNull('consumed_at')->count());
        $this->assertContains($instance->fresh()->status->value, ['completed', 'awaiting_revision']);
    }

    public function test_concurrent_slack_refresh_and_event_replay_are_serialized(): void
    {
        $key = base64_encode(random_bytes(32));
        config(['slack.enabled' => true, 'slack.signing_secret' => 'synthetic-concurrency-signing-key', 'slack.app_id' => 'A12345678', 'integrations.keys.v1' => $key]);
        $admin = User::factory()->create();
        $org = Organization::factory()->create(['created_by' => $admin->id]);
        OrganizationMembership::factory()->create(['organization_id' => $org->id, 'user_id' => $admin->id, 'role' => OrganizationRole::Admin]);
        DB::table('integration_approvals')->insert(['organization_id' => $org->id, 'provider_id' => 'slack', 'approved' => true]);
        $id = (string) Str::uuid();
        DB::table('connections')->insert(['id' => $id, 'organization_id' => $org->id, 'provider_id' => 'slack', 'name' => 'Slack race', 'credential_type' => 'oauth', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('slack_installations')->insert(['connection_id' => $id, 'workspace_id' => 'T12345678', 'active_workspace_id' => 'T12345678', 'bot_user_id' => 'U99999999', 'scopes' => json_encode(config('slack.scopes')), 'approved_by' => $admin->id, 'health' => 'healthy', 'token_expires_at' => now()->subMinute()]);
        DB::table('credential_secrets')->insert(['connection_id' => $id, ...app(CredentialVault::class)->encrypt(['access_token' => 'synthetic-old', 'refresh_token' => 'synthetic-old-refresh'], $id), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('slack_channels')->insert(['connection_id' => $id, 'channel_id' => 'C12345678', 'label' => 'QA', 'approved_by' => $admin->id]);
        DB::table('slack_identity_links')->insert(['connection_id' => $id, 'user_id' => $admin->id, 'slack_user_id' => 'U12345678', 'confirmed' => true]);
        $connection = config('database.connections.pgsql');
        $at = microtime(true) + 1;
        $work = static function () use ($connection, $key, $id, $at): string {
            config(['database.default' => 'pgsql', 'database.connections.pgsql' => $connection, 'slack.enabled' => true, 'slack.signing_secret' => 'synthetic-concurrency-signing-key', 'slack.app_id' => 'A12345678', 'integrations.keys.v1' => $key]);
            DB::purge('pgsql');
            Queue::fake();
            $api = app(SlackApi::class);
            $api->http->preventStrayRequests();
            $api->http->fake([
                'https://slack.com/api/oauth.v2.access' => Factory::response(['ok' => true, 'token_type' => 'bot', 'access_token' => 'synthetic-new', 'refresh_token' => 'synthetic-new-refresh', 'expires_in' => 43200, 'scope' => implode(',', config('slack.scopes'))]),
                'https://slack.com/api/auth.test' => Factory::response(['ok' => true, 'team_id' => 'T12345678', 'user_id' => 'U99999999']),
            ]);
            while (microtime(true) < $at) {
                usleep(1000);
            }
            $token = app(SlackTokens::class)->access($id);
            $raw = json_encode(['api_app_id' => 'A12345678', 'type' => 'event_callback', 'team_id' => 'T12345678', 'event_id' => 'EvSameEvent', 'event' => ['type' => 'app_mention', 'channel' => 'C12345678', 'user' => 'U12345678', 'text' => 'Synthetic race']]);
            $timestamp = (string) now()->timestamp;
            app(SlackIngress::class)->receive($raw, $timestamp, 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$raw, config('slack.signing_secret')));

            return hash('sha256', $token);
        };
        $results = Concurrency::driver('process')->run([$work, $work]);
        $this->assertSame($results[0], $results[1]);
        $this->assertSame(1, DB::table('integration_audit_events')->where('event', 'slack.token.refreshed')->count());
        $this->assertDatabaseCount('slack_inbound_events', 1);
        $secret = app(CredentialVault::class)->decrypt(DB::table('credential_secrets')->where('connection_id', $id)->first(), $id);
        $this->assertSame('synthetic-new-refresh', $secret['refresh_token']);
    }

    public function test_concurrent_email_refresh_rotates_once_and_notification_receipts_deduplicate(): void
    {
        $key = base64_encode(random_bytes(32));
        config(['email_integrations.enabled' => true, 'integrations.keys.v1' => $key, 'email_integrations.outlook.client_id' => 'synthetic-client', 'email_integrations.outlook.client_secret' => 'synthetic-secret']);
        $admin = User::factory()->create();
        $org = Organization::factory()->create(['created_by' => $admin->id]);
        OrganizationMembership::factory()->create(['organization_id' => $org->id, 'user_id' => $admin->id, 'role' => OrganizationRole::Admin]);
        DB::table('integration_approvals')->insert(['organization_id' => $org->id, 'provider_id' => 'outlook', 'approved' => true]);
        $id = (string) Str::uuid();
        DB::table('connections')->insert(['id' => $id, 'organization_id' => $org->id, 'owner_user_id' => null, 'provider_id' => 'outlook', 'name' => 'Concurrent mailbox', 'credential_type' => 'oauth', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('email_mailboxes')->insert(['connection_id' => $id, 'address' => 'race@example.com', 'account_id' => 'synthetic-account', 'active_identity' => hash('sha256', $id), 'capabilities' => '["read"]', 'approved_by' => $admin->id, 'credential_generation' => 1, 'token_expires_at' => now()->subMinute(), 'health' => 'ready', 'connected_at' => now()]);
        DB::table('credential_secrets')->insert(['connection_id' => $id, ...app(CredentialVault::class)->encrypt(['access_token' => 'expired-synthetic', 'refresh_token' => 'old-synthetic-refresh', 'scope' => 'Mail.Read User.Read'], $id), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('email_sync_state')->insert(['connection_id' => $id, ...app(CredentialVault::class)->encrypt(['cursor' => null, 'next' => null, 'subscription_secret' => 'synthetic-state'], 'email-sync:'.$id), 'subscription_id' => 'synthetic-sub', 'subscription_secret_hash' => hash('sha256', 'synthetic-state'), 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $connection = config('database.connections.pgsql');
        $orgId = $org->id;
        $at = microtime(true) + 1;
        $work = static function () use ($key, $id, $connection, $orgId, $at): string {
            config(['database.default' => 'pgsql', 'database.connections.pgsql' => $connection, 'email_integrations.enabled' => true, 'integrations.keys.v1' => $key, 'email_integrations.outlook.client_id' => 'synthetic-client', 'email_integrations.outlook.client_secret' => 'synthetic-secret']);
            DB::purge('pgsql');
            Queue::fake();
            $http = app(EmailHttp::class);
            $http->http->preventStrayRequests();
            $http->http->fake(fn () => Factory::response(['access_token' => 'new-synthetic-access', 'refresh_token' => 'new-synthetic-refresh', 'scope' => 'Mail.Read User.Read', 'expires_in' => 3600, 'token_type' => 'Bearer']));
            while (microtime(true) < $at) {
                usleep(1000);
            }
            $token = app(EmailTokens::class)->get($orgId, $id);
            app(EmailIngress::class)->outlook(['value' => [['subscriptionId' => 'synthetic-sub', 'clientState' => 'synthetic-state', 'changeType' => 'created', 'resourceData' => ['id' => 'same-message']]]]);

            return hash('sha256', $token);
        };
        $results = Concurrency::driver('process')->run([$work, $work]);
        $this->assertSame($results[0], $results[1]);
        $this->assertSame(1, DB::table('integration_audit_events')->where('event', 'email.token.refreshed')->count());
        $this->assertDatabaseCount('email_notification_receipts', 1);
        $secret = app(CredentialVault::class)->decrypt(DB::table('credential_secrets')->where('connection_id', $id)->first(), $id);
        $this->assertSame('new-synthetic-refresh', $secret['refresh_token']);
    }

    public function test_concurrent_telegram_receipt_activation_and_delivery_are_deduplicated(): void
    {
        $key = base64_encode(random_bytes(32));
        config(['integrations.telegram_enabled' => true, 'integrations.keys.v1' => $key]);
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $organization = Organization::factory()->create(['created_by' => $admin->id]);
        foreach ([[$admin, OrganizationRole::Admin], [$member, OrganizationRole::Employee]] as [$user, $role]) {
            OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role]);
        }
        DB::table('integration_approvals')->insert(['organization_id' => $organization->id, 'provider_id' => 'telegram', 'approved' => true]);
        $id = (string) Str::uuid();
        DB::table('connections')->insert(['id' => $id, 'organization_id' => $organization->id, 'owner_user_id' => null, 'provider_id' => 'telegram', 'name' => 'Concurrent test bot', 'credential_type' => 'bot_token', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('credential_secrets')->insert(['connection_id' => $id, ...app(CredentialVault::class)->encrypt(['bot_token' => '123456:'.str_repeat('Synthetic_', 4)], $id), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('telegram_bots')->insert(['connection_id' => $id, 'bot_id' => '123456', 'active_bot_id' => '123456', 'username' => 'qa_bot', 'approved_by' => $admin->id, 'webhook_secret_hash' => hash('sha256', 'synthetic-webhook-secret'), 'webhook_configured' => true]);
        DB::table('telegram_destinations')->insert(['connection_id' => $id, 'chat_id' => '11199', 'chat_type' => 'private', 'label' => 'Test inbox', 'approved_by' => $admin->id]);
        DB::table('telegram_identity_links')->insert(['connection_id' => $id, 'user_id' => $member->id, 'telegram_user_id' => '11199', 'confirmed' => true]);
        $workflow = Workflow::factory()->create(['organization_id' => $organization->id, 'status' => 'published']);
        foreach (['telegram.message', 'telegram.send_message'] as $op) {
            DB::table('workflow_connection_grants')->insert(['connection_id' => $id, 'organization_id' => $organization->id, 'workflow_id' => $workflow->id, 'authorized_by' => $admin->id, 'operation' => $op]);
        }
        $trigger = (string) Str::uuid();
        $send = (string) Str::uuid();
        $end = (string) Str::uuid();
        $graph = ['nodes' => [
            WorkflowGraphs::node($trigger, 'telegram_trigger', 0, 0, ['label' => 'Trigger', 'connection_id' => $id, 'destination' => '11199', 'input_mapping' => []]),
            WorkflowGraphs::node($send, 'telegram_send_message', 100, 0, ['label' => 'Send', 'connection_id' => $id, 'destination' => '11199', 'text' => 'Request {{request.uuid}}', 'allowed_field_ids' => []]),
            WorkflowGraphs::node($end, 'end', 200, 0, ['label' => 'End', 'result' => 'completed']),
        ], 'edges' => [WorkflowGraphs::edge($trigger, $send, 'out'), WorkflowGraphs::edge($send, $end, 'out')]];
        $version = WorkflowVersion::factory()->create(['workflow_id' => $workflow->id, 'node_registry_version' => 3, 'graph_definition' => $graph]);
        $workflow->forceFill(['current_published_version_id' => $version->id])->save();
        app(TelegramWorkflow::class)->subscribe($admin, $workflow, $version);
        $connection = config('database.connections.pgsql');
        $orgId = $organization->id;
        $event = ['update_id' => 100, 'message' => ['message_id' => 100, 'date' => now()->timestamp, 'from' => ['id' => 11199, 'is_bot' => false], 'chat' => ['id' => 11199, 'type' => 'private'], 'text' => 'Synthetic concurrent event']];
        $phase = 'receive';
        $workId = '';
        $at = microtime(true) + 1;
        $work = static function () use ($connection, $key, $id, $orgId, $event, &$phase, &$workId, &$at): string {
            config(['database.default' => 'pgsql', 'database.connections.pgsql' => $connection, 'integrations.telegram_enabled' => true, 'integrations.keys.v1' => $key]);
            DB::purge('pgsql');
            Queue::fake();
            app(TelegramApi::class)->http->preventStrayRequests();
            app(TelegramApi::class)->http->fake(function () use ($orgId, $id) {
                DB::table('integration_audit_events')->insert(['organization_id' => $orgId, 'connection_id' => $id, 'actor_user_id' => null, 'event' => 'telegram.test.http_attempt', 'created_at' => now()]);

                return Factory::response(['ok' => true, 'result' => ['message_id' => 900]]);
            });
            while (microtime(true) < $at) {
                usleep(1000);
            }
            if ($phase === 'receive') {
                app(TelegramIngress::class)->receive($id, 'synthetic-webhook-secret', $event);
            } elseif ($phase === 'activate') {
                app(TelegramActivation::class)->process($workId);
            } else {
                app(TelegramDelivery::class)->process($workId);
            }

            return 'done';
        };
        Concurrency::driver('process')->run([$work, $work]);
        $this->assertSame(1, DB::table('telegram_inbound_events')->count());
        $this->assertSame(1, DB::table('integration_trigger_activations')->count());
        $phase = 'activate';
        $workId = DB::table('integration_trigger_activations')->value('id');
        $at = microtime(true) + 1;
        Concurrency::driver('process')->run([$work, $work]);
        $this->assertSame(1, WorkflowInstance::count());
        $this->assertSame(1, DB::table('integration_action_outbox')->count());
        $phase = 'deliver';
        $workId = DB::table('integration_action_outbox')->value('id');
        $at = microtime(true) + 1;
        Concurrency::driver('process')->run([$work, $work]);
        $this->assertSame('sent', DB::table('integration_action_outbox')->value('status'));
        $this->assertSame(1, DB::table('integration_audit_events')->where('event', 'telegram.test.http_attempt')->count());
    }

    /** Forward-only migrations are recreated only in the guarded disposable database. */
    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_concurrent_integration_retries_record_one_receipt(): void
    {
        $key = base64_encode(random_bytes(32));
        config(['integrations.mock_enabled' => true, 'integrations.keys.v1' => $key]);
        $actor = User::factory()->create();
        $organization = Organization::factory()->create(['created_by' => $actor->id]);
        OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $actor->id, 'role' => OrganizationRole::Admin]);
        DB::table('integration_approvals')->insert(['organization_id' => $organization->id, 'provider_id' => 'local_mock', 'approved' => true]);
        $manager = app(ConnectionManager::class);
        $id = $manager->create($actor->id, $organization->id, ['name' => 'Concurrency mock', 'ownership' => 'organization', 'credential_type' => 'api_key'], ['api_key' => 'synthetic-concurrency-key-only']);
        $workflow = Workflow::factory()->create(['organization_id' => $organization->id]);
        $manager->act($actor->id, $organization->id, $id, 'share', ['workflow_id' => $workflow->uuid, 'operation' => 'mock.notify', 'destination' => 'mock:inbox']);
        $nodeId = (string) Str::uuid();
        $config = ['connection_id' => $id, 'operation' => 'mock.notify', 'destination' => 'mock:inbox'];
        $version = WorkflowVersion::factory()->create(['workflow_id' => $workflow->id, 'node_registry_version' => 3, 'graph_definition' => ['nodes' => [['id' => $nodeId, 'type' => 'integration_action', 'data' => $config]], 'edges' => []]]);
        $instance = WorkflowInstance::query()->create(['organization_id' => $organization->id, 'workflow_id' => $workflow->id, 'workflow_version_id' => $version->id, 'submitted_by' => $actor->id, 'status' => 'running', 'current_node_id' => $nodeId, 'submitted_data' => [], 'started_at' => now()]);
        $instanceId = $instance->id;
        $connection = config('database.connections.pgsql');
        $at = microtime(true) + 1;
        $work = static function () use ($connection, $key, $instanceId, $config, $nodeId, $at): bool {
            config(['database.default' => 'pgsql', 'database.connections.pgsql' => $connection, 'integrations.mock_enabled' => true, 'integrations.keys.v1' => $key]);
            DB::purge('pgsql');
            while (microtime(true) < $at) {
                usleep(1000);
            }

            return app(IntegrationRuntime::class)->action(WorkflowInstance::findOrFail($instanceId), $config, $nodeId);
        };
        $results = Concurrency::driver('process')->run([$work, $work]);
        sort($results);
        $this->assertSame([false, true], $results);
        $this->assertSame(1, DB::table('integration_receipts')->count());
        $this->assertSame(1, DB::table('integration_audit_events')->where('event', 'integration.action.accepted')->count());
    }

    use InteractsWithExecution;

    public function test_postgresql_notification_text_payloads_are_scoped_and_can_be_marked_read(): void
    {
        Mail::fake();
        Queue::fake();
        $organization = $this->signInMember(OrganizationRole::Admin);
        $workflow = $this->publishWorkflow($organization, AutomationGraphs::notification());
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $this->addMember($organization, OrganizationRole::Manager);
        $this->signIn($employee);
        $this->startInstance($organization, $workflow)->assertCreated();
        $row = NotificationOutbox::where('recipient_user_id', $employee->id)->where('channel', 'in_app')->firstOrFail();
        (new ProcessNotificationOutbox($row->workflow_instance_id, $row->node_id))->handle(app(WorkflowEventWriter::class));
        $notification = $employee->notifications()->firstOrFail();
        $notification->update(['data' => ['instance_uuid' => $row->instance->uuid]]);
        $base = "/api/v1/organizations/{$organization->uuid}/notifications";
        $this->getJson($base)->assertOk()->assertJsonCount(1, 'data.items');
        $read = $this->postJson("{$base}/{$notification->id}/read")->assertOk()->json('data.read_at');
        $this->postJson("{$base}/{$notification->id}/read")->assertOk()->assertJsonPath('data.read_at', $read);
        $this->assertNotNull($notification->fresh()->read_at);
        $this->getJson("{$base}?status=read")->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson("{$base}?status=unread")->assertOk()->assertJsonCount(0, 'data.items');
        $employee->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => $notification->type,
            'data' => ['instance_uuid' => 'not-a-uuid', 'organization_uuid' => $organization->uuid], 'read_at' => null,
        ]);
        for ($index = 0; $index < 50; $index++) {
            $employee->notifications()->create([
                'id' => (string) Str::uuid(), 'type' => $notification->type,
                'data' => ['instance_uuid' => $row->instance->uuid], 'read_at' => null,
            ]);
        }
        $this->getJson($base)->assertOk()->assertJsonCount(50, 'data.items')
            ->assertJsonPath('data.total', 51)->assertJsonPath('data.last_page', 2);
        $this->getJson("{$base}?page=2")->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson("{$base}?status=unread")->assertOk()->assertJsonPath('data.total', 50);
        $this->getJson("{$base}?page=0")->assertUnprocessable();
        $this->getJson("{$base}?status=invalid")->assertUnprocessable();
        $other = Organization::factory()->create();
        OrganizationMembership::factory()->create(['organization_id' => $other->id, 'user_id' => $employee->id, 'role' => OrganizationRole::Employee]);
        $this->getJson("/api/v1/organizations/{$other->uuid}/notifications")->assertOk()->assertJsonCount(0, 'data.items');
        $this->postJson("/api/v1/organizations/{$other->uuid}/notifications/{$notification->id}/read")->assertNotFound();
        $this->signIn(User::findOrFail($organization->created_by));
        $this->getJson($base)->assertOk()->assertJsonCount(0, 'data.items');
        $this->postJson("{$base}/{$notification->id}/read")->assertNotFound();
        $this->signIn($employee);
        OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $employee->id)->update(['revoked_at' => now()]);
        $this->getJson($base)->assertNotFound();
        $this->postJson("{$base}/{$notification->id}/read")->assertNotFound();
    }

    public function test_local_endpoint_profile_records_query_counts_without_submission_values(): void
    {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        $organization = $this->signInMember(OrganizationRole::Admin);
        $actor = User::findOrFail($organization->created_by);
        $workflow = $this->publishWorkflow($organization, ExecutionGraphs::approval());
        $this->addMember($organization, OrganizationRole::Manager);
        $this->postJson('/api/v1/auth/logout')->assertSuccessful();
        $counts = ['count' => 0, 'ms' => 0.0];
        DB::listen(static function ($query) use (&$counts): void {
            $counts['count']++;
            $counts['ms'] += $query->time;
        });
        $profile = [];
        $measure = function (string $label, callable $request, int $status) use (&$counts, &$profile): void {
            $this->refreshGuards();
            $counts = ['count' => 0, 'ms' => 0.0];
            $start = hrtime(true);
            $response = $request();
            $elapsed = (hrtime(true) - $start) / 1e6;
            $response->assertStatus($status);
            $profile[$label] = ['kernel_ms' => round($elapsed, 2), 'sql_count' => $counts['count'], 'sql_ms' => round($counts['ms'], 2)];
        };
        $base = "/api/v1/organizations/{$organization->uuid}";
        $measure('csrf', fn () => $this->getJson('/sanctum/csrf-cookie'), 204);
        $measure('login', fn () => $this->postJson('/api/v1/auth/login', ['email' => $actor->email, 'password' => 'password']), 200);
        foreach (['first', 'warm'] as $wave) {
            $measure("{$wave}.me", fn () => $this->getJson('/api/v1/me'), 200);
            $measure("{$wave}.workflow_list", fn () => $this->getJson("{$base}/workflows"), 200);
            $measure("{$wave}.workflow_detail", fn () => $this->getJson("{$base}/workflows/{$workflow}"), 200);
            $measure("{$wave}.submission", fn () => $this->startInstance($organization, $workflow), 201);
            $measure("{$wave}.tasks", fn () => $this->getJson("{$base}/tasks"), 200);
            $range = '?from='.now()->subDays(1)->toDateString().'&to='.now()->toDateString();
            $measure("{$wave}.analytics", fn () => $this->getJson("{$base}/analytics{$range}"), 200);
            $measure("{$wave}.audit", fn () => $this->getJson("{$base}/audit{$range}"), 200);
        }
        $directory = storage_path('app/phase85-qa');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory.'/endpoint-profile.json', json_encode($profile, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public function test_runtime_permissions_allow_execution_but_refuse_immutable_writes_and_ddl(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $workflowUuid = $this->publishWorkflow($organization, ExecutionGraphs::approval());
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $manager = $this->addMember($organization, OrganizationRole::Manager);
        $role = 'flowmv_acceptance_'.strtolower(Str::random(12));
        DB::statement("CREATE ROLE {$role} NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS");
        try {
            DB::unprepared(str_replace(':runtime_role', $role, file_get_contents(database_path('runtime-permissions.sql'))));
            DB::statement("SET ROLE {$role}");
            $instance = app(StartWorkflowInstance::class)($employee, $organization, Workflow::where('uuid', $workflowUuid)->firstOrFail(), []);
            $task = $instance->tasks()->where('status', 'open')->firstOrFail();
            app(DecideWorkflowTask::class)($manager, $task, 'approved');
            $this->assertSame('completed', $instance->fresh()->status->value);
            $this->assertSame(2, DB::table('workflow_submission_snapshots')->where('workflow_instance_id', $instance->id)->count());
            foreach ([
                'UPDATE workflow_submission_snapshots SET submitted_data = submitted_data',
                'DELETE FROM workflow_submission_snapshots',
                'UPDATE workflow_versions SET version_number = version_number',
                'DELETE FROM workflow_events',
                'CREATE TABLE runtime_must_not_create (id int)',
            ] as $sql) {
                try {
                    DB::statement($sql);
                    $this->fail('Runtime permissions allowed a protected operation.');
                } catch (QueryException $exception) {
                    $this->assertSame('42501', $exception->errorInfo[0]);
                }
            }
            try {
                DB::table('organizations')->where('id', $organization->id)->delete();
                $this->fail('The existing audit immutability trigger allowed deletion of event history.');
            } catch (QueryException $exception) {
                $this->assertSame('P0001', $exception->errorInfo[0]);
            }
            $bare = WorkflowInstance::query()->create([
                'organization_id' => $organization->id,
                'workflow_id' => $instance->workflow_id,
                'workflow_version_id' => $instance->workflow_version_id,
                'status' => 'running', 'current_node_id' => $instance->current_node_id, 'submitted_data' => [], 'started_at' => now(),
            ]);
            DB::table('workflow_submission_snapshots')->insert([
                'uuid' => (string) Str::uuid(), 'organization_id' => $organization->id,
                'workflow_instance_id' => $bare->id, 'reason' => 'initial',
                'submitted_data' => '{}', 'captured_at' => now(),
            ]);
            DB::table('workflow_instances')->where('id', $bare->id)->delete();
            $this->assertSame(0, DB::table('workflow_submission_snapshots')->where('workflow_instance_id', $bare->id)->count());
        } finally {
            DB::statement('RESET ROLE');
            DB::statement("DROP OWNED BY {$role}");
            DB::statement("DROP ROLE {$role}");
        }
    }

    public function test_simultaneous_approvals_record_one_decision_and_one_successor(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $definition = ExecutionGraphs::approval();
        $graph = &$definition['graph_definition'];
        $approval = collect($graph['nodes'])->firstWhere('type', 'approval');
        $next = $approval;
        $next['id'] = (string) Str::uuid();
        $graph['nodes'][] = $next;
        foreach ($graph['edges'] as &$edge) {
            if ($edge['source'] === $approval['id'] && $edge['source_handle'] === 'approved') {
                $end = $edge['target'];
                $edge['target'] = $next['id'];
            }
        }
        unset($edge);
        $graph['edges'][] = WorkflowGraphs::edge($next['id'], $end, 'approved');
        $revision = collect($graph['nodes'])->firstWhere('type', 'revision');
        $graph['edges'][] = WorkflowGraphs::edge($next['id'], $revision['id'], 'rejected');
        $workflow = $this->publishWorkflow($organization, $definition);
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $manager = $this->addMember($organization, OrganizationRole::Manager);
        $this->signIn($employee);
        $started = $this->startInstance($organization, $workflow)->assertCreated();
        $taskId = WorkflowTask::where('uuid', $started->json('data.tasks.0.uuid'))->value('id');
        $managerId = $manager->id;
        $connection = config('database.connections.pgsql');
        $at = microtime(true) + 1;
        $work = static function () use ($connection, $taskId, $managerId, $at): string {
            config(['database.default' => 'pgsql', 'database.connections.pgsql' => $connection]);
            DB::purge('pgsql');
            while (microtime(true) < $at) {
                usleep(1000);
            }
            try {
                app(DecideWorkflowTask::class)(User::findOrFail($managerId), WorkflowTask::findOrFail($taskId), 'approved');

                return 'approved';
            } catch (WorkflowConflictException) {
                return 'conflict';
            }
        };
        $results = Concurrency::driver('process')->run([$work, $work]);
        sort($results);
        $this->assertSame(['approved', 'conflict'], $results);
        $instance = WorkflowInstance::where('uuid', $started->json('data.uuid'))->firstOrFail();
        $this->assertSame('awaiting_approval', $instance->status->value);
        $this->assertSame(1, $instance->tasks()->where('status', 'open')->count());
        $this->assertSame(1, $instance->tasks()->where('status', 'completed')->count());
        $this->assertSame(1, $instance->events()->where('event_type', 'task_approved')->count());
    }

    public function test_simultaneous_duplicate_keys_create_one_instance_and_replay_the_same_result(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $workflowUuid = $this->publishWorkflow($organization, ExecutionGraphs::approval());
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $this->addMember($organization, OrganizationRole::Manager);
        $actorId = $employee->id;
        $organizationId = $organization->id;
        $connection = config('database.connections.pgsql');
        $key = (string) Str::uuid();
        $at = microtime(true) + 1;
        $work = static function () use ($connection, $actorId, $organizationId, $workflowUuid, $key, $at): string {
            config(['database.default' => 'pgsql', 'database.connections.pgsql' => $connection]);
            DB::purge('pgsql');
            $actor = User::findOrFail($actorId);
            $organization = Organization::findOrFail($organizationId);
            $workflow = Workflow::where('uuid', $workflowUuid)->firstOrFail();
            $request = Request::create('/concurrent-submission', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key], '{"data":{}}');
            while (microtime(true) < $at) {
                usleep(1000);
            }
            $response = app(IdempotentAction::class)->execute($actor, $organization, $request, static function () use ($actor, $organization, $workflow): array {
                $instance = app(StartWorkflowInstance::class)($actor, $organization, $workflow, []);

                return ['status' => 201, 'body' => ['uuid' => $instance->uuid]];
            });

            return $response->getData(true)['uuid'];
        };
        $results = Concurrency::driver('process')->run([$work, $work]);
        $this->assertSame($results[0], $results[1]);
        $this->assertSame(1, WorkflowInstance::query()->count());
        $instance = WorkflowInstance::query()->firstOrFail();
        $this->assertSame(1, $instance->tasks()->where('status', 'open')->count());
        $this->assertSame(1, $instance->events()->where('event_type', 'instance_started')->count());
    }

    public function test_concurrent_admin_releases_preserve_one_admin(): void
    {
        $organization = Organization::factory()->create(['admin_count' => 2]);
        $organizationId = $organization->id;
        $connection = config('database.connections.pgsql');
        $at = microtime(true) + 1;
        $work = static function () use ($connection, $organizationId, $at): string {
            config(['database.default' => 'pgsql', 'database.connections.pgsql' => $connection]);
            DB::purge('pgsql');
            while (microtime(true) < $at) {
                usleep(1000);
            }
            try {
                app(OrganizationAdminGuard::class)->release(Organization::findOrFail($organizationId));

                return 'released';
            } catch (ValidationException) {
                return 'refused';
            }
        };
        $results = Concurrency::driver('process')->run([$work, $work]);
        sort($results);
        $this->assertSame(['refused', 'released'], $results);
        $this->assertSame(1, $organization->fresh()->admin_count);
    }

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency coverage requires a disposable PostgreSQL target.');
        }

        $host = (string) config('database.connections.pgsql.host');
        $database = (string) config('database.connections.pgsql.database');
        $target = (string) getenv('FLOWMV_PGSQL_TEST_DATABASE');

        if (! in_array($host, ['127.0.0.1', '::1', 'localhost'], true)
            || $target === '' || ! str_starts_with($target, 'flowmv_')
            || ! str_ends_with($target, '_test') || $database !== $target
            || (string) config('database.connections.pgsql.url') !== '') {
            $this->markTestSkipped('Refusing destructive setup outside the explicit disposable loopback database.');
        }

        $actual = DB::selectOne('select current_database() as name, inet_server_addr()::text as address');
        if ($actual->name !== $target || ! in_array($actual->address, ['127.0.0.1/32', '127.0.0.1', '::1/128', '::1'], true)) {
            $this->markTestSkipped('The connected PostgreSQL server is not the verified local test target.');
        }
    }

    public function test_a_second_session_cannot_lock_or_open_the_same_step(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency coverage runs only in the disposable pgsql suite.');
        }

        $host = (string) config('database.connections.pgsql.host');
        $database = (string) config('database.connections.pgsql.database');

        if (str_contains($host, 'supabase.com') || $database !== (string) getenv('FLOWMV_PGSQL_TEST_DATABASE')) {
            $this->markTestSkipped('Refusing to run concurrency tests against a database that is not the explicit disposable target.');
        }

        $organization = Organization::factory()->create();
        $workflow = Workflow::factory()->create(['organization_id' => $organization->id]);
        $version = WorkflowVersion::factory()->create(['workflow_id' => $workflow->id]);
        $instance = WorkflowInstance::query()->create([
            'organization_id' => $organization->id,
            'workflow_id' => $workflow->id,
            'workflow_version_id' => $version->id,
            'status' => 'running',
            'current_node_id' => '11111111-1111-1111-1111-111111111111',
            'submitted_data' => [],
            'started_at' => now(),
        ]);

        $first = DB::connection('pgsql');
        config(['database.connections.pgsql_race' => array_merge(
            config('database.connections.pgsql'),
            ['name' => 'pgsql_race'],
        )]);
        DB::purge('pgsql_race');
        $second = DB::connection('pgsql_race');

        $first->beginTransaction();
        $first->select('select id from workflow_instances where id = ? for update', [$instance->id]);
        $second->statement("set lock_timeout = '500ms'");

        try {
            $second->select('select id from workflow_instances where id = ? for update', [$instance->id]);
            $this->fail('The second session acquired the instance lock.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $first->table('workflow_tasks')->insert([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'workflow_instance_id' => $instance->id,
            'node_id' => $instance->current_node_id,
            'task_type' => 'approval',
            'status' => 'open',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $second->statement("set lock_timeout = '500ms'");

        try {
            $second->table('workflow_tasks')->insert([
                'uuid' => (string) Str::uuid(),
                'organization_id' => $organization->id,
                'workflow_instance_id' => $instance->id,
                'node_id' => $instance->current_node_id,
                'task_type' => 'approval',
                'status' => 'open',
                'assigned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('The second session inserted another open task.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $first->commit();
        $this->assertSame(1, $second->table('workflow_tasks')->where('workflow_instance_id', $instance->id)->where('status', 'open')->count());
        $second->disconnect();
    }
}
