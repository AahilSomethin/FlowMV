<?php

namespace App\Services\Integrations;

use App\Jobs\ProcessTelegramDelivery;
use App\Models\WorkflowInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TelegramConnections
{
    public function __construct(private ConnectionAccess $access, private CredentialVault $vault, private TelegramApi $api, private TelegramAuthorization $authorization, private ConnectionManager $audit) {}

    public function create(int $user, int $org, array $data, #[\SensitiveParameter] array $credentials): string
    {
        return DB::transaction(function () use ($user, $org, $data, $credentials) {
            $this->access->create($user, $org, $data['ownership'], 'telegram');
            $clean = $this->api->validateCredentials('bot_token', $credentials);
            $bot = $this->api->identity($clean);
            abort_if(DB::table('telegram_bots')->where('active_bot_id', $bot['id'])->exists(), 409);
            $id = (string) Str::uuid();
            DB::table('connections')->insert(['id' => $id, 'organization_id' => $org, 'owner_user_id' => $data['ownership'] === 'personal' ? $user : null, 'provider_id' => 'telegram', 'name' => $data['name'], 'credential_type' => 'bot_token', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('credential_secrets')->insert(['connection_id' => $id, ...$this->vault->encrypt($clean, $id), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('telegram_bots')->insert(['connection_id' => $id, 'bot_id' => $bot['id'], 'active_bot_id' => $bot['id'], 'username' => $bot['username']]);
            $this->audit->audit($org, $id, $user, 'telegram.connected');

            return $id;
        });
    }

    public function action(int $user, int $org, string $id, string $action, array $data = [], #[\SensitiveParameter] array $credentials = []): void
    {
        DB::transaction(function () use ($user, $org, $id, $action, $data, $credentials) {
            $c = DB::table('connections')->where('id', $id)->where('organization_id', $org)->where('provider_id', 'telegram')->lockForUpdate()->first();
            abort_unless($c, 404);
            $admin = $this->access->role($user, $org) === 'admin';
            $adminActions = ['approve', 'approve-destination', 'unapprove-destination', 'pause-trigger', 'resume-trigger', 'retry-delivery'];
            if (in_array($action, $adminActions, true)) {
                abort_unless($admin, 403);
            } else {
                $this->access->manage($user, $c);
            }
            abort_if($c->status === 'revoked', 409);
            $bot = DB::table('telegram_bots')->where('connection_id', $id)->first();
            if ($action === 'approve') {
                $this->authorization->connection($org, $id, false);
                DB::table('telegram_bots')->where('connection_id', $id)->update(['approved_by' => $user]);
            } elseif (in_array($action, ['disable', 'enable', 'revoke'], true)) {
                DB::table('connections')->where('id', $id)->update(['status' => ['disable' => 'disabled', 'enable' => 'enabled', 'revoke' => 'revoked'][$action], 'updated_at' => now()]);
                if ($action !== 'enable') {
                    if (Schema::hasTable('telegram_approval_deliveries')) {
                        DB::table('telegram_approval_deliveries')->where('connection_id', $id)->where('status', 'pending')->update(['status' => 'cancelled', 'error_code' => 'connection_disabled', 'updated_at' => now()]);
                        DB::table('telegram_decision_capabilities')->whereIn('delivery_id', DB::table('telegram_approval_deliveries')->where('connection_id', $id)->select('id'))->whereNull('consumed_at')->update(['consumed_at' => now()]);
                    }
                    DB::table('integration_trigger_subscriptions')->where('connection_id', $id)->update(['status' => 'paused', 'updated_at' => now()]);
                    DB::table('integration_action_outbox')->where('connection_id', $id)->where('status', 'pending')->update(['status' => 'cancelled', 'error_code' => 'connection_disabled', 'updated_at' => now()]);
                }
                if ($action === 'revoke') {
                    DB::table('credential_secrets')->where('connection_id', $id)->delete();
                    DB::table('workflow_connection_grants')->where('connection_id', $id)->delete();
                    DB::table('telegram_bots')->where('connection_id', $id)->update(['active_bot_id' => null, 'webhook_secret_hash' => null, 'webhook_configured' => false, 'approved_by' => null]);
                    DB::table('telegram_identity_links')->where('connection_id', $id)->delete();
                }
            } elseif ($action === 'rotate') {
                $this->authorization->connection($org, $id, false);
                $clean = $this->api->validateCredentials('bot_token', $credentials);
                $new = $this->api->identity($clean);
                abort_unless($new['id'] === $bot->bot_id, 422);
                DB::table('credential_secrets')->where('connection_id', $id)->update([...$this->vault->encrypt($clean, $id), 'updated_at' => now()]);
                DB::table('telegram_bots')->where('connection_id', $id)->update(['approved_by' => null, 'credential_generation' => $bot->credential_generation + 1, 'webhook_configured' => false, 'webhook_secret_hash' => null]);
                DB::table('integration_trigger_subscriptions')->where('connection_id', $id)->update(['status' => 'paused', 'updated_at' => now()]);
                DB::table('integration_action_outbox')->where('connection_id', $id)->where('status', 'pending')->update(['status' => 'cancelled', 'error_code' => 'credential_rotated', 'updated_at' => now()]);
            } elseif ($action === 'test') {
                $this->authorization->connection($org, $id, false);
                $this->api->identity($this->authorization->credentials($id));
                if ($bot->webhook_configured) {
                    $info = $this->api->call($this->authorization->credentials($id)['bot_token'], 'getWebhookInfo');
                    $expected = rtrim((string) config('integrations.telegram_webhook_origin'), '/').'/api/v1/integration-webhooks/telegram/'.$id;
                    abort_unless(is_array($info) && ($info['url'] ?? '') === $expected && empty($info['last_error_date']), 409);
                }
            } elseif ($action === 'configure-webhook') {
                $this->authorization->connection($org, $id);
                $origin = rtrim((string) config('integrations.telegram_webhook_origin'), '/');
                $parts = parse_url($origin);
                abort_unless($parts && ($parts['scheme'] ?? '') === 'https' && isset($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']) && empty($parts['path']), 422);
                $secret = bin2hex(random_bytes(32));
                $this->api->call($this->authorization->credentials($id)['bot_token'], 'setWebhook', ['url' => $origin.'/api/v1/integration-webhooks/telegram/'.$id, 'secret_token' => $secret, 'allowed_updates' => ['message', 'callback_query'], 'drop_pending_updates' => false, 'max_connections' => 10]);
                DB::table('telegram_bots')->where('connection_id', $id)->update(['webhook_secret_hash' => hash('sha256', $secret), 'webhook_configured' => true]);
            } elseif (in_array($action, ['approve-destination', 'unapprove-destination'], true)) {
                $this->authorization->connection($org, $id);
                $chat = (string) $data['chat_id'];
                $this->api->validateConfiguration('telegram.message', ['destination' => $chat]);
                if ($action === 'approve-destination') {
                    $info = $this->api->call($this->authorization->credentials($id)['bot_token'], 'getChat', ['chat_id' => $chat]);
                    abort_unless(is_array($info) && (string) ($info['id'] ?? '') === $chat && in_array($info['type'] ?? '', ['private', 'group', 'supergroup'], true), 422);
                    if ($info['type'] !== 'private') {
                        $member = $this->api->call($this->authorization->credentials($id)['bot_token'], 'getChatMember', ['chat_id' => $chat, 'user_id' => $bot->bot_id]);
                        abort_unless(in_array($member['status'] ?? '', ['member', 'administrator', 'creator'], true), 422);
                    }
                    DB::table('telegram_destinations')->updateOrInsert(['connection_id' => $id, 'chat_id' => $chat], ['chat_type' => $info['type'], 'label' => $data['label'], 'approved_by' => $user]);
                } else {
                    DB::table('telegram_destinations')->where('connection_id', $id)->where('chat_id', $chat)->update(['approved_by' => null]);
                    DB::table('integration_trigger_subscriptions')->where('connection_id', $id)->where('destination', $chat)->update(['status' => 'paused', 'updated_at' => now()]);
                }
            } elseif (in_array($action, ['grant', 'ungrant'], true)) {
                $this->authorization->connection($org, $id);
                $this->authorization->destination($c, $data['chat_id']);
                $w = DB::table('workflows')->where('uuid', $data['workflow_id'])->where('organization_id', $org)->first();
                abort_unless($w, 404);
                $this->api->validateConfiguration($data['operation'], ['destination' => $data['chat_id']]);
                if ($action === 'grant') {
                    DB::table('workflow_connection_grants')->updateOrInsert(['connection_id' => $id, 'workflow_id' => $w->id, 'operation' => $data['operation']], ['organization_id' => $org, 'authorized_by' => $user]);
                } else {
                    DB::table('workflow_connection_grants')->where('connection_id', $id)->where('workflow_id', $w->id)->where('operation', $data['operation'])->delete();
                }
            } elseif (in_array($action, ['pause-trigger', 'resume-trigger'], true)) {
                $s = DB::table('integration_trigger_subscriptions')->where('id', $data['subscription_id'])->where('connection_id', $id)->where('organization_id', $org)->first();
                abort_unless($s, 404);
                if ($action === 'resume-trigger') {
                    $this->authorization->workflow($org, $s->workflow_id, $id, 'telegram.message', $s->destination);
                }
                DB::table('integration_trigger_subscriptions')->where('id', $s->id)->update(['status' => $action === 'pause-trigger' ? 'paused' : 'active', 'updated_at' => now()]);
            } elseif ($action === 'retry-delivery') {
                $row = DB::table('integration_action_outbox')->where('id', $data['delivery_id'])->where('connection_id', $id)->where('organization_id', $org)->lockForUpdate()->first();
                abort_unless($row && in_array($row->status, ['failed', 'uncertain'], true) && $row->attempt_count < 5, 409);
                abort_if($row->status === 'uncertain' && ! ($data['acknowledge_duplicate_risk'] ?? false), 422);
                $instance = WorkflowInstance::findOrFail($row->workflow_instance_id);
                $this->authorization->workflow($org, $instance->workflow_id, $id, $row->operation, $row->destination, $instance->submitted_by);
                abort_unless((int) $bot->credential_generation === (int) $row->credential_generation, 403);
                DB::table('integration_action_outbox')->where('id', $row->id)->update(['status' => 'pending', 'available_at' => now(), 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
                ProcessTelegramDelivery::dispatch($row->id)->afterCommit();
            } else {
                abort(422);
            }
            $this->audit->audit($org, $id, $user, 'telegram.'.$action);
        });
    }
}
