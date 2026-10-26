<?php

namespace App\Http\Requests\WorkflowInstances;

class StartWorkflowInstanceRequest extends ExecutionRequest
{
    public function authorize(): bool
    {
        $this->organization();
        $this->workflow();

        return $this->user() !== null;
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
