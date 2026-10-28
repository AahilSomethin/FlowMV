<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Integrations\ConnectionAccess;
use App\Services\Integrations\ConnectionManager;
use App\Services\Integrations\IntegrationRuntime;
use App\Services\Integrations\MockConnector;
use App\Services\Integrations\MockOAuth;
use App\Support\ActiveMembershipLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ConnectionController extends Controller
{
    public function __construct(private ConnectionAccess $access, private ConnectionManager $manager, private ActiveMembershipLocator $memberships) {}

    private function organization(Request $request): int
    {
        return $this->memberships->organizationFrom($request)->id;
    }

    private function stepUp(Request $request): void
    {
        abort_unless($request->hasSession() && $request->session()->get('integration_step_up.user') === $request->user()->id && $request->session()->get('integration_step_up.at', 0) > now()->timestamp - config('integrations.step_up_seconds') && $request->session()->get('integration_step_up.at', 0) <= now()->timestamp, 428);
    }

    public function confirm(Request $request): JsonResponse
    {
        $password = $request->attributes->get('integration_input')->get('password');
        abort_unless(is_string($password) && Hash::check($password, $request->user()->password), 403);
        $request->session()->put('integration_step_up', ['user' => $request->user()->id, 'at' => now()->timestamp]);

        return response()->json(['data' => ['expires_in' => config('integrations.step_up_seconds')]]);
    }

    public function index(Request $request, MockConnector $connector): JsonResponse
    {
        $organizationId = $this->organization($request);
        $role = $this->access->role($request->user()->id, $organizationId);
        $admin = $role === 'admin';
        $workflowId = null;
        if ($request->filled('workflow_id')) {
            $request->validate(['workflow_id' => 'required|uuid']);
            $workflowId = DB::table('workflows')->where('organization_id', $organizationId)->where('uuid', $request->input('workflow_id'))->when($role === 'employee', fn ($query) => $query->where('status', 'published'))->value('id');
            abort_if($workflowId === null, 404);
        }
        $items = DB::table('connections')->where('organization_id', $organizationId)->where(function ($query) use ($request, $admin, $workflowId) {
            $query->where('owner_user_id', $request->user()->id);
            if ($admin) {
                $query->orWhereNull('owner_user_id');
            } elseif ($workflowId !== null) {
                $query->orWhere(function ($shared) use ($workflowId) {
                    $shared->whereNull('owner_user_id')->whereExists(function ($grant) use ($workflowId) {
                        $grant->selectRaw('1')->from('workflow_connection_grants')->whereColumn('workflow_connection_grants.connection_id', 'connections.id')->where('workflow_id', $workflowId);
                    });
                });
            }
        })->orderByDesc('created_at')->limit(200)->get()->map(function ($row) use ($admin, $workflowId) {
            $grants = DB::table('workflow_connection_grants as g')->join('workflows as w', 'w.id', '=', 'g.workflow_id')->where('g.connection_id', $row->id)->when(! $admin && $row->owner_user_id === null, fn ($query) => $query->where('g.workflow_id', $workflowId))->select('w.uuid as workflow_id', 'w.name as workflow_name', 'g.operation')->get();

            return ['id' => $row->id, 'name' => $row->name, 'provider_id' => $row->provider_id, 'ownership' => $row->owner_user_id === null ? 'organization' : 'personal', 'credential_type' => $row->credential_type, 'status' => $row->status, 'workflows' => $grants, 'destinations' => DB::table('authorized_destinations')->where('connection_id', $row->id)->pluck('destination')];
        });

        return response()->json(['data' => ['items' => $items, 'provider' => $connector->manifest(), 'admin' => $admin, 'mock_enabled' => (bool) config('integrations.mock_enabled'), 'personal_connections_allowed' => (bool) DB::table('integration_policies')->where('organization_id', $organizationId)->value('personal_connections_allowed'), 'provider_approved' => (bool) DB::table('integration_approvals')->where('organization_id', $organizationId)->where('provider_id', 'local_mock')->value('approved')]]);
    }

    public function policy(Request $request): JsonResponse
    {
        $this->stepUp($request);
        $organizationId = $this->organization($request);
        $data = $request->validate(['personal_connections_allowed' => 'required|boolean', 'provider_approved' => 'required|boolean']);
        DB::transaction(function () use ($request, $organizationId, $data) {
            abort_unless($this->access->role($request->user()->id, $organizationId) === 'admin', 403);
            DB::table('integration_policies')->updateOrInsert(['organization_id' => $organizationId], ['personal_connections_allowed' => $data['personal_connections_allowed']]);
            DB::table('integration_approvals')->updateOrInsert(['organization_id' => $organizationId, 'provider_id' => 'local_mock'], ['approved' => $data['provider_approved']]);
            $this->manager->audit($organizationId, null, $request->user()->id, 'integration.policy.updated');
        });

        return response()->json(['data' => ['updated' => true]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->stepUp($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'regex:/^[\pL\pN ._-]+$/u'], 'ownership' => 'required|in:personal,organization', 'credential_type' => 'required|in:api_key,bot_token']);
        $credentials = $request->attributes->get('integration_input')->get('credentials', []);
        abort_unless(is_array($credentials), 422);
        $id = $this->manager->create($request->user()->id, $this->organization($request), $data, $credentials);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function action(Request $request, string $organization, string $connection, string $action): JsonResponse
    {
        $this->stepUp($request);
        abort_unless(Str::isUuid($connection), 404);
        $rules = match ($action) {
            'rename' => ['name' => ['required', 'string', 'max:100', 'regex:/^[\pL\pN ._-]+$/u']],
            'share', 'unshare' => ['workflow_id' => 'required|uuid', 'operation' => 'required|in:mock.notify,mock.event', 'destination' => 'required|in:mock:inbox,mock:review'],
            default => [],
        };
        $data = $request->validate($rules);
        $credentials = $request->attributes->get('integration_input')->get('credentials', []);
        abort_unless(is_array($credentials), 422);
        $this->manager->act($request->user()->id, $this->organization($request), $connection, $action, $data, $credentials);

        return response()->json(['data' => ['completed' => true]]);
    }

    public function oauth(Request $request, string $organization, string $stage, MockOAuth $oauth): JsonResponse
    {
        $this->stepUp($request);
        $organizationId = $this->organization($request);
        $private = $request->attributes->get('integration_input');
        if ($stage === 'start') {
            $data = $request->validate(['name' => ['required', 'string', 'max:100', 'regex:/^[\pL\pN ._-]+$/u'], 'ownership' => 'required|in:personal,organization', 'connection_id' => 'sometimes|required|uuid']);
            $result = $oauth->start($request, $organizationId, $data);
        } elseif ($stage === 'authorize') {
            $data = $request->validate(['code_challenge' => 'required|string|size:43']);
            $result = $oauth->authorize($request, $organizationId, (string) $private->get('state'), $data['code_challenge']);
        } elseif ($stage === 'callback') {
            $result = ['id' => $oauth->finish($request, $organizationId, (string) $private->get('state'), (string) $private->get('code'), (string) $request->input('issuer'))];
        } else {
            abort(404);
        }

        return response()->json(['data' => $result]);
    }

    public function webhook(Request $request, string $connection, IntegrationRuntime $runtime): JsonResponse
    {
        $data = $request->validate(['event_id' => 'required|uuid', 'timestamp' => 'required|integer']);
        $accepted = $runtime->webhook($connection, $data['event_id'], $data['timestamp'], (string) $request->attributes->get('integration_input')->get('signature'));

        return response()->json(['data' => ['accepted' => $accepted]]);
    }
}
