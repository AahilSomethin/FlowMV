<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowVersion;
use App\Services\Integrations\CredentialVault;
use App\Services\Integrations\TelegramActivation;
use App\Services\Integrations\TelegramApi;
use App\Services\Integrations\TelegramConnections;
use App\Services\Integrations\TelegramDelivery;
use App\Services\Integrations\TelegramQaTransport;
use App\Services\Workflows\Nodes\NodeRegistry;
use App\Services\Workflows\WorkflowDefinitionNormalizer;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Workflows\InteractsWithExecution;
use Tests\Support\ExecutionGraphs;
use Tests\Support\WorkflowGraphs;
use Tests\TestCase;

class TelegramIntegrationTest extends TestCase
{
    use InteractsWithExecution { setUp as protected spaSetUp; }
    use RefreshDatabase;

    private TelegramApi $api;

    private string $webhookSecret = '';

    private array $sendResponses = [];

    private string $token;

    protected function setUp(): void
    {
        $this->spaSetUp();
        config(['integrations.telegram_enabled' => true, 'integrations.telegram_webhook_origin' => 'https://flowmv.example.test', 'integrations.keys.v1' => base64_encode(random_bytes(32))]);
        $this->token = '123456:'.str_repeat('Synthetic_', 4);
        $this->api = app(TelegramApi::class);
        $this->api->http->preventStrayRequests();
        $this->api->http->fake(function ($request) {
            $method = basename($request->url());
            if ($method === 'getMe') {
                return Factory::response(['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true, 'username' => 'flowmv_test_bot']]);
            }
            if ($method === 'getChat') {
                return Factory::response(['ok' => true, 'result' => ['id' => (int) $request['chat_id'], 'type' => str_starts_with($request['chat_id'], '-') ? 'supergroup' : 'private']]);
            }
            if ($method === 'getChatMember') {
                return Factory::response(['ok' => true, 'result' => ['status' => 'administrator']]);
            }
            if ($method === 'setWebhook') {
                $this->webhookSecret = $request['secret_token'];

                return Factory::response(['ok' => true, 'result' => true]);
            }
            if ($method === 'sendMessage') {
                if ($this->sendResponses) {
                    $response = array_shift($this->sendResponses);
                    if ($response instanceof \Throwable) {
                        throw $response;
                    }

                    return $response;
                }

                return Factory::response(['ok' => true, 'result' => ['message_id' => 900]]);
            }

            return Factory::response(['ok' => true, 'result' => true]);
        });
        Queue::fake();
    }

    private function loginAs(User $user): void
    {
        $this->withCredentials(false);
        $this->signIn($user);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->withCredentials();
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
        $admin = User::findOrFail($org->created_by);
        $this->confirm();
        DB::table('integration_policies')->insert(['organization_id' => $org->id, 'personal_connections_allowed' => true]);
        $base = "/api/v1/organizations/{$org->uuid}/integrations/telegram";
        $this->putJson("$base/policy", ['approved' => true])->assertOk();
        $id = $this->postJson($base, ['name' => 'Test bot', 'ownership' => 'organization', 'credentials' => ['bot_token' => $this->token]])->assertCreated()->json('data.id');
        $this->postJson("$base/$id/approve")->assertOk();
        $this->postJson("$base/$id/configure-webhook")->assertOk();

        return [$org, $admin, $id, $base];
    }

    private function update(int $updateId = 100, string $text = '/request synthetic private content', int $sender = 777): array
    {
        return ['update_id' => $updateId, 'message' => ['message_id' => $updateId, 'date' => now()->timestamp, 'from' => ['id' => $sender, 'is_bot' => false], 'chat' => ['id' => $sender, 'type' => 'private'], 'text' => $text, 'entities' => [['type' => 'bot_command', 'offset' => 0, 'length' => 8]]]];
    }

    private function webhook(string $id, array $update, ?string $secret = null): TestResponse
    {
        return $this->postJson("/api/v1/integration-webhooks/telegram/$id", $update, ['X-Telegram-Bot-Api-Secret-Token' => $secret ?? $this->webhookSecret]);
    }

    private function link(Organization $org, User $user, string $id, string $base): void
    {
        $this->loginAs($user);
        $this->confirm();
        $command = $this->postJson("$base/$id/identity/start")->assertOk()->json('data.command');
        $this->webhook($id, $this->update(1, $command))->assertOk()->assertJsonPath('data.identity_pending', true);
        $this->postJson("$base/$id/identity/confirm")->assertOk();
    }

    private function publishedFixture(): array
    {
        [$org,$admin,$id,$base] = $this->fixture();
        $employee = $this->addMember($org, OrganizationRole::Employee);
        $manager = $this->addMember($org, OrganizationRole::Manager);
        $this->link($org, $employee, $id, $base);
        $this->loginAs($admin);
        $this->confirm();
        $this->postJson("$base/$id/approve-destination", ['chat_id' => '777', 'label' => 'Approved inbox'])->assertOk();
        $workflow = $this->createWorkflow($org, 'Telegram request approval');
        foreach (['telegram.message', 'telegram.notification', 'telegram.send_message'] as $op) {
            $this->postJson("$base/$id/grant", ['workflow_id' => $workflow, 'operation' => $op, 'chat_id' => '777'])->assertOk();
        }
        $trigger = (string) Str::uuid();
        $filter = (string) Str::uuid();
        $approval = (string) Str::uuid();
        $send = (string) Str::uuid();
        $end = (string) Str::uuid();
        $rejected = (string) Str::uuid();
        $graph = ['nodes' => [
            WorkflowGraphs::node($trigger, 'telegram_trigger', 0, 0, ['label' => 'Telegram Trigger', 'connection_id' => $id, 'destination' => '777', 'input_mapping' => ['note' => 'text']]),
            WorkflowGraphs::node($filter, 'telegram_filter', 100, 0, ['label' => 'Request command', 'filter_field' => 'command', 'match_mode' => 'equals', 'match_value' => '/request']),
            WorkflowGraphs::node($approval, 'approval', 200, 0, ['label' => 'Manager approval', 'assignee_roles' => ['manager']]),
            WorkflowGraphs::node($send, 'telegram_notification', 300, 0, ['label' => 'Telegram Notification', 'connection_id' => $id, 'destination' => '777', 'text' => 'Request {{request.uuid}} {{approval.decision}}', 'allowed_field_ids' => []]),
            WorkflowGraphs::node($end, 'end', 400, 0, ['label' => 'Completed', 'result' => 'completed']),
            WorkflowGraphs::node($rejected, 'end', 400, 200, ['label' => 'Rejected', 'result' => 'rejected']),
        ], 'edges' => [WorkflowGraphs::edge($trigger, $filter, 'out'), WorkflowGraphs::edge($filter, $approval, 'matched'), WorkflowGraphs::edge($filter, $rejected, 'unmatched'), WorkflowGraphs::edge($approval, $send, 'approved'), WorkflowGraphs::edge($approval, $rejected, 'rejected'), WorkflowGraphs::edge($send, $end, 'out')]];
        $definition = ['graph_definition' => $graph, 'form_definition' => ['fields' => [ExecutionGraphs::field('note', 'textarea', ['validation' => ['max_length' => 1000]])]]];
        $this->putJson("/api/v1/organizations/{$org->uuid}/workflows/$workflow/draft", ['revision' => 1, ...$definition])->assertOk();
        $this->postJson("/api/v1/organizations/{$org->uuid}/workflows/$workflow/publish", ['revision' => 2])->assertOk();

        return [$org, $admin, $id, $base, $employee, $manager, $workflow, $definition];
    }

    private function activate(string $id, int $update = 100): WorkflowInstance
    {
        $this->webhook($id, $this->update($update))->assertOk()->assertJsonPath('data.accepted', true);
        $activation = DB::table('integration_trigger_activations')->where('status', 'pending')->first();
        $this->assertNotNull($activation);
        app(TelegramActivation::class)->process($activation->id);
        $row = DB::table('integration_trigger_activations')->where('id', $activation->id)->first();
        $this->assertSame('done', $row->status);

        return WorkflowInstance::findOrFail($row->workflow_instance_id);
    }

    public function test_catalog_audit_timestamps_include_the_utc_offset(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28T04:14:20+00:00'));
        [, , , $base] = $this->fixture();
        $audit = $this->getJson($base)->assertOk()->json('data.items.0.audit');
        $this->assertNotEmpty($audit);
        foreach ($audit as $event) {
            $this->assertSame('2026-10-28T04:14:20+00:00', $event['created_at']);
        }
    }

    public function test_end_to_end_linked_message_manager_approval_and_real_adapter_notification_with_faked_transport(): void
    {
        [$org,$admin,$id,$base,$employee,$manager,$workflow] = $this->publishedFixture();
        $instance = $this->activate($id);
        $this->assertSame('awaiting_approval', $instance->status->value);
        $this->assertSame($employee->id, $instance->submitted_by);
        $this->loginAs($manager);
        $this->decide($org, $instance->tasks()->first()->uuid, 'approved')->assertOk()->assertJsonPath('data.status', 'completed');
        $delivery = DB::table('integration_action_outbox')->first();
        $this->assertNotNull($delivery);
        $this->assertStringNotContainsString('Request ', $delivery->ciphertext);
        app(TelegramDelivery::class)->process($delivery->id);
        app(TelegramDelivery::class)->process($delivery->id);
        $this->assertSame('sent', DB::table('integration_action_outbox')->value('status'));
        $this->api->http->assertSentCount(4);
        $this->api->http->assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === '777' && str_contains($r['text'], $instance->uuid) && str_contains($r['text'], 'approved') && ! str_contains($r['text'], 'private content') && ! isset($r['parse_mode']));
        $types = $instance->events()->pluck('event_type')->map(fn ($e) => $e->value)->all();
        foreach (['telegram_trigger_activated', 'telegram_filter_evaluated', 'task_approved', 'telegram_queued', 'telegram_sent'] as $type) {
            $this->assertContains($type, $types);
        }
        $this->webhook($id, $this->update())->assertOk()->assertJsonPath('data.duplicate', true);
        $this->assertSame(1, WorkflowInstance::count());
        $this->assertSame(1, DB::table('integration_trigger_activations')->count());
        $this->assertStringNotContainsString('private content', json_encode($instance->events()->get()));
        $this->assertStringNotContainsString($this->token, json_encode(DB::table('integration_audit_events')->get()));
    }

    public function test_forged_webhooks_unknown_chats_and_unlinked_senders_never_activate(): void
    {
        [$org,$admin,$id,$base] = $this->fixture();
        $this->webhook($id, $this->update(), 'forged')->assertForbidden();
        $this->webhook($id, $this->update())->assertOk()->assertJsonPath('data.accepted', false);
        $this->postJson("$base/$id/approve-destination", ['chat_id' => '777', 'label' => 'Inbox'])->assertOk();
        $this->webhook($id, $this->update())->assertOk()->assertJsonPath('data.accepted', false);
        $this->assertSame(0, DB::table('telegram_inbound_events')->count());
        $this->assertSame(0, WorkflowInstance::count());
        $old = $this->update();
        $old['message']['date'] = now()->subDays(2)->timestamp;
        $this->webhook($id, $old)->assertOk()->assertJsonPath('data.accepted', false);
    }

    public function test_connection_mutations_require_session_step_up_and_admin_approval(): void
    {
        $org = $this->signInMember(OrganizationRole::Admin);
        $base = "/api/v1/organizations/{$org->uuid}/integrations/telegram";
        $this->postJson($base, ['name' => 'Bot', 'ownership' => 'organization', 'credentials' => ['bot_token' => $this->token]])->assertStatus(428);
        $this->confirm();
        $this->putJson("$base/policy", ['approved' => true])->assertOk();
        $id = $this->postJson($base, ['name' => 'Bot', 'ownership' => 'organization', 'credentials' => ['bot_token' => $this->token]])->assertCreated()->json('data.id');
        $this->postJson("$base/$id/configure-webhook")->assertForbidden();
        $employee = $this->addMember($org, OrganizationRole::Employee);
        $this->loginAs($employee);
        $this->confirm();
        $this->postJson("$base/$id/approve")->assertForbidden();
        $this->postJson("$base/$id/revoke")->assertNotFound();
        $this->withHeaders(['Origin' => 'https://attacker.invalid', 'Referer' => 'https://attacker.invalid/'])->postJson("$base/$id/test")->assertStatus(419);
    }

    public function test_no_tokens_or_http_instrumentation_leaks_and_one_bot_cannot_bind_to_two_tenants(): void
    {
        Event::fake([RequestSending::class, ResponseReceived::class]);
        [$org,$admin,$id,$base] = $this->fixture();
        $record = DB::table('credential_secrets')->where('connection_id', $id)->first();
        $this->assertStringNotContainsString($this->token, $record->ciphertext);
        $this->assertSame(['bot_token' => $this->token], app(CredentialVault::class)->decrypt($record, $id));
        $response = $this->getJson($base)->assertOk();
        $this->assertStringNotContainsString($this->token, $response->getContent());
        $this->assertStringNotContainsString('webhook_secret_hash', $response->getContent());
        Event::assertNotDispatched(RequestSending::class);
        Event::assertNotDispatched(ResponseReceived::class);
        $other = $this->signInMember(OrganizationRole::Admin);
        $this->confirm();
        $otherBase = "/api/v1/organizations/{$other->uuid}/integrations/telegram";
        $this->putJson("$otherBase/policy", ['approved' => true])->assertOk();
        $this->postJson($otherBase, ['name' => 'Second bot', 'ownership' => 'organization', 'credentials' => ['bot_token' => $this->token]])->assertStatus(409);
        $this->postJson("$otherBase/$id/approve")->assertNotFound();
        $this->assertSame(1, DB::table('telegram_bots')->count());
    }

    private function queuedFixture(): array
    {
        [$org,$admin,$id,$base,$employee,$manager] = $this->publishedFixture();
        $instance = $this->activate($id);
        $this->loginAs($manager);
        $this->decide($org, $instance->tasks()->first()->uuid, 'approved')->assertOk();

        return [$org, $admin, $id, $base, $employee, $manager, $instance, DB::table('integration_action_outbox')->first()];
    }

    public function test_rate_limit_retry_after_and_duplicate_jobs_are_safe(): void
    {
        [$org,$admin,$id,$base,$employee,$manager,$instance,$row] = $this->queuedFixture();
        $this->sendResponses = [Factory::response(['ok' => false, 'error_code' => 429, 'description' => 'untrusted private description', 'parameters' => ['retry_after' => 75]], 429)];
        app(TelegramDelivery::class)->process($row->id);
        $pending = DB::table('integration_action_outbox')->first();
        $this->assertSame('pending', $pending->status);
        $this->assertSame('rate_limited', $pending->error_code);
        $this->assertSame(1, $pending->attempt_count);
        app(TelegramDelivery::class)->process($row->id);
        $this->assertSame(1, DB::table('integration_action_outbox')->value('attempt_count'));
        $this->travel(76)->seconds();
        app(TelegramDelivery::class)->process($row->id);
        app(TelegramDelivery::class)->process($row->id);
        $this->assertSame('sent', DB::table('integration_action_outbox')->value('status'));
        $this->assertSame(2, DB::table('integration_action_outbox')->value('attempt_count'));
        $this->assertStringNotContainsString('untrusted private description', json_encode(DB::table('integration_audit_events')->get()));
    }

    public function test_ambiguous_timeouts_are_not_retried_without_explicit_duplicate_risk_confirmation(): void
    {
        [$org,$admin,$id,$base,$employee,$manager,$instance,$row] = $this->queuedFixture();
        $this->sendResponses = [new ConnectionException('secret URL must not escape')];
        app(TelegramDelivery::class)->process($row->id);
        $this->assertSame('uncertain', DB::table('integration_action_outbox')->value('status'));
        $this->travel(300)->seconds();
        app(TelegramDelivery::class)->recover();
        app(TelegramDelivery::class)->process($row->id);
        $this->assertSame(1, DB::table('integration_action_outbox')->value('attempt_count'));
        $this->loginAs($admin);
        $this->confirm();
        $this->postJson("$base/$id/retry-delivery", ['delivery_id' => $row->id])->assertUnprocessable();
        $this->postJson("$base/$id/retry-delivery", ['delivery_id' => $row->id, 'acknowledge_duplicate_risk' => true])->assertOk();
        app(TelegramDelivery::class)->process($row->id);
        $this->assertSame('sent', DB::table('integration_action_outbox')->value('status'));
    }

    public function test_bounded_retries_and_stale_processing_claim_recovery(): void
    {
        [$org,$admin,$id,$base,$employee,$manager,$instance,$row] = $this->queuedFixture();
        $this->sendResponses = array_fill(0, 5, Factory::response(['ok' => false], 503));
        for ($n = 0; $n < 5; $n++) {
            app(TelegramDelivery::class)->process($row->id);
            $this->travel(301)->seconds();
        }
        $this->assertSame('failed', DB::table('integration_action_outbox')->value('status'));
        $this->assertSame(5, DB::table('integration_action_outbox')->value('attempt_count'));
        DB::table('integration_action_outbox')->where('id', $row->id)->update(['status' => 'processing', 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->subMinute()]);
        app(TelegramDelivery::class)->recover();
        $this->assertSame('uncertain', DB::table('integration_action_outbox')->value('status'));
    }

    public function test_revocation_pauses_subscription_and_blocks_a_previously_received_event(): void
    {
        [$org,$admin,$id,$base] = $this->publishedFixture();
        $this->webhook($id, $this->update())->assertOk();
        $activation = DB::table('integration_trigger_activations')->first();
        app(TelegramConnections::class)->action($admin->id, $org->id, $id, 'revoke');
        app(TelegramActivation::class)->process($activation->id);
        $this->assertSame('paused', DB::table('integration_trigger_subscriptions')->value('status'));
        $this->assertSame('blocked', DB::table('integration_trigger_activations')->value('status'));
        $this->assertSame(0, WorkflowInstance::count());
        $this->webhook($id, $this->update(101))->assertForbidden();
        $this->assertSame(0, DB::table('credential_secrets')->count());
    }

    public function test_revoked_membership_destination_grant_and_approval_are_rechecked_before_outbound_attempts(): void
    {
        [$org,$admin,$id,$base,$employee,$manager,$instance,$row] = $this->queuedFixture();
        foreach (['member', 'chat', 'grant', 'approval', 'demoted_admin'] as $condition) {
            DB::beginTransaction();
            if ($condition === 'member') {
                DB::table('organization_memberships')->where('user_id', $employee->id)->update(['revoked_at' => now()]);
            }
            if ($condition === 'chat') {
                DB::table('telegram_destinations')->where('connection_id', $id)->update(['approved_by' => null]);
            }
            if ($condition === 'grant') {
                DB::table('workflow_connection_grants')->where('connection_id', $id)->where('operation', 'telegram.notification')->delete();
            }
            if ($condition === 'approval') {
                DB::table('integration_approvals')->where('provider_id', 'telegram')->update(['approved' => false]);
            }
            if ($condition === 'demoted_admin') {
                DB::table('organization_memberships')->where('user_id', $admin->id)->update(['role' => 'employee']);
            }
            app(TelegramDelivery::class)->process($row->id);
            $this->assertSame('cancelled', DB::table('integration_action_outbox')->value('status'), $condition);
            DB::rollBack();
        }
        $this->api->http->assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage'));
    }

    public function test_rotation_does_not_allow_queued_work_to_use_new_credentials_or_auto_resume_triggers(): void
    {
        [$org,$admin,$id,$base,$employee,$manager,$instance,$row] = $this->queuedFixture();
        $this->loginAs($admin);
        $this->confirm();
        $this->postJson("$base/$id/rotate", ['credentials' => ['bot_token' => '123456:'.str_repeat('Replacement_', 4)]])->assertOk();
        $this->assertSame('cancelled', DB::table('integration_action_outbox')->value('status'));
        $this->assertSame('paused', DB::table('integration_trigger_subscriptions')->value('status'));
        $this->assertNull(DB::table('telegram_bots')->value('approved_by'));
        app(TelegramDelivery::class)->process($row->id);
        $this->api->http->assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage'));
    }

    public function test_mapping_filter_and_subscription_are_pinned_and_manual_start_is_rejected(): void
    {
        [$org,$admin,$id,$base,$employee,$manager,$workflow,$definition] = $this->publishedFixture();
        $first = WorkflowVersion::query()->firstOrFail();
        $this->webhook($id, $this->update())->assertOk();
        $a = DB::table('integration_trigger_activations')->first();
        $w = Workflow::query()->where('uuid', $workflow)->firstOrFail();
        $new = WorkflowVersion::factory()->create(['workflow_id' => $w->id, 'version_number' => 2, 'node_registry_version' => 3, 'graph_definition' => $first->graph_definition, 'form_definition' => $first->form_definition]);
        $w->forceFill(['current_published_version_id' => $new->id])->save();
        app(TelegramActivation::class)->process($a->id);
        $instance = WorkflowInstance::firstOrFail();
        $this->assertSame($first->id, $instance->workflow_version_id);
        $this->assertSame('/request synthetic private content', $instance->submitted_data['note']);
        $this->startInstance($org, $workflow)->assertUnprocessable();
        $this->webhook($id, $this->update(101, '/other content'))->assertOk();
        $second = DB::table('integration_trigger_activations')->where('status', 'pending')->first();
        app(TelegramActivation::class)->process($second->id);
        $other = WorkflowInstance::whereKey(DB::table('integration_trigger_activations')->where('id', $second->id)->value('workflow_instance_id'))->firstOrFail();
        $this->assertSame('rejected', $other->status->value);
    }

    public function test_link_requires_private_challenge_and_flowmv_confirmation_and_cannot_create_approval_actions(): void
    {
        [$org,$admin,$id,$base] = $this->fixture();
        $member = $this->addMember($org, OrganizationRole::Employee);
        $this->loginAs($member);
        $this->confirm();
        $command = $this->postJson("$base/$id/identity/start")->assertOk()->json('data.command');
        $u = $this->update(1, $command);
        $u['message']['chat'] = ['id' => -100777, 'type' => 'supergroup'];
        $this->webhook($id, $u)->assertOk();
        $this->assertNull(DB::table('telegram_identity_links')->value('telegram_user_id'));
        $this->webhook($id, $this->update(2, $command))->assertOk();
        $this->assertFalse((bool) DB::table('telegram_identity_links')->value('confirmed'));
        $this->postJson("$base/$id/identity/confirm")->assertOk();
        $this->webhook($id, $this->update(3, '/approve request-id'))->assertOk()->assertJsonPath('data.accepted', false);
        $this->assertSame(0, WorkflowInstance::count());
    }

    public function test_encrypted_event_and_outbox_payloads_follow_vault_key_rotation(): void
    {
        [$org,$admin,$id,$base,$employee,$manager,$instance,$row] = $this->queuedFixture();
        $this->assertStringNotContainsString('synthetic private content', DB::table('telegram_inbound_events')->value('ciphertext'));
        config(['integrations.keys.v2' => base64_encode(random_bytes(32)), 'integrations.active_key' => 'v2']);
        $this->artisan('integrations:rotate-keys')->assertSuccessful();
        $this->assertSame('v2', DB::table('telegram_inbound_events')->value('key_version'));
        $this->assertSame('v2', DB::table('integration_action_outbox')->value('key_version'));
        config(['integrations.keys.v1' => null]);
        app(TelegramDelivery::class)->process($row->id);
        $this->assertSame('sent', DB::table('integration_action_outbox')->value('status'));
    }

    public function test_telegram_schemas_reject_credentials_urls_arbitrary_variables_and_old_registry_types(): void
    {
        $node = WorkflowGraphs::node((string) Str::uuid(), 'telegram_send_message', 0, 0, ['label' => 'Send', 'connection_id' => (string) Str::uuid(), 'destination' => 'https://attacker.invalid', 'text' => '{{secrets.bot_token}}', 'allowed_field_ids' => [], 'bot_token' => $this->token]);
        $result = app(WorkflowDefinitionNormalizer::class)->normalize(['nodes' => [$node], 'edges' => []], ['fields' => []]);
        $this->assertFalse($result->ok());
        $this->assertStringNotContainsString($this->token, json_encode($result->graph));
        $registry = app(NodeRegistry::class);
        $this->assertFalse($registry->allows(1, 'telegram_trigger'));
        $this->assertFalse($registry->allows(2, 'telegram_send_message'));
        $this->assertTrue($registry->allows(3, 'telegram_filter'));
    }

    public function test_expired_unconfirmed_and_revoked_links_cannot_activate(): void
    {
        [$org,$admin,$id,$base,$employee] = $this->publishedFixture();
        DB::table('telegram_identity_links')->where('connection_id', $id)->update(['confirmed' => false, 'expires_at' => now()->subMinute()]);
        $this->webhook($id, $this->update())->assertOk()->assertJsonPath('data.accepted', false);
        $this->loginAs($employee);
        $this->confirm();
        $this->postJson("$base/$id/identity/confirm")->assertStatus(409);
        $this->postJson("$base/$id/identity/unlink")->assertOk();
        $this->webhook($id, $this->update(101))->assertOk()->assertJsonPath('data.accepted', false);
        $this->assertSame(0, WorkflowInstance::count());
    }

    public function test_raw_request_and_webhook_headers_are_redacted_before_request_handled_instrumentation(): void
    {
        [$org,$admin,$id,$base] = $this->fixture();
        $observed = [];
        Event::listen(RequestHandled::class, function ($event) use (&$observed) {
            $observed[] = ['body' => $event->request->getContent(), 'header' => $event->request->header('X-Telegram-Bot-Api-Secret-Token'), 'attributes' => json_encode($event->request->attributes->all())];
        });
        $this->webhook($id, $this->update(100, 'private-raw-message-marker'))->assertOk();
        $this->postJson("$base/$id/rotate", ['credentials' => ['bot_token' => $this->token]])->assertOk();
        $encoded = json_encode($observed);
        foreach (['private-raw-message-marker', $this->webhookSecret, $this->token] as $marker) {
            $this->assertStringNotContainsString($marker, $encoded);
        }
    }

    public function test_simulated_transport_and_event_route_refuse_non_qa_environments(): void
    {
        [$org,$admin,$id,$base] = $this->fixture();
        $this->postJson("$base/$id/simulation", ['sender_id' => '777', 'chat_id' => '777', 'simulation_text' => '/request'])->assertStatus(503);
        config(['integrations.telegram_simulated' => true]);
        try {
            app(TelegramQaTransport::class)->assertLocal();
            $this->fail('Simulation admitted a non-QA environment.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
    }

    public function test_linked_bot_messages_cannot_approve_an_internal_task(): void
    {
        [$org,$admin,$id,$base,$employee] = $this->publishedFixture();
        $instance = $this->activate($id);
        $this->webhook($id, $this->update(101, '/approve forged task decision'))->assertOk();
        $activation = DB::table('integration_trigger_activations')->where('status', 'pending')->first();
        app(TelegramActivation::class)->process($activation->id);
        $this->assertSame('awaiting_approval', $instance->fresh()->status->value);
        $this->assertSame('open', $instance->tasks()->first()->status->value);
        $this->loginAs($employee);
        $this->decide($org, $instance->tasks()->first()->uuid, 'approved')->assertForbidden();
        $this->assertSame(0, DB::table('integration_action_outbox')->count());
    }
}
