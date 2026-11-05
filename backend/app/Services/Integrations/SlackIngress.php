<?php

namespace App\Services\Integrations;

use App\Jobs\ActivateSlackEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SlackIngress
{
    public function __construct(private SlackSignature $signature, private SlackAuthorization $authorization, private ConnectionAccess $access, private CredentialVault $vault, private ConnectionManager $audit) {}

    public function receive(#[\SensitiveParameter] string $raw, string $timestamp, string $signature, bool $command = false): array
    {
        abort_unless(config('slack.enabled') && $this->signature->verify($raw, $timestamp, $signature), 403);
        if ($command) {
            parse_str($raw, $body);
        } else {
            $body = json_decode($raw, true);
        }
        abort_unless(is_array($body) && is_string(config('slack.app_id')) && config('slack.app_id') !== '' && ($body['api_app_id'] ?? '') === config('slack.app_id'), 403);
        if (! $command && ($body['type'] ?? '') === 'url_verification') {
            abort_unless(is_string($body['challenge'] ?? null) && strlen($body['challenge']) <= 256, 422);

            return ['challenge' => $body['challenge']];
        }
        $workspace = $body['team_id'] ?? '';
        $event = $command ? $body : ($body['event'] ?? []);
        if (! $command && ($body['type'] ?? '') === 'event_callback' && in_array($event['type'] ?? '', ['app_uninstalled', 'tokens_revoked'], true)) {
            DB::transaction(function () use ($workspace) {
                $i = DB::table('slack_installations')->where('active_workspace_id', $workspace)->lockForUpdate()->first();
                if (! $i) {
                    return;
                }
                $c = DB::table('connections')->where('id', $i->connection_id)->lockForUpdate()->first();
                DB::table('slack_installations')->where('connection_id', $c->id)->update(['approved_by' => null, 'health' => 'reauthorization_required']);
                DB::table('slack_trigger_subscriptions')->where('connection_id', $c->id)->update(['status' => 'paused', 'updated_at' => now()]);
                DB::table('slack_action_outbox')->where('connection_id', $c->id)->where('status', 'pending')->update(['status' => 'cancelled', 'error_code' => 'credential_revoked', 'updated_at' => now()]);
                $this->audit->audit($c->organization_id, $c->id, null, 'slack.provider.revoked');
            });

            return ['accepted' => true];
        }
        if (! $command && (($body['type'] ?? '') !== 'event_callback' || ($event['type'] ?? '') !== 'app_mention' || isset($event['bot_id']) || isset($event['subtype']))) {
            return ['accepted' => false];
        }
        $channel = $command ? ($body['channel_id'] ?? '') : ($event['channel'] ?? '');
        $sender = $command ? ($body['user_id'] ?? '') : ($event['user'] ?? '');
        $text = $event['text'] ?? '';
        abort_unless(is_string($text) && strlen($text) <= 8192 && preg_match('/^[CG][A-Z0-9]{8,30}$/D', $channel) && preg_match('/^[UW][A-Z0-9]{8,30}$/D', $sender), 422);
        $eventKey = $command ? hash('sha256', $raw) : ($body['event_id'] ?? '');
        abort_unless(is_string($eventKey) && strlen($eventKey) > 0 && strlen($eventKey) <= 64, 422);

        return DB::transaction(function () use ($workspace, $channel, $sender, $text, $body, $event, $eventKey, $command) {
            $i = DB::table('slack_installations')->where('active_workspace_id', $workspace)->lockForUpdate()->first();
            abort_unless($i, 403);
            $c = DB::table('connections')->where('id', $i->connection_id)->first();
            $this->authorization->connection($c->organization_id, $c->id);
            abort_unless($sender !== $i->bot_user_id, 403);
            $d = DB::table('slack_channels')->where('connection_id', $c->id)->where('channel_id', $channel)->first();
            abort_unless($d && $this->access->role($d->approved_by, $c->organization_id) === 'admin', 403);
            if ($command && ($body['command'] ?? '') === '/flowmv_link') {
                abort_unless(preg_match('/^[a-f0-9]{64}$/D', $text), 422);
                $link = DB::table('slack_identity_links')->where('connection_id', $c->id)->where('challenge_hash', hash('sha256', $text))->where('expires_at', '>', now())->where('confirmed', false)->lockForUpdate()->first();
                abort_unless($link, 403);
                $this->access->role($link->user_id, $c->organization_id);
                DB::table('slack_identity_links')->where('id', $link->id)->update(['slack_user_id' => $sender, 'challenge_hash' => null]);
                $this->audit->audit($c->organization_id, $c->id, $link->user_id, 'slack.identity.received');

                return ['accepted' => true];
            }
            $link = DB::table('slack_identity_links')->where('connection_id', $c->id)->where('slack_user_id', $sender)->where('confirmed', true)->first();
            abort_unless($link && ($c->owner_user_id === null || (int) $c->owner_user_id === (int) $link->user_id), 403);
            $this->access->role($link->user_id, $c->organization_id);
            if (DB::table('slack_inbound_events')->where('connection_id', $c->id)->where('event_key', $eventKey)->exists()) {
                return ['accepted' => true, 'duplicate' => true];
            }
            $id = (string) Str::uuid();
            $payload = ['text' => mb_substr($text, 0, 2000), 'command' => $command ? ($body['command'] ?? '') : '', 'event_type' => $command ? 'command' : 'app_mention', 'channel_id' => $channel, 'sender_id' => $sender, 'message_id' => $command ? $eventKey : ($event['ts'] ?? ''), 'actor_user_id' => $link->user_id];
            DB::table('slack_inbound_events')->insert(['id' => $id, 'connection_id' => $c->id, 'event_key' => $eventKey, ...$this->vault->encrypt($payload, $c->id.':event:'.$id), 'received_at' => now()]);
            foreach (DB::table('slack_trigger_subscriptions')->where('connection_id', $c->id)->where('destination', $channel)->where('status', 'active')->limit(100)->get() as $s) {
                $activation = (string) Str::uuid();
                DB::table('slack_trigger_activations')->insert(['id' => $activation, 'event_id' => $id, 'subscription_id' => $s->id, 'status' => 'pending', 'attempt_count' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                ActivateSlackEvent::dispatch($activation)->afterCommit();
            }
            $this->audit->audit($c->organization_id, $c->id, $link->user_id, 'slack.event.accepted');

            return ['accepted' => true, 'duplicate' => false];
        });
    }
}
