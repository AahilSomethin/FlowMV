<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Jobs\ProcessTelegramApproval;
use App\Models\WorkflowInstance;
use App\Services\Integrations\CredentialVault;
use App\Services\Integrations\TelegramApi;
use App\Services\Integrations\TelegramDecisions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Workflows\InteractsWithExecution;
use Tests\Support\WorkflowGraphs;
use Tests\TestCase;

class TelegramDecisionsTest extends TestCase
{
    use InteractsWithExecution { setUp as protected spaSetUp; }
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->spaSetUp();
        config(['integrations.telegram_enabled' => true, 'integrations.keys.v1' => base64_encode(random_bytes(32))]);
    }

    private function fixture(bool $deliver = true): array
    {
        Queue::fake();
        $org = $this->signInMember(OrganizationRole::Admin);
        $manager = $this->addMember($org, OrganizationRole::Manager);
        $employee = $this->addMember($org, OrganizationRole::Employee);
        $id = (string) Str::uuid();
        DB::table('integration_approvals')->insert(['organization_id' => $org->id, 'provider_id' => 'telegram', 'approved' => true]);
        DB::table('connections')->insert(['id' => $id, 'organization_id' => $org->id, 'provider_id' => 'telegram', 'name' => 'Decision QA bot', 'credential_type' => 'bot_token', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('credential_secrets')->insert(['connection_id' => $id, ...app(CredentialVault::class)->encrypt(['bot_token' => '123456:'.str_repeat('Synthetic_', 4)], $id), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('telegram_bots')->insert(['connection_id' => $id, 'bot_id' => '123456', 'active_bot_id' => '123456', 'username' => 'decision_qa_bot', 'approved_by' => $org->created_by, 'webhook_configured' => true, 'webhook_secret_hash' => hash('sha256', 'synthetic-decision-secret')]);
        DB::table('telegram_destinations')->insert(['connection_id' => $id, 'chat_id' => '888', 'chat_type' => 'private', 'label' => 'Manager QA', 'approved_by' => $org->created_by]);
        DB::table('telegram_identity_links')->insert(['connection_id' => $id, 'user_id' => $manager->id, 'telegram_user_id' => '888', 'confirmed' => true]);
        $workflow = $this->createWorkflow($org, 'Interactive approval');
        $w = DB::table('workflows')->where('uuid', $workflow)->first();
        DB::table('workflow_connection_grants')->insert(['organization_id' => $org->id, 'connection_id' => $id, 'workflow_id' => $w->id, 'operation' => 'telegram.approval', 'authorized_by' => $org->created_by]);
        $definition = WorkflowGraphs::publishable();
        foreach ($definition['graph_definition']['nodes'] as &$node) {
            if ($node['type'] === 'approval') {
                $node['data']['telegram_approval'] = ['connection_id' => $id, 'destination' => '888'];
            }
        }
        unset($node);
        $this->putJson("/api/v1/organizations/{$org->uuid}/workflows/$workflow/draft", ['revision' => 1, ...$definition])->assertOk();
        $this->postJson("/api/v1/organizations/{$org->uuid}/workflows/$workflow/publish", ['revision' => 2])->assertOk();
        $api = app(TelegramApi::class);
        $api->http->preventStrayRequests();
        $api->http->fake([
            'https://api.telegram.org/bot*/sendMessage' => Factory::response(['ok' => true, 'result' => ['message_id' => 900]]),
            'https://api.telegram.org/bot*/answerCallbackQuery' => Factory::response(['ok' => true, 'result' => true]),
        ]);
        $this->signIn($employee);
        $uuid = $this->startInstance($org, $workflow)->assertCreated()->json('data.uuid');
        $instance = WorkflowInstance::where('uuid', $uuid)->firstOrFail();
        $delivery = DB::table('telegram_approval_deliveries')->first();
        $this->assertNotNull($delivery);
        if ($deliver) {
            app(TelegramDecisions::class)->deliver($delivery->id);
            $this->assertDatabaseHas('telegram_approval_deliveries', ['id' => $delivery->id, 'status' => 'accepted']);
        }
        $payload = app(CredentialVault::class)->decrypt($delivery, 'telegram-approval:'.$delivery->id);

        return [$org, $id, $manager, $employee, $instance, $delivery, $payload];
    }

    private function callbackWebhook(string $id, string $data, int $update = 100, int $sender = 888, int $chat = 888, int $message = 900): TestResponse
    {
        return $this->postJson('/api/v1/integration-webhooks/telegram/'.$id, ['update_id' => $update, 'callback_query' => ['id' => 'synthetic-query-'.$update, 'data' => $data, 'from' => ['id' => $sender, 'is_bot' => false], 'message' => ['message_id' => $message, 'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => 123456, 'is_bot' => true]]]], ['X-Telegram-Bot-Api-Secret-Token' => 'synthetic-decision-secret']);
    }

    public static function decisions(): array
    {
        return ['approve' => [0, 'approved', 'completed'], 'reject into revision' => [1, 'rejected', 'awaiting_revision']];
    }

    #[DataProvider('decisions')]
    public function test_buttons_invoke_existing_decision_service_once_and_preserve_snapshots(int $index, string $choice, string $status): void
    {
        [, $id, $manager, , $instance, $delivery, $payload] = $this->fixture();
        $data = $payload['keyboard'][0][$index]['callback_data'];
        $this->assertLessThanOrEqual(64, strlen($data));
        $this->assertStringNotContainsString($instance->uuid, $data);
        $this->assertStringNotContainsString('Synthetic', $data);
        $this->assertSame('Request revision in FlowMV', $payload['keyboard'][2][0]['text']);
        $this->callbackWebhook($id, $data)->assertOk();
        $receipt = DB::table('telegram_decision_callbacks')->first();
        app(TelegramDecisions::class)->process($receipt->id);
        app(TelegramDecisions::class)->process($receipt->id);
        $this->assertDatabaseHas('workflow_tasks', ['id' => $delivery->workflow_task_id, 'decision' => $choice, 'completed_by' => $manager->id]);
        $this->assertSame($status, $instance->fresh()->status->value);
        $this->assertSame(1, DB::table('workflow_submission_snapshots')->where('workflow_task_id', $delivery->workflow_task_id)->count());
        $this->assertSame(1, DB::table('integration_audit_events')->where('event', 'telegram.decision.accepted')->count());
        $this->callbackWebhook($id, $data)->assertOk();
        $this->assertDatabaseCount('telegram_decision_callbacks', 1);
        $this->callbackWebhook($id, $payload['keyboard'][0][1 - $index]['callback_data'], 101)->assertOk();
        $second = DB::table('telegram_decision_callbacks')->where('update_id', 101)->first();
        app(TelegramDecisions::class)->process($second->id);
        $this->assertDatabaseHas('telegram_decision_callbacks', ['id' => $second->id, 'status' => 'rejected']);
        Queue::assertPushed(ProcessTelegramApproval::class);
    }

    public static function attacks(): array
    {
        return array_combine(['expired', 'sender', 'chat', 'message', 'demoted', 'revoked', 'unlinked', 'grant'], array_map(fn ($v) => [$v], ['expired', 'sender', 'chat', 'message', 'demoted', 'revoked', 'unlinked', 'grant']));
    }

    #[DataProvider('attacks')]
    public function test_stale_or_unauthorized_callbacks_cannot_decide(string $attack): void
    {
        [$org, $id, $manager, , $instance, $delivery, $payload] = $this->fixture();
        if ($attack === 'expired') {
            DB::table('telegram_decision_capabilities')->update(['expires_at' => now()->subSecond()]);
        } elseif ($attack === 'demoted') {
            DB::table('organization_memberships')->where('organization_id', $org->id)->where('user_id', $manager->id)->update(['role' => 'employee']);
        } elseif ($attack === 'revoked') {
            DB::table('connections')->where('id', $id)->update(['status' => 'revoked']);
        } elseif ($attack === 'unlinked') {
            DB::table('telegram_identity_links')->where('connection_id', $id)->update(['confirmed' => false]);
        } elseif ($attack === 'grant') {
            DB::table('workflow_connection_grants')->where('connection_id', $id)->delete();
        }
        $response = $this->callbackWebhook($id, $payload['keyboard'][0][0]['callback_data'], sender: $attack === 'sender' ? 999 : 888, chat: $attack === 'chat' ? 999 : 888, message: $attack === 'message' ? 999 : 900);
        if ($attack === 'revoked') {
            $response->assertForbidden();
        } else {
            $response->assertOk();
            $receipt = DB::table('telegram_decision_callbacks')->first();
            app(TelegramDecisions::class)->process($receipt->id);
            $this->assertDatabaseHas('telegram_decision_callbacks', ['id' => $receipt->id, 'status' => 'rejected']);
        }
        $this->assertDatabaseHas('workflow_tasks', ['id' => $delivery->workflow_task_id, 'status' => 'open', 'decision' => null]);
        $this->assertSame('awaiting_approval', $instance->fresh()->status->value);
    }

    public function test_queued_approval_is_cancelled_if_connection_is_disabled_before_delivery(): void
    {
        [, $id, , , , $delivery] = $this->fixture(false);
        DB::table('connections')->where('id', $id)->update(['status' => 'disabled']);
        app(TelegramDecisions::class)->deliver($delivery->id);
        $this->assertDatabaseHas('telegram_approval_deliveries', ['id' => $delivery->id, 'status' => 'cancelled']);
        app(TelegramApi::class)->http->assertNothingSent();
    }

    public function test_ambiguous_approval_delivery_is_not_automatically_repeated(): void
    {
        [, , , , , $delivery] = $this->fixture(false);
        $api = app(TelegramApi::class);
        $attempts = 0;
        $api->http->fake(['https://api.telegram.org/bot*/sendMessage' => function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Synthetic timeout');
        }]);
        app(TelegramDecisions::class)->deliver($delivery->id);
        app(TelegramDecisions::class)->deliver($delivery->id);
        $this->assertDatabaseHas('telegram_approval_deliveries', ['id' => $delivery->id, 'status' => 'uncertain', 'attempt_count' => 1]);
        $this->assertSame(1, $attempts);
    }

    public function test_key_rotation_preserves_encrypted_buttons_and_pending_callback_bindings(): void
    {
        [, $id, , , $instance, $delivery, $payload] = $this->fixture();
        $this->callbackWebhook($id, $payload['keyboard'][0][0]['callback_data'])->assertOk();
        config(['integrations.active_key' => 'v2', 'integrations.keys.v2' => base64_encode(random_bytes(32))]);
        $this->artisan('integrations:rotate-keys')->assertSuccessful();
        $this->assertDatabaseHas('telegram_approval_deliveries', ['id' => $delivery->id, 'key_version' => 'v2']);
        $receipt = DB::table('telegram_decision_callbacks')->first();
        $this->assertSame('v2', $receipt->key_version);
        app(TelegramDecisions::class)->process($receipt->id);
        $this->assertSame('completed', $instance->fresh()->status->value);
    }

    public function test_even_a_linked_manager_identity_cannot_approve_its_own_submission(): void
    {
        [$org, $id, , $employee, , $delivery, $payload] = $this->fixture();
        DB::table('organization_memberships')->where('organization_id', $org->id)->where('user_id', $employee->id)->update(['role' => 'manager']);
        DB::table('telegram_identity_links')->where('connection_id', $id)->update(['user_id' => $employee->id]);
        DB::table('telegram_approval_deliveries')->where('id', $delivery->id)->update(['recipient_user_id' => $employee->id]);
        $this->callbackWebhook($id, $payload['keyboard'][0][0]['callback_data'])->assertOk();
        $receipt = DB::table('telegram_decision_callbacks')->first();
        app(TelegramDecisions::class)->process($receipt->id);
        $this->assertDatabaseHas('telegram_decision_callbacks', ['id' => $receipt->id, 'status' => 'rejected']);
        $this->assertDatabaseHas('workflow_tasks', ['id' => $delivery->workflow_task_id, 'status' => 'open', 'decision' => null]);
    }

    public function test_catalog_marks_expired_buttons_and_exposes_no_capability(): void
    {
        [$org, , $manager] = $this->fixture();
        $this->signIn($manager);
        $url = "/api/v1/organizations/{$org->uuid}/integrations/telegram";
        $response = $this->getJson($url)->assertOk()->assertJsonPath('data.items.0.approval_deliveries.0.can_decide', true);
        $this->assertStringNotContainsString('capability_hash', $response->getContent());
        $this->travel(601)->seconds();
        $this->getJson($url)->assertOk()->assertJsonPath('data.items.0.approval_deliveries.0.can_decide', false);
    }
}
