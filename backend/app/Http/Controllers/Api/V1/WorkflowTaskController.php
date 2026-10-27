<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\WorkflowInstances\CompleteWorkflowAssignment;
use App\Actions\WorkflowInstances\DecideWorkflowTask;
use App\Actions\WorkflowInstances\ListEligibleWorkflowTasks;
use App\Actions\WorkflowInstances\ReassignWorkflowAssignment;
use App\Actions\WorkflowInstances\SubmitWorkflowRevision;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkflowInstances\CompleteWorkflowAssignmentRequest;
use App\Http\Requests\WorkflowInstances\DecideWorkflowTaskRequest;
use App\Http\Requests\WorkflowInstances\ListWorkflowTasksRequest;
use App\Http\Requests\WorkflowInstances\ReassignWorkflowAssignmentRequest;
use App\Http\Requests\WorkflowInstances\ShowWorkflowTaskRequest;
use App\Http\Requests\WorkflowInstances\SubmitWorkflowRevisionRequest;
use App\Http\Resources\WorkflowInstanceResource;
use App\Http\Resources\WorkflowTaskResource;
use App\Services\Workflows\Execution\IdempotentAction;
use Illuminate\Http\JsonResponse;

class WorkflowTaskController extends Controller
{
    public function index(ListWorkflowTasksRequest $request, ListEligibleWorkflowTasks $list): JsonResponse
    {
        $tasks = $list(
            $request->user(),
            $request->organization(),
            (int) $request->input('page', 1),
            $request->listStatus(),
            $request->listSearch(),
        );

        return response()->json([
            'data' => [
                'items' => WorkflowTaskResource::collection($tasks->items())->resolve(),
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'total' => $tasks->total(),
            ],
        ]);
    }

    public function show(ShowWorkflowTaskRequest $request): WorkflowTaskResource
    {
        return new WorkflowTaskResource($request->task()->load([
            'decisionSnapshot',
            'instance.workflow',
            'instance.version',
            'instance.submitter',
        ]));
    }

    public function decide(
        DecideWorkflowTaskRequest $request,
        DecideWorkflowTask $decide,
        IdempotentAction $idempotent,
    ): JsonResponse {
        return $idempotent->execute($request->user(), $request->organization(), $request, function () use ($request, $decide) {
            $instance = $decide($request->user(), $request->task(), $request->string('decision')->toString());

            return [
                'status' => 200,
                'body' => ['data' => (new WorkflowInstanceResource($instance))->resolve($request)],
            ];
        });
    }

    public function revise(
        SubmitWorkflowRevisionRequest $request,
        SubmitWorkflowRevision $revise,
        IdempotentAction $idempotent,
    ): JsonResponse {
        return $idempotent->execute($request->user(), $request->organization(), $request, function () use ($request, $revise) {
            $instance = $revise($request->user(), $request->task(), $request->submittedData());

            return [
                'status' => 200,
                'body' => ['data' => (new WorkflowInstanceResource($instance))->resolve($request)],
            ];
        });
    }

    public function complete(
        CompleteWorkflowAssignmentRequest $request,
        CompleteWorkflowAssignment $complete,
        IdempotentAction $idempotent,
    ): JsonResponse {
        return $idempotent->execute($request->user(), $request->organization(), $request, function () use ($request, $complete) {
            $instance = $complete($request->user(), $request->task());

            return [
                'status' => 200,
                'body' => ['data' => (new WorkflowInstanceResource($instance))->resolve($request)],
            ];
        });
    }

    public function reassign(
        ReassignWorkflowAssignmentRequest $request,
        ReassignWorkflowAssignment $reassign,
        IdempotentAction $idempotent,
    ): JsonResponse {
        return $idempotent->execute($request->user(), $request->organization(), $request, function () use ($request, $reassign) {
            $instance = $reassign(
                $request->user(),
                $request->task(),
                (int) $request->integer('assignee_user_id'),
            );

            return [
                'status' => 200,
                'body' => ['data' => (new WorkflowInstanceResource($instance))->resolve($request)],
            ];
        });
    }
}
