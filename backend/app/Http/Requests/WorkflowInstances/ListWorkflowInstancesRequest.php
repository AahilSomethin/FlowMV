<?php

namespace App\Http\Requests\WorkflowInstances;

class ListWorkflowInstancesRequest extends ExecutionRequest
{
    public function authorize(): bool
    {
        $this->organization();

        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
