<?php

namespace App\Services\Integrations;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmailOAuth
{
    public function __construct(private EmailProviders $providers, private EmailAuthorization $authorization, private CredentialVault $vault, private EmailHttp $http, private EmailTokens $tokens, private ConnectionManager $audit) {}

    public function start(Request $request, int $org, array $data): array
    {
        return DB::transaction(function () use ($request, $org, $data) {
            $provider = $data['provider'];
            $caps = $this->providers->capabilities($data['capabilities']);
            $this->authorization->create($request->user()->id, $org, $provider, $data['ownership']);
            if (! empty($data['connection_id'])) {
                $c = $this->authorization->manage($request->user()->id, $org, $data['connection_id']);
                abort_unless($c->provider_id === $provider && ($c->owner_user_id === null ? 'organization' : 'personal') === $data['ownership'], 403);
            }
            $definition = $this->providers->definition($provider);
            $redirect = $this->providers->callback($provider);
            $client = config("email_integrations.$provider.client_id");
            abort_unless(is_string($client) && $client !== '' && config("email_integrations.$provider.client_secret"), 503);
            $state = bin2hex(random_bytes(32));
            $hash = hash('sha256', $state);
            $verifier = $this->b64(random_bytes(64));
            $context = ['provider' => $provider, 'verifier' => $verifier, 'redirect_uri' => $redirect, 'capabilities' => $caps, 'name' => $data['name'], 'ownership' => $data['ownership'], 'connection_id' => $data['connection_id'] ?? null, 'client_id' => $client];
            DB::table('email_oauth_attempts')->insert(['state_hash' => $hash, 'organization_id' => $org, 'user_id' => $request->user()->id, 'provider_id' => $provider, 'session_hash' => hash('sha256', $request->session()->getId()), ...$this->vault->encrypt($context, 'email-oauth:'.$hash), 'expires_at' => now()->addMinutes(5)]);
            $query = ['client_id' => $client, 'response_type' => 'code', 'redirect_uri' => $redirect, 'state' => $state, 'code_challenge' => $this->b64(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256', 'scope' => implode(' ', $this->providers->scopes($provider, $caps)), 'prompt' => 'consent'];
            if ($provider === 'gmail') {
                $query['access_type'] = 'offline';
                $query['include_granted_scopes'] = 'false';
            }

            return ['authorization_url' => $definition['authorize'].'?'.http_build_query($query), 'provider' => $provider];
        });
    }

    public function finish(Request $request, int $org, string $provider, #[\SensitiveParameter] string $state, #[\SensitiveParameter] string $code): string
    {
        abort_unless(strlen($state) === 64 && ctype_xdigit($state) && strlen($code) > 0 && strlen($code) < 12000, 403);
        $context = DB::transaction(function () use ($request, $org, $provider, $state) {
            $hash = hash('sha256', $state);
            $row = DB::table('email_oauth_attempts')->where('state_hash', $hash)->lockForUpdate()->first();
            abort_unless($row && $row->provider_id === $provider && (int) $row->organization_id === $org && (int) $row->user_id === $request->user()->id && $row->consumed_at === null && Carbon::parse($row->expires_at)->isFuture() && hash_equals($row->session_hash, hash('sha256', $request->session()->getId())), 403);
            $context = $this->vault->decrypt($row, 'email-oauth:'.$hash);
            abort_unless($context['provider'] === $provider && $context['redirect_uri'] === $this->providers->callback($provider) && $context['client_id'] === config("email_integrations.$provider.client_id"), 403);
            $this->authorization->create($request->user()->id, $org, $provider, $context['ownership']);
            DB::table('email_oauth_attempts')->where('state_hash', $hash)->update(['consumed_at' => now()]);

            return $context;
        });
        $response = $this->http->token($provider, ['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $context['verifier'], 'redirect_uri' => $context['redirect_uri']]);
        $secret = $this->tokens->validate($provider, $response, $context['capabilities']);
        $identity = $this->http->identity($provider, $secret['access_token']);

        return DB::transaction(function () use ($request, $org, $provider, $context, $secret, $identity) {
            $this->authorization->create($request->user()->id, $org, $provider, $context['ownership']);
            $active = hash('sha256', $provider.':'.$identity['id']);
            $id = $context['connection_id'] ?? (string) Str::uuid();
            abort_if(DB::table('email_mailboxes')->where('active_identity', $active)->where('connection_id', '!=', $id)->exists(), 409);
            if ($context['connection_id'] !== null) {
                $c = $this->authorization->manage($request->user()->id, $org, $id);
                $mailbox = DB::table('email_mailboxes')->where('connection_id', $id)->first();
                abort_unless($c->provider_id === $provider && $mailbox->account_id === $identity['id'], 403);
                $this->tokens->pause($id);
                DB::table('email_workflow_grants')->where('connection_id', $id)->delete();
                DB::table('connections')->where('id', $id)->update(['status' => 'enabled', 'name' => $context['name'], 'updated_at' => now()]);
                $generation = $mailbox->credential_generation + 1;
            } else {
                DB::table('connections')->insert(['id' => $id, 'organization_id' => $org, 'owner_user_id' => $context['ownership'] === 'personal' ? $request->user()->id : null, 'provider_id' => $provider, 'name' => $context['name'], 'credential_type' => 'oauth', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
                $generation = 1;
            }
            DB::table('credential_secrets')->updateOrInsert(['connection_id' => $id], [...$this->vault->encrypt($secret, $id), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('email_mailboxes')->updateOrInsert(['connection_id' => $id], ['address' => $identity['address'], 'account_id' => $identity['id'], 'active_identity' => $active, 'capabilities' => json_encode($context['capabilities']), 'approved_by' => null, 'credential_generation' => $generation, 'token_expires_at' => now()->addSeconds($secret['expires_in']), 'health' => 'needs_approval', 'error_code' => null, 'connected_at' => now()]);
            DB::table('email_sync_state')->updateOrInsert(['connection_id' => $id], [...$this->vault->encrypt(['cursor' => null, 'next' => null, 'subscription_secret' => null], 'email-sync:'.$id), 'subscription_id' => null, 'subscription_secret_hash' => null, 'watch_expires_at' => null, 'available_at' => now(), 'requested' => true, 'attempt_count' => 0, 'error_code' => null, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->audit($org, $id, $request->user()->id, 'email.oauth.connected');

            return $id;
        });
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
