<?php

namespace App\Actions\Workflows;

use App\Exceptions\WorkflowConflictException;
use App\Exceptions\WorkflowDefinitionInvalidException;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowDraft;
use App\Services\Workflows\WorkflowDefinitionNormalizer;
use Illuminate\Support\Facades\DB;

class SaveWorkflowDraft
{
    public function __construct(private readonly WorkflowDefinitionNormalizer $normalizer) {}

    /**
     * @param  mixed  $graph
     * @param  mixed  $form
     */
    public function __invoke(User $user, Workflow $workflow, int $revision, mixed $graph, mixed $form): Workflow
    {
        $normalized = $this->normalizer->normalize($graph, $form);

        if (! $normalized->ok()) {
            throw new WorkflowDefinitionInvalidException($normalized->violations);
        }

        return DB::transaction(function () use ($user, $workflow, $revision, $normalized) {
            $locked = Workflow::query()->whereKey($workflow->id)->lockForUpdate()->firstOrFail();
            $draft = WorkflowDraft::query()->where('workflow_id', $locked->id)->lockForUpdate()->firstOrFail();

            if ($draft->revision !== $revision) {
                throw new WorkflowConflictException('This draft was updated by someone else. Reload the workflow before saving.');
            }

            $draft->fill([
                'graph_definition' => $normalized->graph,
                'form_definition' => $normalized->form,
                'content_hash' => $normalized->hash,
                'updated_by' => $user->id,
                'revision' => $draft->revision + 1,
            ])->save();

            $locked->touch();

            return $locked->fresh(['draft', 'currentPublishedVersion.publisher']);
        });
    }
}
