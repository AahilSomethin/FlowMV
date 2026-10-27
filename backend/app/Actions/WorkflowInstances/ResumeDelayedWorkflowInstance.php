<?php

namespace App\Actions\WorkflowInstances;

use App\Enums\WorkflowDelayStatus;
use App\Enums\WorkflowEventType;
use App\Enums\WorkflowInstanceStatus;
use App\Models\User;
use App\Models\WorkflowDelay;
use App\Models\WorkflowInstance;
use App\Services\Workflows\Execution\PinnedWorkflowVersion;
use App\Services\Workflows\Execution\PublishedGraph;
use App\Services\Workflows\Execution\WorkflowEngine;
use App\Services\Workflows\Execution\WorkflowEventWriter;
use App\Services\Workflows\Execution\WorkflowInstanceState;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent wake for a persisted delay. Concurrent workers compete on the
 * delay row lock; only one consumer advances the instance.
 */
class ResumeDelayedWorkflowInstance
{
    public function __construct(
        private readonly PinnedWorkflowVersion $versions,
        private readonly WorkflowInstanceState $state,
        private readonly WorkflowEngine $engine,
        private readonly WorkflowEventWriter $events,
    ) {}

    public function __invoke(int $delayId): bool
    {
        return DB::transaction(function () use ($delayId) {
            $delay = WorkflowDelay::query()->whereKey($delayId)->lockForUpdate()->first();

            if ($delay === null || $delay->status !== WorkflowDelayStatus::Pending) {
                return false;
            }

            if ($delay->resume_at->isFuture()) {
                return false;
            }

            $instance = WorkflowInstance::query()
                ->whereKey($delay->workflow_instance_id)
                ->lockForUpdate()
                ->first();

            if ($instance === null) {
                $delay->status = WorkflowDelayStatus::Cancelled;
                $delay->consumed_at = now();
                $delay->save();

                return false;
            }

            $actor = User::query()->find($instance->submitted_by)
                ?? User::query()->find($instance->organization?->created_by);

            if ($actor === null) {
                return false;
            }

            if ($instance->status->isTerminal() || $instance->status === WorkflowInstanceStatus::Failed) {
                $delay->status = WorkflowDelayStatus::Cancelled;
                $delay->consumed_at = now();
                $delay->save();
                $this->events->record($instance, $actor, WorkflowEventType::DelayCancelled, $delay->node_id, [
                    'delay_uuid' => $delay->uuid,
                    'reason' => 'terminal_or_failed',
                ]);

                return false;
            }

            if ($instance->status !== WorkflowInstanceStatus::AwaitingDelay
                || $instance->current_node_id !== $delay->node_id) {
                $delay->status = WorkflowDelayStatus::Cancelled;
                $delay->consumed_at = now();
                $delay->save();

                return false;
            }

            $delay->status = WorkflowDelayStatus::Consumed;
            $delay->consumed_at = now();
            $delay->save();

            $instance->resume_at = null;
            $this->state->apply($instance, WorkflowInstanceStatus::Running);
            $this->events->record($instance, $actor, WorkflowEventType::DelayResumed, $delay->node_id, [
                'delay_uuid' => $delay->uuid,
            ]);

            $version = $this->versions->forInstance($instance);
            $target = PublishedGraph::fromVersion($version)->successor($delay->node_id, 'out');

            if ($target === null) {
                $this->engine->fail($instance, $actor, \App\Enums\WorkflowFailureCode::InvalidGraph, $delay->node_id);
            } else {
                $this->engine->advance($instance, $version, $actor, $target);
            }

            return true;
        });
    }
}
