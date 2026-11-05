<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WorkflowInstance;
use App\Services\AI\AiAccess;
use App\Services\AI\AiConnections;
use App\Services\AI\AiProcessing;
use App\Services\Workflows\Execution\WorkflowInstanceVisibility;
use App\Support\ActiveMembershipLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AiController extends Controller
{
    public function __construct(private ActiveMembershipLocator $memberships, private AiAccess $access) {}

    private function org(Request $request): int
    {
        return $this->memberships->organizationFrom($request)->id;
    }

    private function stepUp(Request $request): void
    {
        abort_unless($request->hasSession() && $request->session()->get('integration_step_up.user') === $request->user()->id && $request->session()->get('integration_step_up.at', 0) > now()->timestamp - config('integrations.step_up_seconds') && $request->session()->get('integration_step_up.at', 0) <= now()->timestamp, 428);
    }

    public function index(Request $request): JsonResponse
    {
        $org = $this->org($request);
        $user = $request->user()->id;
        $role = $this->access->role($user, $org);
        $policy = DB::table('ai_policies')->where('organization_id', $org)->first();
        $connections = DB::table('connections as c')->join('ai_connections as a', 'a.connection_id', '=', 'c.id')->where('c.organization_id', $org)->where(fn ($q) => $q->whereNull('c.owner_user_id')->orWhere('c.owner_user_id', $user)->when($role === 'admin', fn ($q) => $q->orWhere('a.approval_requested', true)))->select(['c.id', 'c.name', 'c.provider_id', 'c.owner_user_id', 'c.status', 'a.model', 'a.operations', 'a.max_input_bytes', 'a.max_output_tokens', 'a.daily_units', 'a.approved_by', 'a.approval_requested'])->get()->map(function ($c) use ($role, $org, $user) {
            $approved = $c->approved_by !== null && DB::table('organization_memberships')->where('organization_id', $org)->where('user_id', $c->approved_by)->whereNull('revoked_at')->where('role', 'admin')->exists();
            $grants = DB::table('ai_workflow_grants as g')->join('workflows as w', 'w.id', '=', 'g.workflow_id')->where('g.organization_id', $org)->where('g.connection_id', $c->id)->get(['w.uuid as workflow_id', 'w.name as workflow_name', 'g.operation']);

            return ['id' => $c->id, 'name' => $c->name, 'provider' => substr($c->provider_id, 3), 'ownership' => $c->owner_user_id === null ? 'organization' : 'personal', 'status' => $c->status, 'model' => $c->model, 'operations' => json_decode($c->operations, true), 'max_input_bytes' => $c->max_input_bytes, 'max_output_tokens' => $c->max_output_tokens, 'daily_units' => $c->daily_units, 'approved' => $approved, 'approval_requested' => (bool) $c->approval_requested, 'can_approve' => $role === 'admin' && ($c->owner_user_id === null || (int) $c->owner_user_id === $user || $c->approval_requested), 'can_manage' => $c->owner_user_id === null ? $role === 'admin' : (int) $c->owner_user_id === $user, 'grants' => $grants];
        });
        $runs = DB::table('ai_processing_runs')->where('organization_id', $org)->where('actor_user_id', $user)->orderByDesc('created_at')->limit(30)->get(['id', 'operation', 'status', 'provenance', 'requested_model', 'error_code', 'created_at', 'expires_at']);
        $safePolicy = $policy ? [...(array) $policy, 'providers' => json_decode($policy->providers, true), 'data_categories' => json_decode($policy->data_categories, true)] : null;

        return response()->json(['data' => ['enabled' => (bool) config('ai.enabled'), 'simulated' => (bool) config('ai.simulated'), 'admin' => $role === 'admin', 'personal_connections_allowed' => (bool) DB::table('integration_policies')->where('organization_id', $org)->value('personal_connections_allowed'), 'policy' => $safePolicy, 'models' => config('ai.models'), 'operations' => AiAccess::OPERATIONS, 'items' => $connections, 'runs' => $runs, 'notice' => 'AI suggestions are advisory. Model metadata tests do not send workplace content. Processing requires an approved data policy and workflow grant; provider responses may be incorrect.']]);
    }

    public function policy(Request $request, AiConnections $connections): JsonResponse
    {
        $this->stepUp($request);
        $connections->policy($request->user()->id, $this->org($request), $request->attributes->get('integration_input')->get('data', []));

        return response()->json(['message' => 'AI policy updated. Existing pending analyses were cancelled.']);
    }

    public function store(Request $request, AiConnections $connections): JsonResponse
    {
        $this->stepUp($request);
        $input = $request->attributes->get('integration_input');
        $id = $connections->create($request->user()->id, $this->org($request), $input->get('data', []), $input->get('credentials', []));

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function action(Request $request, string $organization, string $connection, string $action, AiConnections $connections): JsonResponse
    {
        $this->stepUp($request);
        $org = $this->org($request);
        if ($action === 'test') {
            $connections->test($request->user()->id, $org, $connection);
        } else {
            $input = $request->attributes->get('integration_input');
            $connections->action($request->user()->id, $org, $connection, $action, $input->get('data', []), $input->get('credentials', []));
        }

        return response()->json(['message' => 'AI connection updated.']);
    }

    public function analyze(Request $request, AiProcessing $processing): JsonResponse
    {
        $org = $this->org($request);
        $data = $request->attributes->get('integration_input')->get('data', []);
        $clean = Validator::make($data, ['workflow_id' => 'required|uuid', 'connection_id' => 'required|uuid', 'operation' => ['required', Rule::in(AiAccess::OPERATIONS)], 'source_kind' => 'required|in:request,email,document,slack', 'source_id' => 'required|uuid', 'configuration' => 'required|array'])->validate();
        $workflow = DB::table('workflows')->where('organization_id', $org)->where('uuid', $clean['workflow_id'])->first();
        abort_unless($workflow, 404);
        $id = $processing->request($request->user()->id, $org, $workflow->id, $clean['connection_id'], $clean['operation'], $clean['source_kind'], $clean['source_id'], $clean['configuration']);

        return response()->json(['data' => ['id' => $id, 'status' => 'queued']], 202);
    }

    public function result(Request $request, string $organization, string $run, AiProcessing $processing): JsonResponse
    {
        return response()->json(['data' => $processing->result($request->user()->id, $this->org($request), $run)]);
    }

    public function requestAdvice(Request $request, string $organization, string $instance, AiProcessing $processing): JsonResponse
    {
        $org = $this->org($request);
        $this->access->role($request->user()->id, $org);
        $record = WorkflowInstance::where('organization_id', $org)->where('uuid', $instance)->firstOrFail();
        abort_unless(app(WorkflowInstanceVisibility::class)->canView($request->user(), $record), 404);
        $runs = DB::table('ai_processing_runs')->where('organization_id', $org)->where('workflow_instance_id', $record->id)->orderByDesc('created_at')->limit(20)->get();
        $results = [];
        foreach ($runs as $run) {
            try {
                $results[] = $processing->result($request->user()->id, $org, $run->id);
            } catch (\Throwable) {
                $results[] = ['id' => $run->id, 'status' => 'unavailable', 'operation' => $run->operation, 'source' => 'unknown', 'requested_model' => $run->requested_model, 'resolved_model' => null, 'error_code' => 'authorization_or_retention', 'expires_at' => null];
            }
        }

        return response()->json(['data' => ['items' => $results]]);
    }
}
