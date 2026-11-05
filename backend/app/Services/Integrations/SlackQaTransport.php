<?php

namespace App\Services\Integrations;

use App\Support\DemoEnvironment;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SlackQaTransport
{
    public function __construct(private CredentialVault $vault) {}

    public function assertLocal(): void
    {
        if (app()->environment('demo')) {
            app(DemoEnvironment::class)->assertOwned();

            return;
        }
        abort_unless(app()->environment('phase9d') && config('slack.simulated') && config('database.default') === 'pgsql' && in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost', '::1'], true) && config('database.connections.pgsql.database') === 'flowmv_phase9d_qa' && empty(config('database.connections.pgsql.url')) && config('slack.client_id') === 'FLOWMV_QA_SLACK_CLIENT' && config('slack.client_secret') === 'FLOWMV_QA_SLACK_SECRET' && config('slack.signing_secret') === 'FLOWMV_QA_SLACK_SIGNING_SECRET', 503);
        $actual = DB::selectOne('select current_database() as name, host(inet_server_addr()) as address');
        abort_unless($actual->name === 'flowmv_phase9d_qa' && in_array($actual->address, ['127.0.0.1', '::1'], true), 503);
    }

    private function save(string $key, array $value): void
    {
        Cache::put('slack-qa:'.$key, $this->vault->encrypt($value, 'slack-qa:'.$key), app()->environment('demo') ? 15552000 : 86400);
    }

    private function read(string $key): array
    {
        $record = Cache::get('slack-qa:'.$key);

        return is_array($record) ? $this->vault->decrypt((object) $record, 'slack-qa:'.$key) : [];
    }

    public function connect(Request $r, int $org, array $data): string
    {
        $this->assertLocal();
        $start = app(SlackOAuth::class)->start($r, $org, $data);
        parse_str(parse_url($start['authorization_url'], PHP_URL_QUERY), $q);
        $code = 'FLOWMV_QA_'.Str::random(40);
        $this->save('code:'.hash('sha256', $code), ['workspace' => 'T90000001', 'challenge' => $q['code_challenge'] ?? null, 'redirect' => $q['redirect_uri']]);

        return app(SlackOAuth::class)->finish($r, $org, $q['state'], $code);
    }

    public function attach(SlackApi $api): void
    {
        $this->assertLocal();
        $api->http->preventStrayRequests();
        $api->http->fake(function ($r) {
            abort_unless(str_starts_with($r->url(), 'https://slack.com/api/'), 503);
            $method = basename(parse_url($r->url(), PHP_URL_PATH));
            if ($method === 'oauth.v2.access') {
                $refresh = ($r['grant_type'] ?? '') === 'refresh_token';
                $key = ($refresh ? 'refresh:' : 'code:').hash('sha256', (string) $r[$refresh ? 'refresh_token' : 'code']);
                $grant = $this->read($key);
                if (! $grant || (! $refresh && ($grant['redirect'] !== $r['redirect_uri'] || ($grant['challenge'] && ! hash_equals($grant['challenge'], rtrim(strtr(base64_encode(hash('sha256', (string) $r['code_verifier'], true)), '+/', '-_'), '=')))))) {
                    return Factory::response(['ok' => false], 400);
                }
                Cache::forget('slack-qa:'.$key);
                $token = 'FLOWMV_QA_'.Str::random(40);
                $next = 'FLOWMV_QA_'.Str::random(40);
                $this->save('token:'.hash('sha256', $token), $grant);
                $this->save('refresh:'.hash('sha256', $next), $grant);

                return Factory::response(['ok' => true, 'token_type' => 'bot', 'access_token' => $token, 'refresh_token' => $next, 'expires_in' => 43200, 'scope' => implode(',', config('slack.scopes')), 'team' => ['id' => $grant['workspace']], 'bot_user_id' => 'U90000001']);
            }
            $token = preg_replace('/^Bearer /', '', $r->header('Authorization')[0] ?? '');
            $grant = $this->read('token:'.hash('sha256', $token));
            if (! $grant || ! str_starts_with($token, 'FLOWMV_QA_')) {
                return Factory::response(['ok' => false, 'error' => 'invalid_auth']);
            }
            $channel = ['id' => 'C90000001', 'name' => 'qa-workplace', 'is_member' => true, 'is_archived' => false];
            if (app()->environment('demo') && ($r['channel'] ?? '') === 'C90000002') {
                $channel = ['id' => 'C90000002', 'name' => 'qa-ai-workplace', 'is_member' => true, 'is_archived' => false];
            }
            $result = match ($method) {
                'auth.test' => ['team_id' => $grant['workspace'], 'user_id' => 'U90000001'],
                'conversations.list' => ['channels' => [$channel]],
                'conversations.info' => ($r['channel'] ?? '') === $channel['id'] ? ['channel' => $channel] : ['ok' => false],
                'chat.postMessage' => ['channel' => $r['channel'], 'ts' => (string) now()->timestamp.'.000001'],
                'auth.revoke' => ['revoked' => true],
                default => ['ok' => false],
            };

            return Factory::response(['ok' => true, ...$result]);
        });
    }

    public function event(string $id, string $sender, string $command, #[\SensitiveParameter] string $text, string $eventKey, string $channel = 'C90000001'): array
    {
        $this->assertLocal();
        abort_unless($channel === 'C90000001' || (app()->environment('demo') && $channel === 'C90000002'), 422);
        $i = DB::table('slack_installations')->where('connection_id', $id)->first();
        abort_unless($i, 404);
        $raw = http_build_query(['api_app_id' => config('slack.app_id'), 'team_id' => $i->workspace_id, 'channel_id' => $channel, 'user_id' => $sender, 'command' => $command, 'text' => $text, 'trigger_id' => $eventKey]);
        $timestamp = (string) now()->timestamp;

        return app(SlackIngress::class)->receive($raw, $timestamp, 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$raw, config('slack.signing_secret')), true);
    }
}
