<?php

namespace App\Actions\Workflows;

use App\Exceptions\WorkflowDefinitionInvalidException;
use App\Models\Workflow;
use App\Services\Workflows\WorkflowDefinitionNormalizer;
use App\Services\Workflows\WorkflowDefinitionValidator;

class ValidateWorkflowDraft
{
    public function __construct(
        private readonly WorkflowDefinitionNormalizer $normalizer,
        private readonly WorkflowDefinitionValidator $validator,
    ) {}

    /**
     * @param  mixed  $graph
     * @param  mixed  $form
     * @return array{valid: true, violations: array{}}
     */
    public function __invoke(Workflow $workflow, mixed $graph, mixed $form): array
    {
        $normalized = $this->normalizer->normalize($graph, $form);

        if (! $normalized->ok()) {
            throw new WorkflowDefinitionInvalidException($normalized->violations);
        }

        $workflow->loadMissing('currentPublishedVersion');
        $publishedHash = $workflow->currentPublishedVersion?->content_hash;
        $violations = $this->validator->validate(
            $normalized->graph,
            $normalized->form,
            $workflow->status->value === 'published' ? $publishedHash : null,
        );

        if ($violations !== []) {
            throw new WorkflowDefinitionInvalidException($violations);
        }

        return ['valid' => true, 'violations' => []];
    }
}
