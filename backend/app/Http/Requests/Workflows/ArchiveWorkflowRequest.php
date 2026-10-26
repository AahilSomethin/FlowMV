<?php

namespace App\Http\Requests\Workflows;

class ArchiveWorkflowRequest extends WorkflowRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('archive', $this->workflow());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
