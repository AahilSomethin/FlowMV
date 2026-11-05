<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\User;
use App\Services\Integrations\CredentialVault;
use App\Services\Integrations\EmailHttp;
use App\Services\Integrations\EmailProviders;
use App\Services\Integrations\EmailTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Workflows\InteractsWithExecution;
use Tests\TestCase;

class EmailIntegrationTest extends TestCase
{
    use InteractsWithExecution { setUp as protected spaSetUp; }
    use RefreshDatabase;

    private EmailHttp $http;

    private int $exchanges = 0;

    protected function setUp(): void
    {
        $this->spaSetUp();
        config(['email_integrations.enabled' => true, 'integrations.keys.v1' => base64_encode(random_bytes(32))]);
        foreach (EmailProviders::IDS as $provider) {
            config(["email_integrations.$provider.client_id" => 'synthetic-client', "email_integrations.$provider.client_secret" => 'synthetic-client-secret', "email_integrations.$provider.redirect_uri" => 'https://flowmv.example.test/connections/email/callback/'.$provider]);
        }
        $this->http = app(EmailHttp::class);
        $this->http->http->preventStrayRequests();
        $this->http->http->fake(function ($r) {
            if (str_contains($r->url(), '/token')) {
                $this->exchanges++;
                $provider = str_contains($r->url(), 'microsoft') ? 'outlook' : 'gmail';

                return Factory::response(['access_token' => 'synthetic-access-'.str_repeat('x', 20), 'refresh_token' => 'synthetic-refresh-'.str_repeat('r', 20), 'expires_in' => 3600, 'token_type' => 'Bearer', 'scope' => implode(' ', app(EmailProviders::class)->scopes($provider, ['read', 'send']))]);
            }
            if (str_contains($r->url(), 'userinfo')) {
                return Factory::response(['sub' => 'synthetic-account', 'email' => 'qa-mailbox@example.com', 'email_verified' => true]);
            }
            if (str_ends_with($r->url(), '/me')) {
                return Factory::response(['id' => 'synthetic-account', 'mail' => 'qa-outlook@example.com']);
            }

            return Factory::response([]);
        });
    }

    private function login(User $user): void
    {
        $this->withCredentials(false);
        $this->signIn($user);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->withCredentials();
    }

    private function stepUp(): void
    {
        $this->postJson('/api/v1/integration-auth/confirm', ['password' => 'password'])->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->withCredentials();
    }

    private function fixture(string $provider = 'gmail', string $ownership = 'organization'): array
    {
        $org = $this->signInMember(OrganizationRole::Admin);
        $user = User::findOrFail($org->created_by);
        $base = "/api/v1/organizations/{$org->uuid}/integrations/email";
        DB::table('integration_policies')->insert(['organization_id' => $org->id, 'personal_connections_allowed' => true]);
        $this->stepUp();
        $this->putJson("$base/policy", ['provider' => $provider, 'approved' => true])->assertOk();
        $url = $this->postJson("$base/oauth/start", ['provider' => $provider, 'name' => 'QA mailbox', 'ownership' => $ownership, 'capabilities' => ['read', 'send']])->assertOk()->json('data.authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return [$org, $user, $base, $query];
    }

    private function finish(string $base, array $query, string $provider = 'gmail'): string
    {
        return $this->postJson("$base/oauth/finish", ['provider' => $provider, 'state' => $query['state'], 'code' => 'synthetic-once-only-code'])->assertOk()->json('data.id');
    }

    public function test_oauth_pkce_and_provider_identity_are_pinned_and_credentials_stay_encrypted(): void
    {
        foreach (['gmail', 'outlook'] as $provider) {
            [$org,$user,$base,$query] = $this->fixture($provider);
            $row = DB::table('email_oauth_attempts')->where('state_hash', hash('sha256', $query['state']))->first();
            $context = app(CredentialVault::class)->decrypt($row, 'email-oauth:'.$row->state_hash);
            $this->assertSame('S256', $query['code_challenge_method']);
            $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $context['verifier'], true)), '+/', '-_'), '='), $query['code_challenge']);
            $id = $this->finish($base, $query, $provider);
            $secret = DB::table('credential_secrets')->where('connection_id', $id)->first();
            $this->assertStringNotContainsString('synthetic-access', $secret->ciphertext);
            $this->assertStringNotContainsString('synthetic-refresh', $secret->ciphertext);
            $catalog = $this->getJson($base)->assertOk()->getContent();
            foreach (['synthetic-access', 'synthetic-refresh', $context['verifier'], 'client-secret', 'ciphertext'] as $private) {
                $this->assertStringNotContainsString($private, $catalog);
            }
            $this->assertSame($user->id, DB::table('email_oauth_attempts')->where('state_hash', $row->state_hash)->value('user_id'));
        }
    }

    public function test_callbacks_reject_wrong_provider_state_session_expiry_and_replay_before_token_exchange(): void
    {
        [,,$base,$query] = $this->fixture();
        $payload = ['provider' => 'outlook', 'state' => $query['state'], 'code' => 'synthetic-code'];
        $this->postJson("$base/oauth/finish", $payload)->assertForbidden();
        $payload['provider'] = 'gmail';
        $payload['state'] = str_repeat('a', 64);
        $this->postJson("$base/oauth/finish", $payload)->assertForbidden();
        $payload['state'] = $query['state'];
        DB::table('email_oauth_attempts')->update(['session_hash' => hash('sha256', 'other-session')]);
        $this->postJson("$base/oauth/finish", $payload)->assertForbidden();
        $this->assertSame(0, $this->exchanges);
    }

    public function test_consumed_or_expired_callback_cannot_be_reused(): void
    {
        [,,$base,$query] = $this->fixture();
        $this->finish($base, $query);
        $this->postJson("$base/oauth/finish", ['provider' => 'gmail', 'state' => $query['state'], 'code' => 'synthetic-once-only-code'])->assertForbidden();
        $this->assertSame(1, $this->exchanges);
        DB::table('email_oauth_attempts')->update(['consumed_at' => null, 'expires_at' => now()->subMinute()]);
        $this->postJson("$base/oauth/finish", ['provider' => 'gmail', 'state' => $query['state'], 'code' => 'synthetic-code'])->assertForbidden();
        $this->assertSame(1, $this->exchanges);
    }

    public function test_personal_connection_is_private_and_other_members_cannot_share_it(): void
    {
        [$org,,$base,$query] = $this->fixture('gmail', 'personal');
        $id = $this->finish($base, $query);
        $other = $this->addMember($org, OrganizationRole::Employee);
        $this->login($other);
        $this->stepUp();
        $this->getJson($base)->assertOk()->assertJsonCount(0, 'data.items');
        $this->postJson("$base/$id/revoke")->assertNotFound();
        $this->postJson("$base/$id/approve")->assertForbidden();
        $this->assertDatabaseHas('connections', ['id' => $id, 'status' => 'enabled']);
    }

    public function test_cross_tenant_callback_and_connection_operations_are_rejected(): void
    {
        [$first,,$base,$query] = $this->fixture();
        $id = $this->finish($base, $query);
        $second = $this->signInMember(OrganizationRole::Admin);
        $this->stepUp();
        $other = "/api/v1/organizations/{$second->uuid}/integrations/email";
        $this->getJson($other)->assertOk()->assertJsonCount(0, 'data.items');
        $this->postJson("$other/$id/revoke")->assertNotFound();
        $this->postJson("$other/oauth/finish", ['provider' => 'gmail', 'state' => $query['state'], 'code' => 'synthetic-code'])->assertForbidden();
        $this->assertDatabaseHas('connections', ['id' => $id, 'organization_id' => $first->id, 'status' => 'enabled']);
    }

    public function test_expired_token_refreshes_once_and_revocation_blocks_future_use(): void
    {
        [$org,,$base,$query] = $this->fixture();
        $id = $this->finish($base, $query);
        $this->postJson("$base/$id/approve")->assertOk();
        DB::table('email_mailboxes')->where('connection_id', $id)->update(['token_expires_at' => now()->subMinute()]);
        $tokens = app(EmailTokens::class);
        $this->assertSame($tokens->get($org->id, $id), $tokens->get($org->id, $id));
        $this->assertSame(2, $this->exchanges);
        $this->postJson("$base/$id/revoke")->assertOk();
        $this->assertDatabaseMissing('credential_secrets', ['connection_id' => $id]);
        $this->postJson("$base/$id/test")->assertStatus(409);
        $this->assertSame(2, $this->exchanges);
    }

    public function test_scope_downgrade_cannot_escalate_and_requires_fresh_workflow_grants(): void
    {
        [,,$base,$query] = $this->fixture();
        $id = $this->finish($base, $query);
        $this->postJson("$base/$id/downgrade", ['capabilities' => ['read']])->assertOk();
        $this->postJson("$base/$id/downgrade", ['capabilities' => ['read', 'send']])->assertUnprocessable();
        $this->assertSame(['read'], json_decode(DB::table('email_mailboxes')->where('connection_id', $id)->value('capabilities'), true));
    }

    public function test_provider_failure_is_sanitized_and_invalid_refresh_persists_unhealthy_state(): void
    {
        [$org,,$base,$query] = $this->fixture();
        $id = $this->finish($base, $query);
        $this->postJson("$base/$id/approve")->assertOk();
        DB::table('email_mailboxes')->where('connection_id', $id)->update(['token_expires_at' => now()->subMinute()]);
        $this->http->http = new Factory;
        $this->http->http->preventStrayRequests();
        $this->http->http->fake(['*' => Factory::response(['error' => 'invalid_grant', 'private' => 'secret-message-content'], 400)]);
        $response = $this->postJson("$base/$id/test")->assertStatus(503);
        $this->assertStringNotContainsString('secret-message-content', $response->getContent());
        $this->assertDatabaseHas('email_mailboxes', ['connection_id' => $id, 'health' => 'reauthorization_required']);
    }

    public function test_step_up_and_provider_approval_are_required_before_oauth(): void
    {
        $org = $this->signInMember(OrganizationRole::Admin);
        $base = "/api/v1/organizations/{$org->uuid}/integrations/email";
        $data = ['provider' => 'gmail', 'name' => 'Mailbox', 'ownership' => 'organization', 'capabilities' => ['read']];
        $this->postJson("$base/oauth/start", $data)->assertStatus(428);
        $this->stepUp();
        $this->postJson("$base/oauth/start", $data)->assertForbidden();
        $this->assertSame(0, $this->exchanges);
    }

    public function test_provider_paths_and_recipients_cannot_be_used_for_ssrf_or_header_injection(): void
    {
        foreach (['https://evil.example/v1.0/me/mailFolders/inbox/messages/delta', 'https://graph.microsoft.com@evil.example/v1.0/me/mailFolders/inbox/messages/delta', 'https://graph.microsoft.com/v1.0/users/other/messages'] as $url) {
            try {
                $this->http->graphContinuation($url);
                $this->fail('Unsafe continuation accepted');
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
        try {
            app(EmailProviders::class)->address("qa@example.com\r\nBcc:other@example.com");
            $this->fail('Header injection accepted');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->http->http->assertNothingSent();
    }
}
