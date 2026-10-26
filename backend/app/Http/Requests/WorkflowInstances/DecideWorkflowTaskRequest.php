<?php

namespace App\Http\Requests\WorkflowInstances;

use App\Services\Workflows\Execution\WorkflowInstanceVisibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class DecideWorkflowTaskRequest extends ExecutionRequest
{
    public function authorize(): bool
    {
        $task = $this->task()->load('instance');
        $user = $this->user();
        $visibility = app(WorkflowInstanceVisibility::class);

        if ($user === null || ! $visibility->canViewTask($user, $task)) {
            throw (new ModelNotFoundException)->setModel($task::class, [$task->uuid]);
        }

        if (! $visibility->canDecide($user, $task)) {
            throw new AuthorizationException;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approved,rejected'],
        ];
    }
}
