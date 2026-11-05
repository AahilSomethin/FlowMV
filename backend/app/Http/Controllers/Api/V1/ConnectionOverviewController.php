<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Integrations\ConnectionAccess;
use App\Support\ActiveMembershipLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ConnectionOverviewController extends Controller
{
    public function __invoke(Request $request, ActiveMembershipLocator $memberships, ConnectionAccess $access): JsonResponse
    {
        $org = $memberships->organizationFrom($request);
        $admin = $access->role($request->user()->id, $org->id) === 'admin';
        $rows = DB::table('connections')->where('organization_id', $org->id)->where(fn ($q) => $q->whereNull('owner_user_id')->orWhere('owner_user_id', $request->user()->id))->get(['id', 'name', 'provider_id', 'owner_user_id', 'status']);
        $ids = $rows->pluck('id');
        $evidence = DB::table('integration_audit_events')->where('organization_id', $org->id)->whereIn('connection_id', $ids)->whereNotNull('evidence_source')->selectRaw('connection_id, max(id) as last_id')->groupBy('connection_id');
        $last = DB::table('integration_audit_events')->whereIn('id', $evidence->pluck('last_id'))->get(['connection_id', 'event', 'evidence_source', 'created_at'])->keyBy('connection_id');
        $approvals = DB::table('integration_approvals')->where('organization_id', $org->id)->pluck('approved', 'provider_id');
        $providers = ['gmail' => ['Gmail', '/connections/email', 'OAuth mailbox and recipient approval'], 'outlook' => ['Outlook', '/connections/email', 'OAuth mailbox and recipient approval'], 'telegram' => ['Telegram Bot', '/connections/telegram', 'Bot, approved chat and confirmed identity'], 'slack' => ['Slack', '/connections/slack', 'OAuth workspace, approved channel and identity'], 'twilio_sms' => ['SMS / Twilio', '/connections/sms', 'Organization account, sender, consent and volume policy']];
        $items = [];
        foreach ($providers as $provider => [$name, $path, $setup]) {
            $visible = $rows->where('provider_id', $provider);
            $items[] = ['provider_id' => $provider, 'name' => $name, 'path' => $path, 'setup' => $setup, 'provider_approved' => (bool) ($approvals[$provider] ?? false), 'connections' => $visible->map(function ($c) use ($last, $admin) {
                $operation = $last->get($c->id);

                return ['id' => $c->id, 'name' => $c->name, 'ownership' => $c->owner_user_id === null ? 'organization' : 'personal', 'status' => $c->status, 'can_manage' => $c->owner_user_id === null ? $admin : true, 'last_operation' => $operation ? ['operation' => $operation->event, 'source' => $operation->evidence_source, 'at' => Carbon::parse($operation->created_at, config('app.timezone'))->utc()->toIso8601String()] : null];
            })->values()];
        }

        return response()->json(['data' => ['providers' => $items, 'notice' => 'Connection status does not grant workflow access. Historical operations without provenance remain unverified.']]);
    }
}
