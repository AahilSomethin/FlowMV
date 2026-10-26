<?php

namespace App\Http\Requests\Workflows;

class UpdateWorkflowRequest extends WorkflowRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->workflow());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $description = $this->input('description');

        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'description' => is_string($description) && trim($description) !== '' ? trim($description) : null,
        ]);
    }
}
