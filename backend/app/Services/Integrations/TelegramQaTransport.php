<?php

namespace App\Services\Integrations;

use App\Support\DemoEnvironment;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TelegramQaTransport
{
    public function __construct(private CredentialVault $vault) {}

    public function assertLocal(): void
    {
        if (app()->environment('demo')) {
            app(DemoEnvironment::class)->assertOwned();

            return;
        }
        $expected = app()->environment('phase9d') ? 'flowmv_phase9d_qa' : 'flowmv_phase9b_qa';
        abort_unless(app()->environment(['phase9b', 'phase9d']) && config('integrations.telegram_simulated') && config('database.default') === 'pgsql' && in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost', '::1'], true) && config('database.connections.pgsql.database') === $expected && empty(config('database.connections.pgsql.url')), 503);
        $actual = DB::selectOne('select current_database() as name, inet_server_addr()::text as address');
        abort_unless($actual->name === $expected && in_array($actual->address, ['127.0.0.1/32', '127.0.0.1', '::1/128', '::1'], true), 503);
    }

    public function attach(TelegramApi $api): void
    {
        $this->assertLocal();
        $api->http->preventStrayRequests();
        $api->http->fake(function ($request) {
            $parts = explode('/', parse_url($request->url(), PHP_URL_PATH));
            $bot = $parts[1] ?? '';
            $method = $parts[2] ?? '';
            if (! preg_match('/^bot(900[0-9]{3}):[A-Za-z0-9_-]{35,100}_FLOWMV_QA_ONLY$/D', $bot, $match)) {
                return Factory::response(['ok' => false, 'error_code' => 401], 401);
            }
            $id = $match[1];
            $result = match ($method) {
                'getMe' => ['id' => (int) $id, 'is_bot' => true, 'username' => 'flowmv_qa_'.$id.'_bot'],
                'getChat' => ['id' => (int) $request['chat_id'], 'type' => str_starts_with((string) $request['chat_id'], '-') ? 'supergroup' : 'private'],
                'getChatMember' => ['status' => 'administrator'],'getWebhookInfo' => $this->webhookInfo($id),
                'setWebhook' => $this->remember($id, (string) $request['secret_token'], (string) $request['url']),
                'deleteWebhook' => true,
                'answerCallbackQuery' => true,
                'sendMessage' => ['message_id' => $this->nextId('sent:'.$id)],
                default => false,
            };

            return Factory::response(['ok' => $result !== false, 'result' => $result]);
        });
    }

    private function webhookInfo(string $id): array
    {
        $stored = Cache::get('telegram-qa-webhook:'.$id);
        if (! is_array($stored)) {
            return ['url' => ''];
        }
        $context = $this->vault->decrypt((object) $stored, 'qa:'.$id);

        return ['url' => $context['url'], 'pending_update_count' => 0];
    }

    private function remember(string $botId, #[\SensitiveParameter] string $secret, string $url): bool
    {
        Cache::put('telegram-qa-webhook:'.$botId, $this->vault->encrypt(['secret' => $secret, 'url' => $url], 'qa:'.$botId), app()->environment('demo') ? 15552000 : 7200);

        return true;
    }

    private function nextId(string $scope): int
    {
        Cache::add('telegram-qa-counter:'.$scope, 1000, app()->environment('demo') ? 15552000 : 7200);

        return (int) Cache::increment('telegram-qa-counter:'.$scope);
    }

    public function emit(string $connectionId, string $sender, string $chat, #[\SensitiveParameter] string $text): array
    {
        $this->assertLocal();
        $bot = DB::table('telegram_bots')->where('connection_id', $connectionId)->first();
        abort_unless($bot, 404);
        $stored = Cache::get('telegram-qa-webhook:'.$bot->bot_id);
        abort_unless(is_array($stored), 409);
        $secret = $this->vault->decrypt((object) $stored, 'qa:'.$bot->bot_id)['secret'];
        $command = explode(' ', $text, 2)[0];
        $update = ['update_id' => $this->nextId('event:'.$bot->bot_id), 'message' => ['message_id' => $this->nextId('message:'.$bot->bot_id), 'date' => now()->timestamp, 'from' => ['id' => (int) $sender, 'is_bot' => false], 'chat' => ['id' => (int) $chat, 'type' => str_starts_with($chat, '-') ? 'supergroup' : 'private'], 'text' => $text, 'entities' => str_starts_with($text, '/') ? [['type' => 'bot_command', 'offset' => 0, 'length' => strlen($command)]] : []]];

        return app(TelegramIngress::class)->receive($connectionId, $secret, $update);
    }

    public function decision(object $delivery, string $choice): array
    {
        $this->assertLocal();
        $bot = DB::table('telegram_bots')->where('connection_id', $delivery->connection_id)->first();
        $stored = Cache::get('telegram-qa-webhook:'.$bot->bot_id);
        abort_unless(is_array($stored), 409);
        $secret = $this->vault->decrypt((object) $stored, 'qa:'.$bot->bot_id)['secret'];
        $payload = $this->vault->decrypt($delivery, 'telegram-approval:'.$delivery->id);
        $update = $this->nextId('event:'.$bot->bot_id);
        $query = ['id' => 'qa-decision-'.$update, 'data' => $payload['keyboard'][0][$choice === 'approved' ? 0 : 1]['callback_data'], 'from' => ['id' => (int) $delivery->destination, 'is_bot' => false], 'message' => ['message_id' => (int) $delivery->provider_message_id, 'from' => ['id' => (int) $bot->bot_id, 'is_bot' => true], 'chat' => ['id' => (int) $delivery->destination, 'type' => 'private']]];

        return app(TelegramIngress::class)->receive($delivery->connection_id, $secret, ['update_id' => $update, 'callback_query' => $query]);
    }
}
