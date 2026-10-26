<?php

namespace App\Http\Requests\Workflows;

class PublishWorkflowRequest extends WorkflowRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('publish', $this->workflow());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
        ];
    }
}
