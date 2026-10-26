<?php

namespace App\Http\Requests\WorkflowInstances;

use App\Enums\WorkflowTaskStatus;
use Illuminate\Validation\Rule;

class ListWorkflowTasksRequest extends ExecutionRequest
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
            'status' => ['nullable', 'string', Rule::enum(WorkflowTaskStatus::class)],
            'search' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function listStatus(): WorkflowTaskStatus
    {
        $status = $this->filled('status') ? $this->string('status')->toString() : null;

        return WorkflowTaskStatus::tryFrom((string) $status) ?? WorkflowTaskStatus::Open;
    }

    public function listSearch(): ?string
    {
        $search = trim($this->string('search')->toString());

        return $search === '' ? null : $search;
    }
}
