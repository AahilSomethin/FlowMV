<?php

namespace App\Http\Requests\WorkflowInstances;

use App\Services\Workflows\Execution\WorkflowInstanceVisibility;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ShowWorkflowTaskRequest extends ExecutionRequest
{
    public function authorize(): bool
    {
        $task = $this->task()->load('instance');
        $user = $this->user();

        if ($user === null || ! app(WorkflowInstanceVisibility::class)->canViewTask($user, $task)) {
            throw (new ModelNotFoundException)->setModel($task::class, [$task->uuid]);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
