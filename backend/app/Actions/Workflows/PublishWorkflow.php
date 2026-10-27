<?php

namespace App\Actions\Workflows;

use App\Enums\WorkflowStatus;
use App\Exceptions\WorkflowConflictException;
use App\Exceptions\WorkflowDefinitionInvalidException;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowDraft;
use App\Models\WorkflowVersion;
use App\Services\Workflows\Nodes\NodeRegistry;
use App\Services\Workflows\WorkflowDefinitionNormalizer;
use App\Services\Workflows\WorkflowDefinitionValidator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class PublishWorkflow
{
    public function __construct(
        private readonly WorkflowDefinitionNormalizer $normalizer,
        private readonly WorkflowDefinitionValidator $validator,
    ) {}

    public function __invoke(User $user, Workflow $workflow, int $revision): Workflow
    {
        return DB::transaction(function () use ($user, $workflow, $revision) {
            $locked = Workflow::query()->whereKey($workflow->id)->lockForUpdate()->firstOrFail();
            $draft = WorkflowDraft::query()->where('workflow_id', $locked->id)->lockForUpdate()->firstOrFail();

            if ($draft->revision !== $revision) {
                throw new WorkflowConflictException('This workflow changed before it could be published. Reload it and try again.');
            }

            $normalized = $this->normalizer->normalize($draft->graph_definition, $draft->form_definition);

            if (! $normalized->ok()) {
                throw new WorkflowDefinitionInvalidException($normalized->violations);
            }

            $publishedHash = null;

            if ($locked->current_published_version_id !== null) {
                $publishedHash = WorkflowVersion::query()
                    ->whereKey($locked->current_published_version_id)
                    ->value('content_hash');
            }

            $violations = $this->validator->validate($normalized->graph, $normalized->form, is_string($publishedHash) ? $publishedHash : null);

            if ($violations !== []) {
                throw new WorkflowDefinitionInvalidException($violations);
            }

            $next = ((int) WorkflowVersion::query()->where('workflow_id', $locked->id)->max('version_number')) + 1;

            try {
                $version = WorkflowVersion::query()->create([
                    'workflow_id' => $locked->id,
                    'version_number' => $next,
                    'graph_definition' => $normalized->graph,
                    'form_definition' => $normalized->form,
                    'content_hash' => $normalized->hash,
                    'node_registry_version' => NodeRegistry::CURRENT,
                    'published_by' => $user->id,
                    'published_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new WorkflowConflictException('This workflow was published by someone else. Reload it and try again.');
            }

            $locked->forceFill([
                'current_published_version_id' => $version->id,
                'status' => WorkflowStatus::Published,
            ])->save();

            $draft->forceFill([
                'graph_definition' => $normalized->graph,
                'form_definition' => $normalized->form,
                'content_hash' => $normalized->hash,
                'revision' => $draft->revision + 1,
                'updated_by' => $user->id,
            ])->save();

            return $locked->fresh(['draft', 'currentPublishedVersion.publisher']);
        });
    }
}
