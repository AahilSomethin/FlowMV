<?php

namespace App\Services\Integrations;

use App\Jobs\ActivateTelegramEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramIngress
{
    public function __construct(private TelegramAuthorization $authorization, private TelegramApi $api, private TelegramIdentity $identity, private CredentialVault $vault, private ConnectionManager $audit) {}

    public function receive(string $id, #[\SensitiveParameter] string $secret, #[\SensitiveParameter] array $update): array
    {
        return DB::transaction(function () use ($id, $secret, $update) {
            $c = DB::table('connections')->where('id', $id)->where('provider_id', 'telegram')->lockForUpdate()->first();
            abort_unless($c, 403);
            $bot = DB::table('telegram_bots')->where('connection_id', $id)->first();
            abort_unless($bot && $bot->webhook_configured && $this->api->verifyWebhook('', 0, $secret, ['webhook_secret_hash' => $bot->webhook_secret_hash]), 403);
            $this->authorization->connection($c->organization_id, $id);
            if (isset($update['callback_query'])) {
                return app(TelegramDecisions::class)->receive($c, $update);
            }
            $m = $update['message'] ?? null;
            $updateId = $update['update_id'] ?? null;
            if (! is_int($updateId) || $updateId < 0 || ! is_array($m) || ! is_string($m['text'] ?? null) || strlen($m['text']) > 16384 || ! is_int($m['from']['id'] ?? null) || ($m['from']['is_bot'] ?? true) !== false || ! is_int($m['chat']['id'] ?? null) || ! is_int($m['message_id'] ?? null) || ! is_int($m['date'] ?? null) || $m['date'] < now()->subDay()->timestamp || $m['date'] > now()->addMinute()->timestamp) {
                return ['accepted' => false];
            }
            $chat = (string) $m['chat']['id'];
            $sender = (string) $m['from']['id'];
            $type = $m['chat']['type'] ?? '';
            if (! in_array($type, ['private', 'group', 'supergroup'], true) || ($type === 'private' && $chat !== $sender)) {
                return ['accepted' => false];
            }
            if (! DB::table('telegram_destinations')->where('connection_id', $id)->where('chat_id', $chat)->exists()) {
                DB::table('telegram_destinations')->insert(['connection_id' => $id, 'chat_id' => $chat, 'chat_type' => $type, 'label' => ucfirst($type).' '.$chat]);
                $this->audit->audit($c->organization_id, $id, null, 'telegram.destination.discovered');
            }
            if ($this->identity->receive($c, $sender, $m['text'], $chat, $type)) {
                return ['accepted' => true, 'identity_pending' => true];
            }
            $destination = DB::table('telegram_destinations')->where('connection_id', $id)->where('chat_id', $chat)->first();
            if (! $destination->approved_by) {
                return ['accepted' => false];
            }
            $this->authorization->destination($c, $chat);
            $link = DB::table('telegram_identity_links')->where('connection_id', $id)->where('telegram_user_id', $sender)->where('confirmed', true)->first();
            if (! $link || ($c->owner_user_id !== null && (int) $c->owner_user_id !== (int) $link->user_id)) {
                return ['accepted' => false];
            }
            app(ConnectionAccess::class)->role($link->user_id, $c->organization_id);
            if (DB::table('telegram_inbound_events')->where('connection_id', $id)->where('update_id', $updateId)->exists()) {
                return ['accepted' => true, 'duplicate' => true];
            }
            $command = '';
            foreach ($m['entities'] ?? [] as $entity) {
                if (($entity['type'] ?? '') === 'bot_command' && ($entity['offset'] ?? -1) === 0 && is_int($entity['length'] ?? null)) {
                    $raw = substr($m['text'], 0, min(100, $entity['length']));
                    $parts = explode('@', $raw, 2);
                    if (! isset($parts[1]) || strcasecmp($parts[1], $bot->username) === 0) {
                        $command = strtolower($parts[0]);
                    } break;
                }
            }
            $eventId = (string) Str::uuid();
            $payload = ['text' => mb_substr($m['text'], 0, 4096), 'command' => $command, 'chat_id' => $chat, 'chat_type' => $type, 'sender_id' => $sender, 'message_id' => (string) $m['message_id'], 'actor_user_id' => $link->user_id];
            DB::table('telegram_inbound_events')->insert(['id' => $eventId, 'connection_id' => $id, 'update_id' => $updateId, ...$this->vault->encrypt($payload, $id.':event:'.$eventId), 'received_at' => now()]);
            $subscriptions = DB::table('integration_trigger_subscriptions')->where('connection_id', $id)->where('destination', $chat)->where('status', 'active')->limit(100)->get();
            foreach ($subscriptions as $subscription) {
                $activation = (string) Str::uuid();
                DB::table('integration_trigger_activations')->insert(['id' => $activation, 'event_id' => $eventId, 'subscription_id' => $subscription->id, 'status' => 'pending', 'attempt_count' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                ActivateTelegramEvent::dispatch($activation)->afterCommit();
            }
            $this->audit->audit($c->organization_id, $id, $link->user_id, 'telegram.event.accepted');

            return ['accepted' => true, 'duplicate' => false];
        });
    }
}
