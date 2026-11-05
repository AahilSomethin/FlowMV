<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Integrations\ConnectionAccess;
use App\Services\Integrations\ConnectionManager;
use App\Services\Integrations\CredentialVault;
use App\Services\Integrations\SlackApi;
use App\Services\Integrations\SlackAuthorization;
use App\Services\Integrations\SlackIngress;
use App\Services\Integrations\SlackOAuth;
use App\Services\Integrations\SlackQaTransport;
use App\Services\Integrations\SlackTokens;
use App\Support\ActiveMembershipLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SlackController extends Controller
{
    public function __construct(private ActiveMembershipLocator $memberships, private ConnectionAccess $access, private SlackAuthorization $authorization, private ConnectionManager $audit) {}

    private function org(Request $r): int
    {
        return $this->memberships->organizationFrom($r)->id;
    }

    private function stepUp(Request $r): void
    {
        abort_unless($r->hasSession() && $r->session()->get('integration_step_up.user') === $r->user()->id && $r->session()->get('integration_step_up.at', 0) > now()->timestamp - config('integrations.step_up_seconds') && $r->session()->get('integration_step_up.at', 0) <= now()->timestamp, 428);
    }

    public function index(Request $r): JsonResponse
    {
        $org = $this->org($r);
        $admin = $this->access->role($r->user()->id, $org) === 'admin';
        $items = DB::table('connections as c')->join('slack_installations as i', 'i.connection_id', '=', 'c.id')->where('c.organization_id', $org)->where('c.provider_id', 'slack')->where(function ($q) use ($r, $admin) {
            if (! $admin) {
                $q->whereNull('c.owner_user_id')->orWhere('c.owner_user_id', $r->user()->id);
            }
        })->get(['c.id', 'c.name', 'c.status', 'c.owner_user_id', 'i.workspace_id', 'i.health', 'i.approved_by'])->map(function ($c) use ($r, $admin) {
            $c->can_manage = $c->owner_user_id === null ? $admin : (int) $c->owner_user_id === $r->user()->id;
            $c->ownership = $c->owner_user_id === null ? 'organization' : 'personal';
            $c->approved = $c->approved_by !== null;
            unset($c->owner_user_id, $c->approved_by);
            $c->channels = DB::table('slack_channels')->where('connection_id', $c->id)->get(['channel_id', 'label']);
            $c->grants = $c->can_manage ? DB::table('slack_workflow_grants as g')->join('workflows as w', 'w.id', '=', 'g.workflow_id')->where('g.connection_id', $c->id)->get(['w.uuid as workflow_id', 'w.name as workflow_name', 'g.operation', 'g.destination']) : [];
            $c->identity = DB::table('slack_identity_links')->where('connection_id', $c->id)->where('user_id', $r->user()->id)->first(['slack_user_id', 'confirmed']);
            $c->deliveries = $c->can_manage ? DB::table('slack_action_outbox')->where('connection_id', $c->id)->orderByDesc('created_at')->limit(20)->get(['id', 'status', 'error_code', 'attempt_count']) : [];

            return $c;
        });

        return response()->json(['data' => ['items' => $items, 'admin' => $admin, 'enabled' => (bool) config('slack.enabled'), 'simulated' => (bool) config('slack.simulated'), 'provider_approved' => (bool) DB::table('integration_approvals')->where('organization_id', $org)->where('provider_id', 'slack')->value('approved')]]);
    }

    public function policy(Request $r): JsonResponse
    {
        $this->stepUp($r);
        $org = $this->org($r);
        $d = $r->validate(['approved' => 'required|boolean']);
        DB::transaction(function () use ($r, $org, $d) {
            abort_unless($this->access->role($r->user()->id, $org) === 'admin', 403);
            DB::table('integration_approvals')->updateOrInsert(['organization_id' => $org, 'provider_id' => 'slack'], ['approved' => $d['approved']]);
            if (! $d['approved']) {
                DB::table('slack_trigger_subscriptions')->where('organization_id', $org)->update(['status' => 'paused', 'updated_at' => now()]);
            }
            $this->audit->audit($org, null, $r->user()->id, 'slack.provider.policy');
        });

        return response()->json(['data' => ['updated' => true]]);
    }

    public function oauth(Request $r, string $organization, string $stage, SlackOAuth $oauth): JsonResponse
    {
        $this->stepUp($r);
        $org = $this->org($r);
        if ($stage === 'start') {
            $d = $r->validate(['name' => 'required|string|max:100', 'ownership' => 'required|in:personal,organization', 'connection_id' => 'nullable|uuid']);

            return response()->json(['data' => $oauth->start($r, $org, $d)]);
        }
        abort_unless($stage === 'finish', 404);
        $p = $r->attributes->get('integration_input');

        return response()->json(['data' => ['id' => $oauth->finish($r, $org, (string) $p->get('state', ''), (string) $p->get('code', ''))]]);
    }

    public function action(Request $r, string $organization, string $connection, string $action, SlackApi $api, SlackTokens $tokens): JsonResponse
    {
        $this->stepUp($r);
        $org = $this->org($r);
        $result = DB::transaction(function () use ($r, $connection, $action, $api, $tokens, $org) {
            if (in_array($action, ['approve', 'approve-channel'], true)) {
                abort_unless($this->access->role($r->user()->id, $org) === 'admin', 403);
                $c = DB::table('connections')->where('id', $connection)->where('organization_id', $org)->where('provider_id', 'slack')->lockForUpdate()->first();
                abort_unless($c, 404);
            } else {
                $c = $this->authorization->manage($r->user()->id, $org, $connection);
            }
            abort_unless($c->status !== 'revoked', 409);
            if ($action === 'approve') {
                $this->authorization->approved($org);
                DB::table('slack_installations')->where('connection_id', $connection)->update(['approved_by' => $r->user()->id, 'health' => 'healthy']);
            } elseif ($action === 'test') {
                $this->authorization->connection($org, $connection, false);
                $i = DB::table('slack_installations')->where('connection_id', $connection)->first();
                $identity = $api->call('auth.test', $tokens->access($connection));
                abort_unless(($identity['team_id'] ?? '') === $i->workspace_id && ($identity['user_id'] ?? '') === $i->bot_user_id, 403);
            } elseif ($action === 'channels') {
                $this->authorization->connection($org, $connection);
                $d = $r->validate(['cursor' => 'nullable|string|max:512']);
                $list = $api->call('conversations.list', $tokens->access($connection), ['types' => 'public_channel,private_channel', 'exclude_archived' => true, 'limit' => 100, 'cursor' => $d['cursor'] ?? '']);

                return ['channels' => collect($list['channels'] ?? [])->filter(fn ($ch) => ($ch['is_member'] ?? false) && ! ($ch['is_archived'] ?? true))->map(fn ($ch) => ['channel_id' => $ch['id'], 'label' => mb_substr($ch['name'], 0, 100)])->values(), 'cursor' => $list['response_metadata']['next_cursor'] ?? ''];
            } elseif ($action === 'approve-channel') {
                $this->authorization->connection($org, $connection);
                $d = $r->validate(['destination' => ['required', 'string', 'regex:/^[CG][A-Z0-9]{8,30}$/D']]);
                $channel = $api->call('conversations.info', $tokens->access($connection), ['channel' => $d['destination']])['channel'] ?? [];
                abort_unless(($channel['id'] ?? '') === $d['destination'] && ($channel['is_member'] ?? false) && ! ($channel['is_archived'] ?? true), 403);
                DB::table('slack_channels')->updateOrInsert(['connection_id' => $connection, 'channel_id' => $d['destination']], ['label' => mb_substr($channel['name'] ?? $d['destination'], 0, 100), 'approved_by' => $r->user()->id]);
            } elseif (in_array($action, ['grant', 'ungrant'], true)) {
                $this->authorization->connection($org, $connection);
                $d = $r->validate(['workflow_id' => 'required|uuid', 'operation' => 'required|in:slack.message,slack.send_message,slack.notification', 'destination' => ['required', 'string', 'regex:/^[CG][A-Z0-9]{8,30}$/D']]);
                $w = DB::table('workflows')->where('uuid', $d['workflow_id'])->where('organization_id', $org)->first();
                abort_unless($w && DB::table('slack_channels')->where('connection_id', $connection)->where('channel_id', $d['destination'])->exists(), 404);
                $key = ['organization_id' => $org, 'connection_id' => $connection, 'workflow_id' => $w->id, 'operation' => $d['operation'], 'destination' => $d['destination']];
                if ($action === 'grant') {
                    DB::table('slack_workflow_grants')->updateOrInsert($key, ['authorized_by' => $r->user()->id]);
                } else {
                    DB::table('slack_workflow_grants')->where($key)->delete();
                }
            } elseif (in_array($action, ['disable', 'enable', 'revoke'], true)) {
                DB::table('connections')->where('id', $connection)->update(['status' => ['disable' => 'disabled', 'enable' => 'enabled', 'revoke' => 'revoked'][$action], 'updated_at' => now()]);
                if ($action !== 'enable') {
                    DB::table('slack_trigger_subscriptions')->where('connection_id', $connection)->update(['status' => 'paused', 'updated_at' => now()]);
                    DB::table('slack_action_outbox')->where('connection_id', $connection)->where('status', 'pending')->update(['status' => 'cancelled', 'error_code' => 'connection_disabled', 'updated_at' => now()]);
                }
                if ($action === 'revoke') {
                    try {
                        $secret = DB::table('credential_secrets')->where('connection_id', $connection)->first();
                        abort_unless($secret, 403);
                        $credentials = app(CredentialVault::class)->decrypt($secret, $connection);
                        $result = $api->call('auth.revoke', $credentials['access_token']);
                        abort_unless(($result['revoked'] ?? false) === true, 503);
                        $this->audit->audit($org, $connection, $r->user()->id, 'slack.provider.revoked');
                    } catch (\Throwable) {
                        $this->audit->audit($org, $connection, $r->user()->id, 'slack.provider.revocation_unconfirmed');
                    }
                    DB::table('credential_secrets')->where('connection_id', $connection)->delete();
                    DB::table('slack_installations')->where('connection_id', $connection)->update(['active_workspace_id' => null, 'approved_by' => null, 'health' => 'revoked']);
                    DB::table('slack_workflow_grants')->where('connection_id', $connection)->delete();
                    DB::table('slack_identity_links')->where('connection_id', $connection)->delete();
                }
            } elseif ($action === 'rename') {
                $d = $r->validate(['name' => 'required|string|max:100']);
                DB::table('connections')->where('id', $connection)->update(['name' => $d['name'], 'updated_at' => now()]);
            } else {
                abort(422);
            }
            $this->audit->audit($org, $connection, $r->user()->id, 'slack.connection.'.$action);

            return ['updated' => true];
        });

        return response()->json(['data' => $result]);
    }

    public function identity(Request $r, string $organization, string $connection, string $stage): JsonResponse
    {
        $this->stepUp($r);
        $org = $this->org($r);
        $result = DB::transaction(function () use ($r, $org, $connection, $stage) {
            $this->access->role($r->user()->id, $org);
            $c = $this->authorization->connection($org, $connection);
            abort_unless($c->owner_user_id === null || (int) $c->owner_user_id === $r->user()->id, 403);
            if ($stage === 'start') {
                $challenge = bin2hex(random_bytes(32));
                DB::table('slack_identity_links')->updateOrInsert(['connection_id' => $connection, 'user_id' => $r->user()->id], ['challenge_hash' => hash('sha256', $challenge), 'expires_at' => now()->addMinutes(10), 'slack_user_id' => null, 'confirmed' => false]);

                return ['command' => '/flowmv_link '.$challenge];
            }
            abort_unless($stage === 'confirm', 404);
            $changed = DB::table('slack_identity_links')->where('connection_id', $connection)->where('user_id', $r->user()->id)->whereNotNull('slack_user_id')->whereNull('challenge_hash')->where('expires_at', '>', now())->update(['confirmed' => true, 'expires_at' => null]);
            abort_unless($changed, 409);
            $this->audit->audit($org, $connection, $r->user()->id, 'slack.identity.confirmed');

            return ['confirmed' => true];
        });

        return response()->json(['data' => $result]);
    }

    public function webhook(Request $r, string $kind, SlackIngress $ingress): JsonResponse
    {
        abort_unless(in_array($kind, ['events', 'commands'], true), 404);
        $p = $r->attributes->get('integration_input');
        $result = $ingress->receive((string) $p->get('slack_raw', ''), (string) $p->get('slack_timestamp', ''), (string) $p->get('slack_signature', ''), $kind === 'commands');

        return response()->json(isset($result['challenge']) ? $result : ['ok' => true]);
    }

    public function simulation(Request $r, string $organization, string $stage, SlackQaTransport $qa): JsonResponse
    {
        $qa->assertLocal();
        $this->stepUp($r);
        $org = $this->org($r);
        if ($stage === 'connect') {
            $d = $r->validate(['name' => 'required|string|max:100', 'ownership' => 'required|in:personal,organization', 'connection_id' => 'nullable|uuid']);

            return response()->json(['data' => ['id' => $qa->connect($r, $org, $d)]]);
        }
        abort_unless($stage === 'event', 404);
        $d = $r->validate(['connection_id' => 'required|uuid', 'sender_id' => ['required', 'regex:/^U[0-9]{8}$/D'], 'command' => 'required|in:/flowmv_link,/request,/leave', 'event_key' => 'required|string|max:64']);
        abort_unless($this->access->role($r->user()->id, $org) === 'admin', 403);
        $this->authorization->manage($r->user()->id, $org, $d['connection_id']);
        $text = $r->attributes->get('integration_input')->get('simulation_text', 'Synthetic workplace request');
        abort_unless(is_string($text) && strlen($text) <= 2000, 422);

        return response()->json(['data' => $qa->event($d['connection_id'], $d['sender_id'], $d['command'], $text, $d['event_key'])]);
    }
}
