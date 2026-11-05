<?php

namespace App\Services\Integrations;

use App\Exceptions\SlackApiFailure;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Str;

class SlackApi
{
    public function __construct(public readonly Factory $http) {}

    public function call(string $method, #[\SensitiveParameter] ?string $token, #[\SensitiveParameter] array $data = []): array
    {
        abort_unless(in_array($method, ['oauth.v2.access', 'auth.test', 'auth.revoke', 'conversations.list', 'conversations.info', 'chat.postMessage'], true), 422);
        try {
            $request = $this->http->acceptJson()->connectTimeout(3)->timeout(8)->withOptions([
                'allow_redirects' => false, 'verify' => true, 'debug' => false,
                'progress' => function ($total, $downloaded) {
                    if ($downloaded > 1048576) {
                        throw new \RuntimeException('Response limit.');
                    }
                },
            ]);
            if ($token !== null) {
                $request = $request->withToken($token);
            }
            $response = $request->asForm()->post('https://slack.com/api/'.$method, $data);
        } catch (\Throwable) {
            throw new SlackApiFailure('transport_timeout', ambiguous: $method === 'chat.postMessage');
        }
        if ($response->status() === 429) {
            throw new SlackApiFailure('rate_limited', true, max(1, min(86400, (int) $response->header('Retry-After'))));
        }
        if ($response->serverError()) {
            throw new SlackApiFailure('provider_unavailable', true, 30, $method === 'chat.postMessage');
        }
        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ($body['ok'] ?? false) !== true) {
            $reason = in_array($body['error'] ?? '', ['token_revoked', 'token_expired', 'invalid_auth', 'missing_scope', 'not_in_channel', 'channel_not_found'], true) ? $body['error'] : 'provider_rejected';
            throw new SlackApiFailure($reason);
        }

        return $body;
    }

    public function send(#[\SensitiveParameter] string $token, string $channel, #[\SensitiveParameter] string $text, string $id): string
    {
        abort_unless(preg_match('/^[CG][A-Z0-9]{8,30}$/D', $channel) && mb_strlen($text) > 0 && mb_strlen($text) <= 2000 && Str::isUuid($id), 422);
        $result = $this->call('chat.postMessage', $token, ['channel' => $channel, 'text' => $text, 'mrkdwn' => false, 'parse' => 'none', 'unfurl_links' => false, 'unfurl_media' => false, 'client_msg_id' => $id]);
        if (($result['channel'] ?? null) !== $channel || ! is_string($result['ts'] ?? null)) {
            throw new SlackApiFailure('invalid_response', ambiguous: true);
        }

        return $result['ts'];
    }
}
