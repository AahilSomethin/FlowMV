<?php

namespace App\Http\Requests\Workflows;

use App\Enums\WorkflowStatus;
use App\Models\Workflow;
use Illuminate\Validation\Rule;

class ListWorkflowsRequest extends WorkflowRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', [Workflow::class, $this->organization()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(WorkflowStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
