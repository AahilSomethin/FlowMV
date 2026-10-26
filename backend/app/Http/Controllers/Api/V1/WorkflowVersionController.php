<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Workflows\PublishWorkflow;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflows\PublishWorkflowRequest;
use App\Http\Resources\WorkflowResource;
use App\Http\Resources\WorkflowVersionResource;
use App\Policies\WorkflowPolicy;
use App\Support\WorkflowLocator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkflowVersionController extends Controller
{
    public function index(Request $request, WorkflowLocator $locator): AnonymousResourceCollection
    {
        $workflow = $locator->workflowFrom($request);
        $this->authorize('view', $workflow);

        $versions = $workflow->versions()->with('publisher')->orderByDesc('version_number')->get();

        return WorkflowVersionResource::collection($versions);
    }

    public function show(Request $request, WorkflowLocator $locator): WorkflowVersionResource
    {
        $workflow = $locator->workflowFrom($request);
        $this->authorize('view', $workflow);
        $version = $locator->versionFrom($request)->load('publisher');
        $version->setAttribute('present_definition', true);

        return new WorkflowVersionResource($version);
    }

    public function store(PublishWorkflowRequest $request, PublishWorkflow $publish, WorkflowPolicy $policy): WorkflowResource
    {
        $workflow = $publish($request->user(), $request->workflow(), $request->integer('revision'));
        $workflow->setAttribute('viewer_role', $policy->role($request->user(), $request->organization()));
        $workflow->setAttribute('present_definition', true);

        return new WorkflowResource($workflow);
    }
}
