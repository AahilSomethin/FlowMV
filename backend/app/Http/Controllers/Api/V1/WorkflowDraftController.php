<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Workflows\SaveWorkflowDraft;
use App\Actions\Workflows\ValidateWorkflowDraft;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflows\SaveWorkflowDraftRequest;
use App\Http\Resources\WorkflowResource;
use App\Policies\WorkflowPolicy;
use Illuminate\Http\JsonResponse;

class WorkflowDraftController extends Controller
{
    public function update(SaveWorkflowDraftRequest $request, SaveWorkflowDraft $save, WorkflowPolicy $policy): WorkflowResource
    {
        $workflow = $save(
            $request->user(),
            $request->workflow(),
            $request->integer('revision'),
            $request->input('graph_definition'),
            $request->input('form_definition'),
        );
        $workflow->setAttribute('viewer_role', $policy->role($request->user(), $request->organization()));
        $workflow->setAttribute('present_definition', true);

        return new WorkflowResource($workflow);
    }

    public function validateDraft(SaveWorkflowDraftRequest $request, ValidateWorkflowDraft $validate): JsonResponse
    {
        return response()->json([
            'data' => $validate(
                $request->workflow(),
                $request->input('graph_definition'),
                $request->input('form_definition'),
            ),
        ]);
    }
}
