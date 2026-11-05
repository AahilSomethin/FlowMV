<?php

namespace App\Services\Integrations;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SlackTokens
{
    public function __construct(private CredentialVault $vault, private SlackApi $api) {}

    public function access(string $id): string
    {
        $result = DB::transaction(function () use ($id) {
            $i = DB::table('slack_installations')->where('connection_id', $id)->lockForUpdate()->first();
            $record = DB::table('credential_secrets')->where('connection_id', $id)->lockForUpdate()->first();
            abort_unless($i && $record && $i->active_workspace_id && $i->health !== 'reauthorization_required', 403);
            $secret = $this->vault->decrypt($record, $id);
            if ($i->token_expires_at === null || Carbon::parse($i->token_expires_at)->isAfter(now()->addMinute())) {
                return $secret['access_token'];
            }
            try {
                abort_unless(is_string($secret['refresh_token'] ?? null) && $secret['refresh_token'] !== '', 403);
                $form = ['grant_type' => 'refresh_token', 'refresh_token' => $secret['refresh_token'], 'client_id' => config('slack.client_id')];
                if (! config('slack.pkce')) {
                    $form['client_secret'] = config('slack.client_secret');
                }
                $tokens = $this->api->call('oauth.v2.access', null, $form);
                abort_unless(($tokens['token_type'] ?? '') === 'bot' && is_string($tokens['access_token'] ?? null) && $tokens['access_token'] !== '' && is_string($tokens['refresh_token'] ?? null) && $tokens['refresh_token'] !== '' && is_int($tokens['expires_in'] ?? null) && $tokens['expires_in'] > 0 && array_diff(config('slack.scopes'), explode(',', $tokens['scope'] ?? '')) === [], 403);
                $identity = $this->api->call('auth.test', $tokens['access_token']);
                abort_unless(($identity['team_id'] ?? '') === $i->workspace_id && ($identity['user_id'] ?? '') === $i->bot_user_id, 403);
                DB::table('credential_secrets')->where('connection_id', $id)->update([...$this->vault->encrypt(['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token']], $id), 'updated_at' => now()]);
                DB::table('slack_installations')->where('connection_id', $id)->update(['token_expires_at' => now()->addSeconds(min(86400, $tokens['expires_in']))]);
                $connection = DB::table('connections')->where('id', $id)->first();
                app(ConnectionManager::class)->audit($connection->organization_id, $id, null, 'slack.token.refreshed');

                return $tokens['access_token'];
            } catch (\Throwable) {
                DB::table('slack_installations')->where('connection_id', $id)->update(['health' => 'reauthorization_required', 'approved_by' => null]);
                DB::table('slack_trigger_subscriptions')->where('connection_id', $id)->update(['status' => 'paused', 'updated_at' => now()]);

                return null;
            }
        });
        abort_unless(is_string($result), 403);

        return $result;
    }
}
