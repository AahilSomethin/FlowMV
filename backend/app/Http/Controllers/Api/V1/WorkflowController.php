<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Workflows\ArchiveWorkflow;
use App\Actions\Workflows\CreateWorkflow;
use App\Actions\Workflows\UpdateWorkflow;
use App\Enums\OrganizationRole;
use App\Enums\WorkflowStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflows\ArchiveWorkflowRequest;
use App\Http\Requests\Workflows\ListWorkflowsRequest;
use App\Http\Requests\Workflows\StoreWorkflowRequest;
use App\Http\Requests\Workflows\UpdateWorkflowRequest;
use App\Http\Resources\WorkflowResource;
use App\Models\Workflow;
use App\Policies\WorkflowPolicy;
use App\Support\ActiveMembershipLocator;
use App\Support\WorkflowLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class WorkflowController extends Controller
{
    public function index(ListWorkflowsRequest $request, WorkflowPolicy $policy): JsonResponse
    {
        $organization = $request->organization();
        $role = $policy->role($request->user(), $organization);
        $workflows = Workflow::query()
            ->where('organization_id', $organization->id)
            ->with([
                'draft:id,workflow_id,revision,content_hash',
                'currentPublishedVersion:id,version_number,content_hash,published_at,published_by',
            ])
            ->when($role === OrganizationRole::Employee, function ($query) {
                $query->where('status', WorkflowStatus::Published);
            })
            ->when($role !== OrganizationRole::Employee && $request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->string('status')->toString());
            })
            ->when($request->filled('search'), function ($query) use ($request) {
                $operator = Schema::getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $search = addcslashes($request->string('search')->toString(), '%_\\');
                $query->where('name', $operator, '%'.$search.'%');
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(15);

        $workflows->getCollection()->each(function (Workflow $workflow) use ($role) {
            $workflow->setAttribute('viewer_role', $role);
        });

        return response()->json([
            'data' => [
                'items' => WorkflowResource::collection($workflows->getCollection())->resolve(),
                'current_page' => $workflows->currentPage(),
                'last_page' => $workflows->lastPage(),
                'total' => $workflows->total(),
            ],
        ]);
    }

    public function store(StoreWorkflowRequest $request, CreateWorkflow $create, WorkflowPolicy $policy): JsonResponse
    {
        $organization = $request->organization();
        $workflow = $create(
            $request->user(),
            $organization,
            $request->string('name')->toString(),
            $request->input('description'),
        );
        $workflow->setAttribute('viewer_role', $policy->role($request->user(), $organization));
        $workflow->setAttribute('present_definition', true);

        return (new WorkflowResource($workflow))->response()->setStatusCode(201);
    }

    public function show(Request $request, WorkflowLocator $locator, ActiveMembershipLocator $memberships, WorkflowPolicy $policy): WorkflowResource
    {
        $workflow = $locator->workflowFrom($request);
        $this->authorize('view', $workflow);
        $role = $policy->role($request->user(), $memberships->organizationFrom($request));
        $workflow->load($role === OrganizationRole::Employee
            ? ['currentPublishedVersion.publisher']
            : ['draft', 'currentPublishedVersion.publisher']);
        $workflow->setAttribute('viewer_role', $role);
        $workflow->setAttribute('present_definition', true);

        return new WorkflowResource($workflow);
    }

    public function update(UpdateWorkflowRequest $request, UpdateWorkflow $update, WorkflowPolicy $policy): WorkflowResource
    {
        $workflow = $update(
            $request->workflow(),
            $request->string('name')->toString(),
            $request->input('description'),
        );
        $workflow->setAttribute('viewer_role', $policy->role($request->user(), $request->organization()));
        $workflow->setAttribute('present_definition', true);

        return new WorkflowResource($workflow);
    }

    public function archive(ArchiveWorkflowRequest $request, ArchiveWorkflow $archive, WorkflowPolicy $policy): WorkflowResource
    {
        $workflow = $archive($request->workflow());
        $workflow->setAttribute('viewer_role', $policy->role($request->user(), $request->organization()));
        $workflow->setAttribute('present_definition', true);

        return new WorkflowResource($workflow);
    }
}
