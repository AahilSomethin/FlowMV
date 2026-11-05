<?php

namespace App\Services\Integrations;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SlackOAuth
{
    public function __construct(private SlackAuthorization $authorization, private CredentialVault $vault, private SlackApi $api, private ConnectionManager $audit) {}

    public function callback(): string
    {
        $uri = config('slack.redirect_uri');
        $parts = is_string($uri) ? parse_url($uri) : false;
        abort_unless($parts && ($parts['scheme'] ?? '') === 'https' && isset($parts['host']) && ($parts['path'] ?? '') === '/integrations/slack-callback' && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']), 503);

        return $uri;
    }

    public function start(Request $request, int $org, array $data): array
    {
        return DB::transaction(function () use ($request, $org, $data) {
            $this->authorization->create($request->user()->id, $org, $data['ownership']);
            if (! empty($data['connection_id'])) {
                $this->authorization->manage($request->user()->id, $org, $data['connection_id']);
            }
            $state = bin2hex(random_bytes(32));
            $hash = hash('sha256', $state);
            $client = config('slack.client_id');
            abort_unless(is_string($client) && $client !== '' && config('slack.client_secret'), 503);
            $context = ['name' => $data['name'], 'ownership' => $data['ownership'], 'connection_id' => $data['connection_id'] ?? null, 'client_id' => $client, 'redirect_uri' => $this->callback()];
            $context['pkce'] = (bool) config('slack.pkce');
            $context['verifier'] = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            DB::table('slack_oauth_attempts')->insert(['state_hash' => $hash, 'organization_id' => $org, 'user_id' => $request->user()->id, 'session_hash' => hash('sha256', $request->session()->getId()), ...$this->vault->encrypt($context, 'slack-oauth:'.$hash), 'expires_at' => now()->addMinutes(5)]);

            $query = ['client_id' => $client, 'scope' => implode(',', config('slack.scopes')), 'redirect_uri' => $context['redirect_uri'], 'state' => $state];
            if ($context['pkce']) {
                $query['code_challenge_method'] = 'S256';
                $query['code_challenge'] = rtrim(strtr(base64_encode(hash('sha256', $context['verifier'], true)), '+/', '-_'), '=');
            }

            return ['authorization_url' => 'https://slack.com/oauth/v2/authorize?'.http_build_query($query)];
        });
    }

    public function finish(Request $request, int $org, #[\SensitiveParameter] string $state, #[\SensitiveParameter] string $code): string
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $state) && strlen($code) > 0 && strlen($code) < 12000, 403);
        $context = DB::transaction(function () use ($request, $org, $state) {
            $hash = hash('sha256', $state);
            $row = DB::table('slack_oauth_attempts')->where('state_hash', $hash)->lockForUpdate()->first();
            abort_unless($row && (int) $row->organization_id === $org && (int) $row->user_id === $request->user()->id && $row->consumed_at === null && Carbon::parse($row->expires_at)->isFuture() && hash_equals($row->session_hash, hash('sha256', $request->session()->getId())), 403);
            $context = $this->vault->decrypt($row, 'slack-oauth:'.$hash);
            abort_unless($context['client_id'] === config('slack.client_id') && $context['redirect_uri'] === $this->callback() && $context['pkce'] === (bool) config('slack.pkce'), 403);
            $this->authorization->create($request->user()->id, $org, $context['ownership']);
            DB::table('slack_oauth_attempts')->where('state_hash', $hash)->update(['consumed_at' => now()]);

            return $context;
        });
        $form = ['client_id' => $context['client_id'], 'code' => $code, 'redirect_uri' => $context['redirect_uri']];
        $form[$context['pkce'] ? 'code_verifier' : 'client_secret'] = $context['pkce'] ? $context['verifier'] : config('slack.client_secret');
        $tokens = $this->api->call('oauth.v2.access', null, $form);
        abort_unless(($tokens['token_type'] ?? '') === 'bot' && ($tokens['is_enterprise_install'] ?? false) === false && preg_match('/^T[A-Z0-9]{8,30}$/D', $tokens['team']['id'] ?? '') && is_string($tokens['access_token'] ?? null) && strlen($tokens['access_token']) < 4096 && ($tokens['access_token'] ?? '') !== '' && preg_match('/^[UW][A-Z0-9]{8,30}$/D', $tokens['bot_user_id'] ?? ''), 422);
        $scopes = explode(',', $tokens['scope'] ?? '');
        abort_unless(array_diff(config('slack.scopes'), $scopes) === [], 422);
        $identity = $this->api->call('auth.test', $tokens['access_token']);
        abort_unless(($identity['team_id'] ?? '') === $tokens['team']['id'] && ($identity['user_id'] ?? '') === $tokens['bot_user_id'], 403);

        return DB::transaction(function () use ($request, $org, $context, $tokens, $scopes) {
            $this->authorization->create($request->user()->id, $org, $context['ownership']);
            $id = $context['connection_id'] ?? (string) Str::uuid();
            abort_if(DB::table('slack_installations')->where('active_workspace_id', $tokens['team']['id'])->where('connection_id', '!=', $id)->exists(), 409);
            $generation = 1;
            if ($context['connection_id']) {
                $c = $this->authorization->manage($request->user()->id, $org, $id);
                $old = DB::table('slack_installations')->where('connection_id', $id)->first();
                abort_unless($old && $old->workspace_id === $tokens['team']['id'] && ($c->owner_user_id === null ? 'organization' : 'personal') === $context['ownership'], 403);
                $generation = $old->credential_generation + 1;
                DB::table('slack_workflow_grants')->where('connection_id', $id)->delete();
                DB::table('slack_channels')->where('connection_id', $id)->delete();
                DB::table('slack_identity_links')->where('connection_id', $id)->delete();
                DB::table('slack_trigger_subscriptions')->where('connection_id', $id)->update(['status' => 'paused', 'updated_at' => now()]);
                DB::table('slack_action_outbox')->where('connection_id', $id)->whereIn('status', ['pending', 'processing'])->update(['status' => 'cancelled', 'updated_at' => now()]);
                DB::table('connections')->where('id', $id)->update(['name' => $context['name'], 'status' => 'enabled', 'updated_at' => now()]);
            } else {
                DB::table('connections')->insert(['id' => $id, 'organization_id' => $org, 'owner_user_id' => $context['ownership'] === 'personal' ? $request->user()->id : null, 'provider_id' => 'slack', 'credential_type' => 'oauth', 'name' => $context['name'], 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
            }
            $secret = ['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'] ?? null];
            DB::table('credential_secrets')->updateOrInsert(['connection_id' => $id], [...$this->vault->encrypt($secret, $id), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('slack_installations')->updateOrInsert(['connection_id' => $id], ['workspace_id' => $tokens['team']['id'], 'active_workspace_id' => $tokens['team']['id'], 'bot_user_id' => $tokens['bot_user_id'], 'scopes' => json_encode($scopes), 'approved_by' => null, 'credential_generation' => $generation, 'health' => 'needs_approval', 'token_expires_at' => isset($tokens['expires_in']) ? now()->addSeconds(max(1, min(86400, (int) $tokens['expires_in']))) : null]);
            $this->audit->audit($org, $id, $request->user()->id, 'slack.oauth.connected');

            return $id;
        });
    }
}
