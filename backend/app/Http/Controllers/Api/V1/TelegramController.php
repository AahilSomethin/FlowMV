<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Integrations\ConnectionAccess;
use App\Services\Integrations\ConnectionManager;
use App\Services\Integrations\TelegramApi;
use App\Services\Integrations\TelegramConnections;
use App\Services\Integrations\TelegramIdentity;
use App\Services\Integrations\TelegramIngress;
use App\Services\Integrations\TelegramQaTransport;
use App\Support\ActiveMembershipLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TelegramController extends Controller
{
    public function __construct(private ActiveMembershipLocator $memberships, private ConnectionAccess $access, private TelegramConnections $connections) {}

    private function org(Request $r): int
    {
        return $this->memberships->organizationFrom($r)->id;
    }

    private function stepUp(Request $r): void
    {
        abort_unless($r->hasSession() && $r->session()->get('integration_step_up.user') === $r->user()->id && $r->session()->get('integration_step_up.at', 0) > now()->timestamp - config('integrations.step_up_seconds') && $r->session()->get('integration_step_up.at', 0) <= now()->timestamp, 428);
    }

    public function index(Request $r, TelegramApi $api): JsonResponse
    {
        $org = $this->org($r);
        $role = $this->access->role($r->user()->id, $org);
        $admin = $role === 'admin';
        $items = DB::table('connections as c')->join('telegram_bots as b', 'b.connection_id', '=', 'c.id')->where('c.organization_id', $org)->where('c.provider_id', 'telegram')
            ->where(function ($q) use ($admin, $r) {
                if (! $admin) {
                    $q->whereNull('c.owner_user_id')->orWhere('c.owner_user_id', $r->user()->id);
                }
            })
            ->select('c.id', 'c.name', 'c.owner_user_id', 'c.status', 'b.username', 'b.approved_by', 'b.webhook_configured')->orderByDesc('c.created_at')->limit(200)->get()->map(function ($c) use ($admin, $r, $role) {
                $c->can_manage = $c->owner_user_id === null ? $admin : (int) $c->owner_user_id === $r->user()->id;
                $c->ownership = $c->owner_user_id === null ? 'organization' : 'personal';
                unset($c->owner_user_id);
                $c->approved = $c->approved_by !== null;
                unset($c->approved_by);
                $c->destinations = DB::table('telegram_destinations')->where('connection_id', $c->id)->select('chat_id', 'label', 'chat_type', 'approved_by')->get()->map(fn ($d) => ['chat_id' => $d->chat_id, 'label' => $d->label, 'chat_type' => $d->chat_type, 'approved' => $d->approved_by !== null]);
                $c->grants = DB::table('workflow_connection_grants as g')->join('workflows as w', 'w.id', '=', 'g.workflow_id')->where('g.connection_id', $c->id)->when($role === 'employee', fn ($q) => $q->where('w.status', 'published'))->select('w.uuid as workflow_id', 'w.name as workflow_name', 'g.operation')->get();
                $c->subscriptions = DB::table('integration_trigger_subscriptions as s')->join('workflow_versions as v', 'v.id', '=', 's.workflow_version_id')->join('workflows as w', 'w.id', '=', 's.workflow_id')->where('s.connection_id', $c->id)->when($role === 'employee', fn ($q) => $q->where('w.status', 'published'))->select('s.id', 's.status', 's.destination', 'v.version_number', 'w.name as workflow_name')->get();
                $c->identity = DB::table('telegram_identity_links')->where('connection_id', $c->id)->where('user_id', $r->user()->id)->select('telegram_user_id', 'confirmed', 'expires_at')->first();
                $c->approval_deliveries = Schema::hasTable('telegram_approval_deliveries') ? DB::table('telegram_approval_deliveries as d')->join('workflow_tasks as t', 't.id', '=', 'd.workflow_task_id')->join('workflow_instances as i', 'i.id', '=', 't.workflow_instance_id')->where('d.connection_id', $c->id)->when(! $admin, fn ($q) => $q->where('d.recipient_user_id', $r->user()->id))->orderByDesc('d.created_at')->limit(20)->get(['d.id', 'd.status', 'd.error_code', 'd.attempt_count', 'd.recipient_user_id', 't.status as task_status', 'i.uuid as instance_uuid'])->map(function ($d) use ($r) {
                    $d->is_mine = (int) $d->recipient_user_id === $r->user()->id;
                    $d->can_decide = DB::table('telegram_decision_capabilities')->where('delivery_id', $d->id)->whereNull('consumed_at')->where('expires_at', '>', now())->exists();
                    unset($d->recipient_user_id);

                    return $d;
                }) : [];
                $c->deliveries = $admin ? DB::table('integration_action_outbox as o')->join('workflow_instances as i', 'i.id', '=', 'o.workflow_instance_id')->where('o.connection_id', $c->id)->select('o.id', 'o.status', 'o.error_code', 'o.attempt_count', 'i.uuid as instance_uuid')->orderByDesc('o.created_at')->limit(20)->get() : [];
                $c->audit = $admin ? DB::table('integration_audit_events')->where('organization_id', $this->org($r))->where('connection_id', $c->id)->orderByDesc('id')->limit(20)->get(['id', 'event', 'created_at'])->map(fn ($event) => ['id' => $event->id, 'event' => $event->event, 'created_at' => Carbon::parse($event->created_at, config('app.timezone'))->utc()->toIso8601String()]) : [];

                return $c;
            });

        return response()->json(['data' => ['items' => $items, 'admin' => $admin, 'enabled' => (bool) config('integrations.telegram_enabled'), 'simulated' => (bool) config('integrations.telegram_simulated'), 'provider_approved' => (bool) DB::table('integration_approvals')->where('organization_id', $org)->where('provider_id', 'telegram')->value('approved'), 'personal_connections_allowed' => (bool) DB::table('integration_policies')->where('organization_id', $org)->value('personal_connections_allowed'), 'provider' => $api->manifest()]]);
    }

    public function policy(Request $r, ConnectionManager $audit): JsonResponse
    {
        $this->stepUp($r);
        $org = $this->org($r);
        $d = $r->validate(['approved' => 'required|boolean']);
        DB::transaction(function () use ($r, $org, $d, $audit) {
            abort_unless($this->access->role($r->user()->id, $org) === 'admin', 403);
            DB::table('integration_approvals')->updateOrInsert(['organization_id' => $org, 'provider_id' => 'telegram'], ['approved' => $d['approved']]);
            if (! $d['approved']) {
                DB::table('integration_trigger_subscriptions')->where('organization_id', $org)->update(['status' => 'paused', 'updated_at' => now()]);
            }
            $audit->audit($org, null, $r->user()->id, 'telegram.provider.policy');
        });

        return response()->json(['data' => ['updated' => true]]);
    }

    public function simulate(Request $r, string $organization, string $connection, TelegramQaTransport $transport): JsonResponse
    {
        $this->stepUp($r);
        $org = $this->org($r);
        abort_unless($this->access->role($r->user()->id, $org) === 'admin', 403);
        abort_unless(DB::table('connections')->where('id', $connection)->where('organization_id', $org)->where('provider_id', 'telegram')->exists(), 404);
        $transport->assertLocal();
        $d = $r->validate(['sender_id' => ['required', 'string', 'regex:/^[0-9]{1,15}$/D'], 'chat_id' => ['required', 'string', 'regex:/^-?[0-9]{1,15}$/D']]);
        $text = $r->attributes->get('integration_input')->get('simulation_text');
        abort_unless(is_string($text) && $text !== '' && mb_strlen($text) <= 4096, 422);

        return response()->json(['data' => $transport->emit($connection, $d['sender_id'], $d['chat_id'], $text)]);
    }

    public function store(Request $r): JsonResponse
    {
        $this->stepUp($r);
        $d = $r->validate(['name' => ['required', 'string', 'max:100', 'regex:/^[\pL\pN ._-]+$/u'], 'ownership' => 'required|in:personal,organization']);
        $credentials = $r->attributes->get('integration_input')->get('credentials', []);
        abort_unless(is_array($credentials), 422);
        $id = $this->connections->create($r->user()->id, $this->org($r), $d, $credentials);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function action(Request $r, string $organization, string $connection, string $action): JsonResponse
    {
        $this->stepUp($r);
        abort_unless(Str::isUuid($connection), 404);
        $rules = match ($action) {
            'approve-destination' => ['chat_id' => ['required', 'string', 'regex:/^-?[0-9]{1,20}$/D'], 'label' => ['required', 'string', 'max:100', 'regex:/^[\pL\pN ._-]+$/u']],
            'unapprove-destination' => ['chat_id' => ['required', 'string', 'regex:/^-?[0-9]{1,20}$/D']],
            'grant','ungrant' => ['workflow_id' => 'required|uuid', 'operation' => 'required|in:telegram.message,telegram.send_message,telegram.notification,telegram.approval', 'chat_id' => ['required', 'string', 'regex:/^-?[0-9]{1,20}$/D']],
            'pause-trigger','resume-trigger' => ['subscription_id' => 'required|uuid'],
            'retry-delivery' => ['delivery_id' => 'required|uuid', 'acknowledge_duplicate_risk' => 'sometimes|boolean'],
            default => [],
        };
        $d = $r->validate($rules);
        $credentials = $r->attributes->get('integration_input')->get('credentials', []);
        abort_unless(is_array($credentials), 422);
        $this->connections->action($r->user()->id, $this->org($r), $connection, $action, $d, $credentials);

        return response()->json(['data' => ['completed' => true]]);
    }

    public function link(Request $r, string $organization, string $connection, string $stage, TelegramIdentity $identity): JsonResponse
    {
        $this->stepUp($r);
        abort_unless(Str::isUuid($connection), 404);
        $org = $this->org($r);
        if ($stage === 'start') {
            return response()->json(['data' => ['command' => $identity->begin($r->user()->id, $org, $connection)]]);
        }
        if ($stage === 'confirm') {
            $identity->confirm($r->user()->id, $org, $connection);
        } elseif ($stage === 'unlink') {
            $this->access->role($r->user()->id, $org);
            abort_unless(DB::table('connections')->where('id', $connection)->where('organization_id', $org)->where('provider_id', 'telegram')->exists(), 404);
            DB::table('telegram_identity_links')->where('connection_id', $connection)->where('user_id', $r->user()->id)->delete();
            app(ConnectionManager::class)->audit($org, $connection, $r->user()->id, 'telegram.identity.unlinked');
        } else {
            abort(404);
        }

        return response()->json(['data' => ['completed' => true]]);
    }

    public function webhook(Request $r, string $connection, TelegramIngress $ingress): JsonResponse
    {
        abort_unless(Str::isUuid($connection), 403);
        $private = $r->attributes->get('integration_input');
        $update = $private->get('telegram_update', []);
        abort_unless(is_array($update), 422);

        return response()->json(['data' => $ingress->receive($connection, (string) $private->get('telegram_webhook_secret'), $update)]);
    }

    public function approvalSimulation(Request $r, string $organization, string $connection, TelegramQaTransport $qa): JsonResponse
    {
        $qa->assertLocal();
        $this->stepUp($r);
        $org = $this->org($r);
        $this->access->role($r->user()->id, $org);
        $d = $r->validate(['delivery_id' => 'required|uuid', 'decision' => 'required|in:approved,rejected']);
        $delivery = DB::table('telegram_approval_deliveries')->where('id', $d['delivery_id'])->where('organization_id', $org)->where('connection_id', $connection)->where('recipient_user_id', $r->user()->id)->where('status', 'accepted')->first();
        abort_unless($delivery, 404);

        return response()->json(['data' => $qa->decision($delivery, $d['decision'])]);
    }

    public function identitySimulation(Request $r, string $organization, string $connection, TelegramQaTransport $qa): JsonResponse
    {
        $qa->assertLocal();
        $this->stepUp($r);
        $org = $this->org($r);
        $this->access->role($r->user()->id, $org);
        $c = DB::table('connections')->where('id', $connection)->where('organization_id', $org)->where('provider_id', 'telegram')->first();
        abort_unless($c, 404);
        $d = $r->validate(['sender_id' => ['required', 'string', 'regex:/^[0-9]{1,15}$/D']]);
        $text = $r->attributes->get('integration_input')->get('simulation_text', '');
        abort_unless(is_string($text) && preg_match('/^\/flowmv_link ([a-f0-9]{64})$/D', $text, $match), 422);
        abort_unless(DB::table('telegram_identity_links')->where('connection_id', $connection)->where('user_id', $r->user()->id)->where('challenge_hash', hash('sha256', $match[1]))->where('expires_at', '>', now())->where('confirmed', false)->exists(), 403);

        return response()->json(['data' => $qa->emit($connection, $d['sender_id'], $d['sender_id'], $text)]);
    }
}
