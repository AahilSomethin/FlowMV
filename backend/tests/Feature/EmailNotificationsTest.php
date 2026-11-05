<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Jobs\FetchEmailMessage;
use App\Jobs\SyncEmailMailbox;
use App\Services\Integrations\CredentialVault;
use App\Services\Integrations\EmailHttp;
use App\Services\Integrations\EmailSync;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Workflows\InteractsWithExecution;
use Tests\TestCase;

class EmailNotificationsTest extends TestCase
{
    use InteractsWithExecution { setUp as protected spaSetUp; }
    use RefreshDatabase;

    private EmailHttp $http;

    private string $key = '';

    private string $publicKey;

    private bool $historyGone = false;

    private bool $unsafeDelta = false;

    protected function setUp(): void
    {
        $this->spaSetUp();
        Queue::fake();
        config(['email_integrations.enabled' => true, 'integrations.keys.v1' => base64_encode(random_bytes(32)), 'email_integrations.gmail.topic' => 'projects/qa-project/topics/qa-mail', 'email_integrations.gmail.subscription' => 'projects/qa-project/subscriptions/qa-mail', 'email_integrations.gmail.push_audience' => 'https://flowmv.example.test/api/v1/integration-webhooks/email/gmail', 'email_integrations.gmail.push_service_account' => 'qa-push@qa-project.iam.gserviceaccount.com', 'email_integrations.webhook_origin' => 'https://flowmv.example.test']);
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => __DIR__.'/../Fixtures/openssl-test.cnf'];
        $key = openssl_pkey_new($config);
        openssl_pkey_export($key, $this->key, null, $config);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
        $this->http = app(EmailHttp::class);
        $this->http->http->preventStrayRequests();
        $this->http->http->fake(function ($r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($path === '/oauth2/v1/certs') {
                return Factory::response(['qa-kid' => $this->publicKey]);
            }
            if (str_ends_with($path, '/watch')) {
                return Factory::response(['historyId' => '150', 'expiration' => (string) now()->addDays(7)->getTimestampMs()]);
            }
            if (str_ends_with($path, '/profile')) {
                return Factory::response(['historyId' => '150']);
            }
            if (str_ends_with($path, '/history')) {
                return $this->historyGone ? Factory::response([], 404) : Factory::response(['historyId' => '160', 'history' => [['messagesAdded' => [['message' => ['id' => 'message-one']], ['message' => ['id' => 'message-one']]]]]]);
            }
            if (str_ends_with($path, '/messages')) {
                return Factory::response(['messages' => [['id' => 'message-one']]]);
            }
            if (str_contains($path, '/subscriptions')) {
                return Factory::response(['id' => 'qa-subscription', 'expirationDateTime' => now()->addDay()->toIso8601String()]);
            }
            if (str_ends_with($path, '/delta')) {
                return Factory::response(['value' => [['id' => 'message-one', 'receivedDateTime' => now()->toIso8601String()]], '@odata.deltaLink' => $this->unsafeDelta ? 'https://evil.example/steal' : 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=opaque']);
            }

            return Factory::response([], 503);
        });
    }

    private function fixture(string $provider = 'gmail'): array
    {
        $org = $this->signInMember(OrganizationRole::Admin);
        $id = (string) Str::uuid();
        DB::table('integration_approvals')->insert(['organization_id' => $org->id, 'provider_id' => $provider, 'approved' => true]);
        DB::table('connections')->insert(['id' => $id, 'organization_id' => $org->id, 'provider_id' => $provider, 'name' => 'Synthetic mailbox', 'credential_type' => 'oauth', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('email_mailboxes')->insert(['connection_id' => $id, 'address' => 'qa-mailbox@example.com', 'account_id' => 'qa-account', 'active_identity' => hash('sha256', $id), 'capabilities' => json_encode(['read', 'send']), 'approved_by' => $org->created_by, 'token_expires_at' => now()->addHour(), 'connected_at' => now()->subMinute(), 'health' => 'ready']);
        $vault = app(CredentialVault::class);
        DB::table('credential_secrets')->insert(['connection_id' => $id, ...$vault->encrypt(['access_token' => 'synthetic-only-access', 'refresh_token' => 'synthetic-only-refresh'], $id), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('email_sync_state')->insert(['connection_id' => $id, ...$vault->encrypt(['cursor' => $provider === 'gmail' ? '100' : null, 'next' => null, 'subscription_secret' => 'synthetic-client-state'], 'email-sync:'.$id), 'subscription_id' => $provider === 'outlook' ? 'qa-subscription' : null, 'subscription_secret_hash' => hash('sha256', 'synthetic-client-state'), 'watch_expires_at' => now()->addDays(2), 'available_at' => now(), 'requested' => false, 'created_at' => now(), 'updated_at' => now()]);

        return [$org, $id];
    }

    private function jwt(array $overrides = []): string
    {
        $b64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $header = $b64(json_encode(['alg' => 'RS256', 'kid' => 'qa-kid']));
        $claims = $b64(json_encode([...['iss' => 'https://accounts.google.com', 'aud' => config('email_integrations.gmail.push_audience'), 'email' => config('email_integrations.gmail.push_service_account'), 'email_verified' => true, 'iat' => now()->timestamp, 'exp' => now()->addHour()->timestamp], ...$overrides]));
        openssl_sign($header.'.'.$claims, $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $header.'.'.$claims.'.'.$b64($signature);
    }

    private function googleEvent(string $id = 'push-one', string $history = '140'): array
    {
        return ['subscription' => config('email_integrations.gmail.subscription'), 'message' => ['messageId' => $id, 'data' => base64_encode(json_encode(['emailAddress' => 'qa-mailbox@example.com', 'historyId' => $history]))]];
    }

    public function test_authenticated_google_push_is_deduplicated_without_trusting_history_order(): void
    {
        [,$id] = $this->fixture();
        $headers = ['Authorization' => 'Bearer '.$this->jwt()];
        $this->postJson('/api/v1/integration-webhooks/email/gmail', $this->googleEvent(), $headers)->assertStatus(202);
        $this->postJson('/api/v1/integration-webhooks/email/gmail', $this->googleEvent(), $headers)->assertStatus(202);
        $this->postJson('/api/v1/integration-webhooks/email/gmail', $this->googleEvent('push-old', '110'), $headers)->assertStatus(202);
        $this->assertDatabaseCount('email_notification_receipts', 2);
        Queue::assertPushed(SyncEmailMailbox::class, 2);
        $state = app(CredentialVault::class)->decrypt(DB::table('email_sync_state')->where('connection_id', $id)->first(), 'email-sync:'.$id);
        $this->assertSame('100', $state['cursor']);
        $this->assertDatabaseCount('workflow_instances', 0);
    }

    public function test_google_push_rejects_forged_claims_signatures_and_wrong_subscription(): void
    {
        $this->fixture();
        foreach ([['aud' => 'https://evil.example'], ['email' => 'attacker@example.com'], ['email_verified' => false], ['exp' => now()->subMinute()->timestamp], ['iss' => 'https://evil.example']] as $claims) {
            $this->postJson('/api/v1/integration-webhooks/email/gmail', $this->googleEvent(), ['Authorization' => 'Bearer '.$this->jwt($claims)])->assertForbidden();
        }
        $jwt = $this->jwt();
        $parts = explode('.', $jwt);
        $parts[2] = rtrim(strtr(base64_encode(random_bytes(256)), '+/', '-_'), '=');
        $this->postJson('/api/v1/integration-webhooks/email/gmail', $this->googleEvent(), ['Authorization' => 'Bearer '.implode('.', $parts)])->assertForbidden();
        $event = $this->googleEvent();
        $event['subscription'] = 'projects/other/subscriptions/other';
        $this->postJson('/api/v1/integration-webhooks/email/gmail', $event, ['Authorization' => 'Bearer '.$jwt])->assertForbidden();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('email_notification_receipts', 0);
    }

    public function test_graph_validation_is_plain_text_and_does_not_authenticate_events(): void
    {
        $this->post('/api/v1/integration-webhooks/email/outlook?validationToken=opaque%20challenge')->assertOk()->assertSee('opaque challenge')->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->postJson('/api/v1/integration-webhooks/email/outlook', ['value' => [['subscriptionId' => 'qa-subscription', 'clientState' => 'forged']]])->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_graph_client_state_and_lifecycle_recovery_require_current_connection_authority(): void
    {
        [,$id] = $this->fixture('outlook');
        $event = ['value' => [['subscriptionId' => 'qa-subscription', 'clientState' => 'synthetic-client-state', 'lifecycleEvent' => 'subscriptionRemoved']]];
        $this->postJson('/api/v1/integration-webhooks/email/outlook', $event)->assertStatus(202);
        $this->assertDatabaseHas('email_sync_state', ['connection_id' => $id, 'requested' => true, 'subscription_id' => null]);
        app(EmailSync::class)->process($id);
        $this->assertDatabaseHas('email_sync_state', ['connection_id' => $id, 'subscription_id' => 'qa-subscription', 'error_code' => null]);
        DB::table('connections')->where('id', $id)->update(['status' => 'revoked']);
        $state = DB::table('email_sync_state')->where('connection_id', $id)->first();
        $secret = app(CredentialVault::class)->decrypt($state, 'email-sync:'.$id)['subscription_secret'];
        $event['value'][0]['clientState'] = $secret;
        $event['value'][0]['lifecycleEvent'] = 'missed';
        $this->postJson('/api/v1/integration-webhooks/email/outlook', $event)->assertForbidden();
    }

    public function test_history_sync_checkpoints_only_after_durable_unique_fetch_work(): void
    {
        [,$id] = $this->fixture();
        app(EmailSync::class)->process($id);
        $this->assertDatabaseCount('email_fetch_queue', 1);
        Queue::assertPushed(FetchEmailMessage::class, 1);
        $row = DB::table('email_sync_state')->where('connection_id', $id)->first();
        $this->assertSame('160', app(CredentialVault::class)->decrypt($row, 'email-sync:'.$id)['cursor']);
        $this->assertStringNotContainsString('message-one', DB::table('email_fetch_queue')->value('ciphertext'));
        DB::table('email_sync_state')->where('connection_id', $id)->update(['available_at' => now()]);
        app(EmailSync::class)->process($id);
        $this->assertDatabaseCount('email_fetch_queue', 1);
    }

    public function test_expired_gmail_history_reconciles_and_renews_watch_without_overwriting_cursor(): void
    {
        [,$id] = $this->fixture();
        $this->historyGone = true;
        DB::table('email_sync_state')->where('connection_id', $id)->update(['watch_expires_at' => now()->subMinute()]);
        app(EmailSync::class)->process($id);
        $row = DB::table('email_sync_state')->where('connection_id', $id)->first();
        $this->assertSame('history_reconciliation', $row->error_code);
        $this->assertNull(app(CredentialVault::class)->decrypt($row, 'email-sync:'.$id)['cursor']);
        $this->historyGone = false;
        app(EmailSync::class)->process($id);
        $this->assertDatabaseCount('email_fetch_queue', 1);
        $this->assertSame('150', app(CredentialVault::class)->decrypt(DB::table('email_sync_state')->where('connection_id', $id)->first(), 'email-sync:'.$id)['cursor']);
    }

    public function test_graph_cursor_cannot_redirect_mailbox_access_to_another_host(): void
    {
        [,$id] = $this->fixture('outlook');
        $this->unsafeDelta = true;
        app(EmailSync::class)->process($id);
        $this->assertDatabaseCount('email_fetch_queue', 0);
        $row = DB::table('email_sync_state')->where('connection_id', $id)->first();
        $this->assertNull(app(CredentialVault::class)->decrypt($row, 'email-sync:'.$id)['next']);
        $this->http->http->assertNotSent(fn ($r) => str_contains($r->url(), 'evil.example'));
    }

    public function test_webhook_request_instrumentation_never_sees_bearer_or_raw_notification(): void
    {
        $this->fixture();
        $seen = [];
        Event::listen(RequestHandled::class, function ($e) use (&$seen) {
            if ($e->request->is('api/v1/integration-webhooks/email/*')) {
                $seen = [$e->request->getContent(), $e->request->header('Authorization'), json_encode($e->request->attributes->all())];
            }
        });
        $jwt = $this->jwt();
        $event = $this->googleEvent('private-push-id');
        $this->postJson('/api/v1/integration-webhooks/email/gmail', $event, ['Authorization' => 'Bearer '.$jwt])->assertStatus(202);
        foreach ($seen as $item) {
            $this->assertStringNotContainsString($jwt, (string) $item);
            $this->assertStringNotContainsString('private-push-id', (string) $item);
        }
        $this->assertSame(['redacted' => true], json_decode($seen[0], true));
    }
}
