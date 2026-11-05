<?php

namespace App\Services\Integrations;

use App\Exceptions\TelegramApiFailure;
use Illuminate\Http\Client\Factory;

class TelegramApi implements Connector
{
    /** Private HTTP factory: no application request events or global HTTP middleware. */
    public function __construct(public readonly Factory $http) {}

    public function manifest(): array
    {
        return ['id' => 'telegram', 'version' => 3, 'name' => 'Telegram Bot', 'credential_types' => ['bot_token'], 'credential_schemas' => ['bot_token' => ['bot_token' => 'BotFather token']],
            'triggers' => ['telegram.message'], 'actions' => ['telegram.send_message', 'telegram.notification'],
            'configuration_schema' => ['destination' => 'approved numeric chat ID', 'text' => 'plain text template'],
            'reads' => 'Messages sent to the bot in approved chats, from confirmed linked members.', 'sends' => 'Plain text to administrator-approved destinations.',
            'rate_limit' => ['per_bot_per_minute' => 20, 'per_chat_per_minute' => 10], 'retry' => ['max_attempts' => 5, 'ambiguous_timeout' => 'manual review'],
            'audit_metadata' => ['connection_id', 'instance_uuid', 'node_id', 'status', 'error_code']];
    }

    public function validateCredentials(string $type, #[\SensitiveParameter] array $credentials): array
    {
        abort_unless($type === 'bot_token' && array_keys($credentials) === ['bot_token'] && is_string($credentials['bot_token']) && preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{30,100}$/D', $credentials['bot_token']) === 1, 422);

        return $credentials;
    }

    public function validateConfiguration(string $operation, array $configuration): void
    {
        abort_unless(in_array($operation, ['telegram.message', 'telegram.send_message', 'telegram.notification', 'telegram.approval'], true) && is_string($configuration['destination'] ?? null) && preg_match('/^-?[0-9]{1,20}$/D', $configuration['destination']) === 1, 422);
    }

    public function identity(#[\SensitiveParameter] array $credentials): array
    {
        $clean = $this->validateCredentials('bot_token', $credentials);
        $bot = $this->call($clean['bot_token'], 'getMe');
        abort_unless(is_array($bot) && ($bot['is_bot'] ?? false) === true && isset($bot['id'],$bot['username']) && (string) $bot['id'] === explode(':', $clean['bot_token'])[0] && preg_match('/^[A-Za-z0-9_]{1,64}$/D', $bot['username']) === 1, 422);

        return ['id' => (string) $bot['id'], 'username' => $bot['username']];
    }

    public function test(#[\SensitiveParameter] array $credentials): bool
    {
        $this->identity($credentials);

        return true;
    }

    public function execute(string $operation, string $destination, #[\SensitiveParameter] array $credentials, string $idempotencyKey, #[\SensitiveParameter] array $configuration = []): void
    {
        $this->validateConfiguration($operation, ['destination' => $destination]);
        $this->send($credentials, $destination, $configuration['text'] ?? '');
    }

    public function send(#[\SensitiveParameter] array $credentials, string $destination, #[\SensitiveParameter] string $text): string
    {
        $this->validateConfiguration('telegram.send_message', ['destination' => $destination]);
        abort_unless($text !== '' && mb_strlen($text) <= 4096, 422);
        $result = $this->call($credentials['bot_token'], 'sendMessage', ['chat_id' => $destination, 'text' => $text, 'link_preview_options' => ['is_disabled' => true], 'protect_content' => true]);
        if (! is_array($result) || ! isset($result['message_id'])) {
            throw new TelegramApiFailure('invalid_response', ambiguous: true);
        }

        return (string) $result['message_id'];
    }

    public function verifyWebhook(string $eventId, int $timestamp, string $signature, #[\SensitiveParameter] array $credentials): bool
    {
        return $signature !== '' && strlen($signature) <= 256 && isset($credentials['webhook_secret_hash']) && hash_equals($credentials['webhook_secret_hash'], hash('sha256', $signature));
    }

    public function call(#[\SensitiveParameter] string $token, string $method, #[\SensitiveParameter] array $payload = []): mixed
    {
        if (! in_array($method, ['getMe', 'getChat', 'getChatMember', 'getWebhookInfo', 'setWebhook', 'deleteWebhook', 'sendMessage', 'answerCallbackQuery'], true)) {
            throw new TelegramApiFailure('unsupported_method');
        }
        try {
            $response = $this->http->withOptions(['allow_redirects' => false, 'debug' => false, 'verify' => true])->connectTimeout(3)->timeout(8)->acceptJson()->asJson()->post('https://api.telegram.org/bot'.$token.'/'.$method, $payload);
        } catch (\Throwable) {
            throw new TelegramApiFailure('transport_timeout', ambiguous: $method === 'sendMessage');
        }
        $body = $response->json();
        if ($response->status() === 429 || (is_array($body) && ($body['error_code'] ?? null) === 429)) {
            throw new TelegramApiFailure('rate_limited', true, max(1, min(86400, (int) ($body['parameters']['retry_after'] ?? 30))));
        }
        if ($response->serverError()) {
            throw new TelegramApiFailure('provider_unavailable', true, 30);
        }
        if (! $response->successful() || ! is_array($body) || ($body['ok'] ?? false) !== true) {
            throw new TelegramApiFailure('provider_rejected');
        }

        return $body['result'] ?? null;
    }
}
