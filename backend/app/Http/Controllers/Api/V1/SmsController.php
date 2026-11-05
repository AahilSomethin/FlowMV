<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Workflow;
use App\Services\Integrations\ConnectionAccess;
use App\Services\Integrations\SmsAuthorization;
use App\Services\Integrations\SmsConnections;
use App\Services\Integrations\SmsDelivery;
use App\Services\Integrations\SmsIngress;
use App\Services\Integrations\SmsWorkflow;
use App\Support\ActiveMembershipLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SmsController extends Controller
{
    public function __construct(private ActiveMembershipLocator $memberships, private SmsAuthorization $authorization, private SmsConnections $connections) {}

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
        $admin = app(ConnectionAccess::class)->role($r->user()->id, $org) === 'admin';
        $workflow = null;
        if ($r->filled('workflow_id')) {
            $d = $r->validate(['workflow_id' => 'required|uuid']);
            $workflow = Workflow::where('uuid', $d['workflow_id'])->where('organization_id', $org)->firstOrFail();
            Gate::authorize('view', $workflow);
        }
        $grants = DB::table('sms_workflow_grants')->where('organization_id', $org)->when($workflow, fn ($q) => $q->where('workflow_id', $workflow->id))->get();
        $items = DB::table('connections as c')->join('sms_accounts as a', 'a.connection_id', '=', 'c.id')->where('c.organization_id', $org)->whereNull('c.owner_user_id')->where('c.provider_id', 'twilio_sms')->when(! $admin, fn ($q) => $q->whereIn('c.id', $workflow ? $grants->pluck('connection_id') : []))->get(['c.id', 'c.name', 'c.status', 'a.health', 'a.approved_by'])->map(function ($c) use ($admin, $grants) {
            $c->approved = $c->approved_by !== null;
            unset($c->approved_by);
            foreach (['senders' => 'sender_id', 'recipients' => 'recipient_id'] as $kind => $field) {
                $c->$kind = DB::table('sms_'.$kind)->where('connection_id', $c->id)->when(! $admin, fn ($q) => $q->whereIn('id', $grants->where('connection_id', $c->id)->pluck($field)))->get($admin ? ['id', 'label', 'masked_phone', 'enabled', 'country', 'expires_at'] : ['id', 'label', 'enabled']);
            }
            $c->deliveries = $admin ? DB::table('sms_action_outbox')->where('connection_id', $c->id)->orderByDesc('created_at')->limit(20)->get(['id', 'status', 'error_code', 'attempt_count', 'created_at', 'updated_at']) : [];
            $c->grants = $admin ? DB::table('sms_workflow_grants as g')->join('workflows as w', 'w.id', '=', 'g.workflow_id')->where('g.connection_id', $c->id)->get(['w.uuid as workflow_id', 'w.name as workflow_name', 'g.operation', 'g.sender_id', 'g.recipient_id', 'g.template_id']) : [];

            return $c;
        });
        $templates = DB::table('sms_templates')->where('organization_id', $org)->when(! $admin, fn ($q) => $q->whereIn('id', $workflow ? $grants->pluck('template_id') : []))->get(['id', 'name', 'body', 'enabled']);
        $policy = $admin ? DB::table('sms_policies')->where('organization_id', $org)->first(['enabled', 'allowed_countries', 'per_minute', 'daily_limit', 'monthly_limit', 'recipient_daily_limit']) : null;

        return response()->json(['data' => ['items' => $items, 'templates' => $templates, 'policy' => $policy, 'admin' => $admin, 'enabled' => (bool) config('sms.enabled'), 'simulated' => (bool) config('sms.simulated'), 'provider_approved' => (bool) DB::table('integration_approvals')->where('organization_id', $org)->where('provider_id', 'twilio_sms')->value('approved')]]);
    }

    public function policy(Request $r): JsonResponse
    {
        $this->stepUp($r);
        $d = $r->validate(['approved' => 'required|boolean', 'enabled' => 'required|boolean', 'allowed_countries' => 'required|array|min:1|max:10', 'allowed_countries.*' => ['required', 'regex:/^[A-Z]{2}$/D'], 'per_minute' => 'required|integer|min:1|max:10', 'daily_limit' => 'required|integer|min:1|max:100', 'monthly_limit' => 'required|integer|min:1|max:1000|gte:daily_limit', 'recipient_daily_limit' => 'required|integer|min:1|max:10|lte:daily_limit']);
        $this->connections->policy($r->user()->id, $this->org($r), $d);

        return response()->json(['data' => ['updated' => true]]);
    }

    public function store(Request $r): JsonResponse
    {
        $this->stepUp($r);
        $d = $r->validate(['name' => 'required|string|max:100', 'ownership' => 'required|in:organization']);
        $credentials = $r->attributes->get('integration_input')->get('credentials', []);
        abort_unless(is_array($credentials), 422);

        return response()->json(['data' => ['id' => $this->connections->create($r->user()->id, $this->org($r), $d['name'], $credentials)]]);
    }

    public function template(Request $r): JsonResponse
    {
        $this->stepUp($r);
        $d = $r->validate(['name' => 'required|string|max:100', 'approved' => 'required|accepted']);
        $text = $r->attributes->get('integration_input')->get('template_text');
        abort_unless(is_string($text), 422);

        return response()->json(['data' => ['id' => $this->connections->template($r->user()->id, $this->org($r), $d['name'], $text)]]);
    }

    public function action(Request $r, string $organization, string $connection, string $action): JsonResponse
    {
        $this->stepUp($r);
        if ($action === 'reconcile') {
            $org = $this->org($r);
            $this->authorization->manage($r->user()->id, $org, $connection);
            $d = $r->validate(['resource_id' => 'required|uuid']);
            abort_unless(DB::table('sms_action_outbox')->where('id', $d['resource_id'])->where('organization_id', $org)->where('connection_id', $connection)->exists(), 404);
            app(SmsDelivery::class)->reconcile($d['resource_id']);

            return response()->json(['data' => ['updated' => true]]);
        }
        $rules = match ($action) {
            'approve-sender', 'approve-recipient' => ['label' => 'required|string|max:100', 'consent' => 'required|boolean'],
            'disable-sender', 'disable-recipient', 'disable-template', 'enable-template' => ['resource_id' => 'required|uuid'],
            'grant', 'ungrant' => ['workflow_id' => 'required|uuid', 'operation' => 'required|in:sms.notification,sms.send_message', 'sender_id' => 'required|uuid', 'recipient_id' => 'required|uuid', 'template_id' => 'required|uuid'],
            'rename' => ['name' => 'required|string|max:100'],
            'test', 'approve', 'disable', 'enable', 'rotate', 'revoke' => [],
            default => abort(404),
        };
        $d = $r->validate($rules);
        $private = $r->attributes->get('integration_input');
        $credentials = $private->get('credentials', []);
        $phone = $private->get('phone', '');
        abort_unless(is_array($credentials) && is_string($phone), 422);
        $id = $this->connections->action($r->user()->id, $this->org($r), $connection, $action, $d, $credentials, $phone);

        return response()->json(['data' => ['updated' => true, 'id' => $id]]);
    }

    public function preview(Request $r, SmsWorkflow $workflow): JsonResponse
    {
        $org = $this->org($r);
        $this->authorization->admin($r->user()->id, $org);
        $d = $r->validate(['template_id' => 'required|uuid']);
        $t = DB::table('sms_templates')->where('id', $d['template_id'])->where('organization_id', $org)->where('enabled', true)->first();
        abort_unless($t, 404);

        return response()->json(['data' => ['text' => $workflow->render($t->body, '00000000-0000-4000-8000-000000000000', 'completed', 'approved'), 'segments' => 1, 'external_delivery' => false]]);
    }

    public function webhook(Request $r, string $connection, string $delivery, SmsIngress $ingress): Response
    {
        abort_unless(str_starts_with(strtolower($r->header('Content-Type', '')), 'application/x-www-form-urlencoded') && $r->query->count() === 0, 403);
        $p = $r->attributes->get('integration_input');
        $ingress->receive($connection, $delivery, (string) $p->get('sms_raw', ''), (string) $p->get('sms_signature', ''));

        return response()->noContent();
    }
}
