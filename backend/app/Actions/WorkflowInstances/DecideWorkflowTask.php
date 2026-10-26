<?php

namespace App\Actions\WorkflowInstances;

use App\Enums\WorkflowEventType;
use App\Enums\WorkflowFailureCode;
use App\Enums\WorkflowInstanceStatus;
use App\Enums\WorkflowTaskDecision;
use App\Enums\WorkflowTaskStatus;
use App\Enums\WorkflowTaskType;
use App\Exceptions\WorkflowConflictException;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Services\Workflows\Execution\PinnedWorkflowVersion;
use App\Services\Workflows\Execution\PublishedGraph;
use App\Services\Workflows\Execution\WorkflowEngine;
use App\Services\Workflows\Execution\WorkflowEventWriter;
use App\Services\Workflows\Execution\WorkflowInstanceState;
use App\Services\Workflows\Execution\WorkflowInstanceVisibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class DecideWorkflowTask
{
    public function __construct(
        private readonly WorkflowInstanceVisibility $visibility,
        private readonly PinnedWorkflowVersion $versions,
        private readonly WorkflowInstanceState $state,
        private readonly WorkflowEngine $engine,
        private readonly WorkflowEventWriter $events,
        private readonly LoadWorkflowInstance $loader,
    ) {}

    public function __invoke(User $user, WorkflowTask $task, string $decision): WorkflowInstance
    {
        return DB::transaction(function () use ($user, $task, $decision) {
            $instance = $this->lockedInstance($task);
            $lockedTask = WorkflowTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $version = $this->versions->forInstance($instance);
            $this->authorize($user, $lockedTask, $instance);

            if (! $this->openApproval($lockedTask, $instance)) {
                throw new WorkflowConflictException('This task is no longer open.');
            }

            $choice = WorkflowTaskDecision::from($decision);
            $lockedTask->forceFill([
                'status' => WorkflowTaskStatus::Completed,
                'decision' => $choice,
                'completed_at' => now(),
                'completed_by' => $user->id,
            ])->save();

            $this->events->record(
                $instance,
                $user,
                $choice === WorkflowTaskDecision::Approved ? WorkflowEventType::TaskApproved : WorkflowEventType::TaskRejected,
                $lockedTask->node_id,
                ['task_uuid' => $lockedTask->uuid, 'decision' => $choice->value],
            );

            $this->state->apply($instance, WorkflowInstanceStatus::Running);
            $target = PublishedGraph::fromVersion($version)->successor($lockedTask->node_id, $choice->value);

            if ($target === null) {
                $this->engine->fail($instance, $user, WorkflowFailureCode::InvalidGraph, $lockedTask->node_id);
            } else {
                $this->engine->advance($instance, $version, $user, $target);
            }

            $this->guardPin($instance, $version->id);

            return ($this->loader)($instance);
        });
    }

    private function authorize(User $user, WorkflowTask $task, WorkflowInstance $instance): void
    {
        $task->setRelation('instance', $instance);

        if ($this->visibility->canDecide($user, $task)) {
            return;
        }

        if ($instance->organization->activeMemberships()->where('user_id', $user->id)->doesntExist()) {
            throw (new ModelNotFoundException)->setModel(WorkflowTask::class, [$task->uuid]);
        }

        throw new AuthorizationException;
    }

    private function openApproval(WorkflowTask $task, WorkflowInstance $instance): bool
    {
        return $task->status === WorkflowTaskStatus::Open
            && $task->task_type === WorkflowTaskType::Approval
            && $task->workflow_instance_id === $instance->id
            && $task->node_id === $instance->current_node_id
            && $instance->status === WorkflowInstanceStatus::AwaitingApproval;
    }

    private function lockedInstance(WorkflowTask $task): WorkflowInstance
    {
        $instance = WorkflowInstance::query()->whereKey($task->workflow_instance_id)->lockForUpdate()->firstOrFail();

        if ($instance->organization_id !== $task->organization_id) {
            throw (new ModelNotFoundException)->setModel(WorkflowTask::class, [$task->uuid]);
        }

        return $instance;
    }

    private function guardPin(WorkflowInstance $instance, int $versionId): void
    {
        if ($instance->workflow_version_id !== $versionId) {
            throw new WorkflowConflictException('This workflow instance is no longer consistent.');
        }
    }
}
