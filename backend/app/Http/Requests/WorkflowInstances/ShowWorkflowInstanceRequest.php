<?php

namespace App\Http\Requests\WorkflowInstances;

use App\Services\Workflows\Execution\WorkflowInstanceVisibility;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ShowWorkflowInstanceRequest extends ExecutionRequest
{
    public function authorize(): bool
    {
        $instance = $this->instance();

        if ($this->user() === null || ! app(WorkflowInstanceVisibility::class)->canView($this->user(), $instance)) {
            throw (new ModelNotFoundException)->setModel($instance::class, [$instance->uuid]);
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
