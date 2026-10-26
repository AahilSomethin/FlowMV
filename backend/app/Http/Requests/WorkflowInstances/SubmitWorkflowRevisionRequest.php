<?php

namespace App\Http\Requests\WorkflowInstances;

use App\Services\Workflows\Execution\WorkflowInstanceVisibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SubmitWorkflowRevisionRequest extends ExecutionRequest
{
    public function authorize(): bool
    {
        $task = $this->task()->load('instance');
        $user = $this->user();
        $visibility = app(WorkflowInstanceVisibility::class);

        if ($user === null || ! $visibility->canViewTask($user, $task)) {
            throw (new ModelNotFoundException)->setModel($task::class, [$task->uuid]);
        }

        if (! $visibility->canRevise($user, $task)) {
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
            'data' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function submittedData(): array
    {
        $data = $this->input('data', []);

        return is_array($data) ? $data : [];
    }
}
