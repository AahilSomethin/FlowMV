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
use App\Services\Workflows\Execution\AssignmentNodeExecutor;
use App\Services\Workflows\Execution\PinnedWorkflowVersion;
use App\Services\Workflows\Execution\PublishedGraph;
use App\Services\Workflows\Execution\WorkflowEngine;
use App\Services\Workflows\Execution\WorkflowEventWriter;
use App\Services\Workflows\Execution\WorkflowInstanceState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class CompleteWorkflowAssignment
{
    public function __construct(
        private readonly PinnedWorkflowVersion $versions,
        private readonly WorkflowInstanceState $state,
        private readonly WorkflowEngine $engine,
        private readonly WorkflowEventWriter $events,
        private readonly AssignmentNodeExecutor $assignments,
        private readonly LoadWorkflowInstance $loader,
    ) {}

    public function __invoke(User $user, WorkflowTask $task): WorkflowInstance
    {
        return DB::transaction(function () use ($user, $task) {
            $instance = WorkflowInstance::query()->whereKey($task->workflow_instance_id)->lockForUpdate()->firstOrFail();
            $lockedTask = WorkflowTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($instance->organization_id !== $lockedTask->organization_id) {
                throw (new ModelNotFoundException)->setModel(WorkflowTask::class, [$task->uuid]);
            }

            if (! $this->openAssignment($lockedTask, $instance)) {
                throw new WorkflowConflictException('This task is no longer open.');
            }

            $version = $this->versions->forInstance($instance);
            $node = PublishedGraph::fromVersion($version)->node($lockedTask->node_id);
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];

            if (! $this->assignments->canComplete($user, $instance, $lockedTask, $data)) {
                if ($instance->organization->activeMemberships()->where('user_id', $user->id)->doesntExist()) {
                    throw (new ModelNotFoundException)->setModel(WorkflowTask::class, [$task->uuid]);
                }

                throw new AuthorizationException;
            }

            $lockedTask->forceFill([
                'status' => WorkflowTaskStatus::Completed,
                'decision' => WorkflowTaskDecision::Completed,
                'completed_at' => now(),
                'completed_by' => $user->id,
            ])->save();

            $this->events->record($instance, $user, WorkflowEventType::TaskCompleted, $lockedTask->node_id, [
                'task_uuid' => $lockedTask->uuid,
                'task_type' => $lockedTask->task_type->value,
                'decision' => WorkflowTaskDecision::Completed->value,
            ]);

            $this->state->apply($instance, WorkflowInstanceStatus::Running);
            $target = PublishedGraph::fromVersion($version)->successor($lockedTask->node_id, 'out');

            if ($target === null) {
                $this->engine->fail($instance, $user, WorkflowFailureCode::InvalidGraph, $lockedTask->node_id);
            } else {
                $this->engine->advance($instance, $version, $user, $target);
            }

            return ($this->loader)($instance);
        });
    }

    private function openAssignment(WorkflowTask $task, WorkflowInstance $instance): bool
    {
        return $task->status === WorkflowTaskStatus::Open
            && $task->task_type === WorkflowTaskType::Assignment
            && $task->workflow_instance_id === $instance->id
            && $task->node_id === $instance->current_node_id
            && $instance->status === WorkflowInstanceStatus::AwaitingAssignment;
    }
}
