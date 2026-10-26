<?php

namespace App\Http\Requests\Workflows;

use App\Services\Workflows\WorkflowDefinitionNormalizer;

class SaveWorkflowDraftRequest extends WorkflowRequest
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
            'revision' => ['required', 'integer', 'min:1'],
            'graph_definition' => ['required', 'array'],
            'graph_definition.nodes' => ['present', 'array', 'max:'.WorkflowDefinitionNormalizer::MAX_NODES],
            'graph_definition.edges' => ['present', 'array', 'max:'.WorkflowDefinitionNormalizer::MAX_EDGES],
            'form_definition' => ['required', 'array'],
            'form_definition.fields' => ['present', 'array', 'max:'.WorkflowDefinitionNormalizer::MAX_FIELDS],
        ];
    }
}
