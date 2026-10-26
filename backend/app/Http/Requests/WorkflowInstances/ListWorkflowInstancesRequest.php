<?php

namespace App\Http\Requests\WorkflowInstances;

use App\Enums\WorkflowInstanceStatus;
use Illuminate\Validation\Rule;

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
            'status' => ['nullable', 'string', Rule::enum(WorkflowInstanceStatus::class)],
            'search' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function listStatus(): ?string
    {
        return $this->filled('status') ? $this->string('status')->toString() : null;
    }

    public function listSearch(): ?string
    {
        $search = trim($this->string('search')->toString());

        return $search === '' ? null : $search;
    }
}
