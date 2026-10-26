<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\WorkflowInstances\ListWorkflowInstances;
use App\Actions\WorkflowInstances\LoadWorkflowInstance;
use App\Actions\WorkflowInstances\RetryFailedWorkflowInstance;
use App\Actions\WorkflowInstances\StartWorkflowInstance;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkflowInstances\ListWorkflowInstancesRequest;
use App\Http\Requests\WorkflowInstances\RetryWorkflowInstanceRequest;
use App\Http\Requests\WorkflowInstances\ShowWorkflowInstanceRequest;
use App\Http\Requests\WorkflowInstances\StartWorkflowInstanceRequest;
use App\Http\Resources\WorkflowInstanceResource;
use App\Services\Workflows\Execution\IdempotentAction;
use Illuminate\Http\JsonResponse;

class WorkflowInstanceController extends Controller
{
    public function index(ListWorkflowInstancesRequest $request, ListWorkflowInstances $list): JsonResponse
    {
        $instances = $list($request->user(), $request->organization(), (int) $request->input('page', 1));

        return response()->json([
            'data' => [
                'items' => WorkflowInstanceResource::collection($instances->getCollection())->resolve(),
                'current_page' => $instances->currentPage(),
                'last_page' => $instances->lastPage(),
                'total' => $instances->total(),
            ],
        ]);
    }

    public function show(ShowWorkflowInstanceRequest $request, LoadWorkflowInstance $loader): WorkflowInstanceResource
    {
        return new WorkflowInstanceResource($loader($request->instance()));
    }

    public function store(
        StartWorkflowInstanceRequest $request,
        StartWorkflowInstance $start,
        IdempotentAction $idempotent,
    ): JsonResponse {
        return $idempotent->execute($request->user(), $request->organization(), $request, function () use ($request, $start) {
            $instance = $start(
                $request->user(),
                $request->organization(),
                $request->workflow(),
                $request->submittedData(),
            );

            return [
                'status' => 201,
                'body' => ['data' => (new WorkflowInstanceResource($instance))->resolve($request)],
            ];
        });
    }

    public function retry(
        RetryWorkflowInstanceRequest $request,
        RetryFailedWorkflowInstance $retry,
        IdempotentAction $idempotent,
    ): JsonResponse {
        return $idempotent->execute($request->user(), $request->organization(), $request, function () use ($request, $retry) {
            $instance = $retry($request->user(), $request->instance());

            return [
                'status' => 200,
                'body' => ['data' => (new WorkflowInstanceResource($instance))->resolve($request)],
            ];
        });
    }
}
