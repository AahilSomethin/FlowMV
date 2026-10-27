<?php

namespace App\Actions\WorkflowInstances;

use App\Enums\SnapshotReason;
use App\Enums\WorkflowEventType;
use App\Enums\WorkflowFailureCode;
use App\Enums\WorkflowInstanceStatus;
use App\Enums\WorkflowTaskStatus;
use App\Enums\WorkflowTaskType;
use App\Exceptions\WorkflowConflictException;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowRevisionCounter;
use App\Models\WorkflowTask;
use App\Services\Workflows\Execution\FormSubmissionValidator;
use App\Services\Workflows\Execution\PinnedWorkflowVersion;
use App\Services\Workflows\Execution\PublishedGraph;
use App\Services\Workflows\Execution\SubmissionSnapshotWriter;
use App\Services\Workflows\Execution\WorkflowEngine;
use App\Services\Workflows\Execution\WorkflowEventWriter;
use App\Services\Workflows\Execution\WorkflowInstanceState;
use App\Services\Workflows\Execution\WorkflowInstanceVisibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class SubmitWorkflowRevision
{
    public function __construct(
        private readonly WorkflowInstanceVisibility $visibility,
        private readonly PinnedWorkflowVersion $versions,
        private readonly FormSubmissionValidator $forms,
        private readonly WorkflowInstanceState $state,
        private readonly WorkflowEngine $engine,
        private readonly WorkflowEventWriter $events,
        private readonly SubmissionSnapshotWriter $snapshots,
        private readonly LoadWorkflowInstance $loader,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(User $user, WorkflowTask $task, array $data): WorkflowInstance
    {
        return DB::transaction(function () use ($user, $task, $data) {
            $instance = WorkflowInstance::query()->whereKey($task->workflow_instance_id)->lockForUpdate()->firstOrFail();
            $lockedTask = WorkflowTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $lockedTask->setRelation('instance', $instance);
            $version = $this->versions->forInstance($instance);
            $graph = PublishedGraph::fromVersion($version);

            if ($instance->organization->activeMemberships()->where('user_id', $user->id)->doesntExist()) {
                throw (new ModelNotFoundException)->setModel(WorkflowTask::class, [$task->uuid]);
            }

            if (! $this->visibility->canRevise($user, $lockedTask)) {
                throw new AuthorizationException;
            }

            if (! $this->openRevision($lockedTask, $instance)) {
                throw new WorkflowConflictException('This task is no longer open.');
            }

            $submitted = $this->forms->validate($graph->fields(), $data);
            $changed = $this->changedFields($instance->submitted_data ?? [], $submitted, array_keys($graph->fields()));
            $lockedTask->forceFill([
                'status' => WorkflowTaskStatus::Completed,
                'completed_at' => now(),
                'completed_by' => $user->id,
            ])->save();
            $instance->submitted_data = $submitted;
            $instance->save();
            $this->snapshots->capture($instance, SnapshotReason::RevisionSubmitted, $lockedTask);

            $attempts = (int) WorkflowRevisionCounter::query()
                ->where('workflow_instance_id', $instance->id)
                ->where('revision_node_id', $lockedTask->node_id)
                ->value('attempt_count');

            $this->events->record($instance, $user, WorkflowEventType::RevisionSubmitted, $lockedTask->node_id, [
                'task_uuid' => $lockedTask->uuid,
                'attempt_count' => $attempts,
                'changed_field_ids' => $changed,
            ]);
            $this->state->apply($instance, WorkflowInstanceStatus::Running);
            $target = $graph->successor($lockedTask->node_id, 'return');

            if ($target === null) {
                $this->engine->fail($instance, $user, WorkflowFailureCode::InvalidGraph, $lockedTask->node_id);
            } else {
                $this->engine->advance($instance, $version, $user, $target);
            }

            if ($instance->workflow_version_id !== $version->id) {
                throw new WorkflowConflictException('This workflow instance is no longer consistent.');
            }

            return ($this->loader)($instance);
        });
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $submitted
     * @param  list<string>  $fieldIds
     * @return list<string>
     */
    private function changedFields(array $previous, array $submitted, array $fieldIds): array
    {
        $changed = [];

        foreach ($fieldIds as $fieldId) {
            if (($previous[$fieldId] ?? null) !== ($submitted[$fieldId] ?? null)) {
                $changed[] = $fieldId;
            }
        }

        return $changed;
    }

    private function openRevision(WorkflowTask $task, WorkflowInstance $instance): bool
    {
        return $task->status === WorkflowTaskStatus::Open
            && $task->task_type === WorkflowTaskType::Revision
            && $task->workflow_instance_id === $instance->id
            && $task->node_id === $instance->current_node_id
            && $instance->status === WorkflowInstanceStatus::AwaitingRevision
            && $task->assigned_user_id === $instance->submitted_by;
    }
}
