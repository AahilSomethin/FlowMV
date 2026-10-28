<?php

namespace App\Services\Workflows\Execution;

use App\Enums\WorkflowEventType;
use App\Enums\WorkflowFailureCode;
use App\Enums\WorkflowInstanceStatus;
use App\Enums\WorkflowTaskStatus;
use App\Enums\WorkflowTaskType;
use App\Exceptions\WorkflowConflictException;
use App\Exceptions\WorkflowEvaluationException;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowRevisionCounter;
use App\Models\WorkflowTask;
use App\Models\WorkflowVersion;
use App\Services\Integrations\IntegrationRuntime;
use App\Services\Workflows\Nodes\NodeRegistry;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Interprets one pinned workflow version. Callers own the transaction and the
 * instance row lock. This class never reads the workflow's current version.
 *
 * V1 snapshots keep the original five-type semantics. V2 may include
 * notification, assignment, and delay nodes. Historical graphs are never
 * reinterpreted under newer node rules.
 */
class WorkflowEngine
{
    public const MISSING_NODE = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly ConditionEvaluator $conditions,
        private readonly ApprovalEligibility $eligibility,
        private readonly WorkflowInstanceState $state,
        private readonly WorkflowEventWriter $events,
        private readonly NodeRegistry $registry,
        private readonly NotificationNodeExecutor $notifications,
        private readonly AssignmentNodeExecutor $assignments,
        private readonly DelayNodeExecutor $delays,
    ) {}

    public function run(WorkflowInstance $instance, WorkflowVersion $version, User $actor): void
    {
        $graph = $this->graph($instance, $version);
        $start = $graph->startId();

        if ($start === null) {
            $this->fail($instance, $actor, WorkflowFailureCode::InvalidGraph, null);

            return;
        }

        $this->advance($instance, $version, $actor, $start);
    }

    public function advance(WorkflowInstance $instance, WorkflowVersion $version, User $actor, string $nodeId): void
    {
        $graph = $this->graph($instance, $version);
        $limit = max($graph->nodeCount(), 1);
        $registryVersion = (int) ($version->node_registry_version ?? NodeRegistry::V1);

        for ($step = 0; $step < $limit; $step++) {
            $node = $graph->node($nodeId);

            if ($node === null || ! is_string($node['type'] ?? null)) {
                $this->fail($instance, $actor, WorkflowFailureCode::InvalidGraph, $nodeId);

                return;
            }

            $instance->current_node_id = $nodeId;
            $instance->save();
            $type = $node['type'];

            if (! $this->registry->allows($registryVersion, $type)) {
                $this->fail($instance, $actor, WorkflowFailureCode::InvalidGraph, $nodeId);

                return;
            }

            if ($type === 'start') {
                $nodeId = $this->successor($graph, $instance, $actor, $nodeId, 'out');

                if ($nodeId === null) {
                    return;
                }

                continue;
            }

            if ($type === 'condition') {
                $nodeId = $this->condition($graph, $instance, $actor, $node, $nodeId);

                if ($nodeId === null) {
                    return;
                }

                continue;
            }

            if ($type === 'approval') {
                $this->approval($instance, $actor, $node, $nodeId);

                return;
            }

            if ($type === 'revision') {
                $nodeId = $this->revision($graph, $instance, $actor, $node, $nodeId);

                if ($nodeId === null) {
                    return;
                }

                continue;
            }

            if ($type === 'notification') {
                $nodeId = $this->notifications->execute($instance, $version, $actor, $node, $nodeId, $this, $graph);

                if ($nodeId === null) {
                    return;
                }

                continue;
            }

            if ($type === 'integration_action') {
                try {
                    app(IntegrationRuntime::class)->action($instance, $node['data'] ?? [], $nodeId);
                } catch (\Throwable) {
                    $this->fail($instance, $actor, WorkflowFailureCode::InvalidGraph, $nodeId);

                    return;
                }
                $nodeId = $this->successor($graph, $instance, $actor, $nodeId, 'out');
                if ($nodeId === null) {
                    return;
                }

                continue;
            }

            if ($type === 'assignment') {
                $this->assignments->execute($instance, $actor, $node, $nodeId, $this);

                return;
            }

            if ($type === 'delay') {
                $this->delays->execute($instance, $actor, $node, $nodeId, $this);

                return;
            }

            if ($type === 'end') {
                $this->finish($instance, $actor, $node, $nodeId);

                return;
            }

            $this->fail($instance, $actor, WorkflowFailureCode::InvalidGraph, $nodeId);

            return;
        }

        $this->fail($instance, $actor, WorkflowFailureCode::CorruptState, $nodeId);
    }

    public function fail(WorkflowInstance $instance, User $actor, WorkflowFailureCode $code, ?string $nodeId): void
    {
        if ($instance->status !== WorkflowInstanceStatus::Running) {
            throw new WorkflowConflictException('This workflow instance cannot change to that state.');
        }

        $instance->failure_code = $code;
        $this->state->apply($instance, WorkflowInstanceStatus::Failed);
        $this->events->record($instance, $actor, WorkflowEventType::InstanceFailed, $nodeId, [
            'failure_code' => $code->value,
            'recoverable' => $code->recoverable(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function openHumanTask(
        WorkflowInstance $instance,
        User $actor,
        array $attributes,
        WorkflowInstanceStatus $waiting,
        ?array $revision = null,
    ): void {
        $this->openTask($instance, $actor, $attributes, $waiting, $revision);
    }

    private function graph(WorkflowInstance $instance, WorkflowVersion $version): PublishedGraph
    {
        if ($instance->workflow_version_id !== $version->id || $version->workflow_id !== $instance->workflow_id) {
            throw new WorkflowConflictException('This workflow instance is no longer consistent.');
        }

        return PublishedGraph::fromVersion($version);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function condition(PublishedGraph $graph, WorkflowInstance $instance, User $actor, array $node, string $nodeId): ?string
    {
        try {
            $evaluation = $this->conditions->evaluate($node, $graph->fields(), $instance->submitted_data ?? []);
        } catch (WorkflowEvaluationException $exception) {
            $this->fail($instance, $actor, $exception->failureCode, $nodeId);

            return null;
        }

        $this->events->record($instance, $actor, WorkflowEventType::ConditionEvaluated, $nodeId, [
            'match' => $this->data($node)['match'] ?? 'all',
            'outcome' => $evaluation['outcome'],
            'rules' => $evaluation['rules'],
        ]);

        return $this->successor($graph, $instance, $actor, $nodeId, $evaluation['outcome'] ? 'true' : 'false');
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function approval(WorkflowInstance $instance, User $actor, array $node, string $nodeId): void
    {
        $roles = $this->roles($node);

        if (! $this->eligibility->eligibleExists($instance, $roles)) {
            $this->fail($instance, $actor, WorkflowFailureCode::NoEligibleApprover, $nodeId);

            return;
        }

        $this->openTask($instance, $actor, [
            'node_id' => $nodeId,
            'task_type' => WorkflowTaskType::Approval,
            'assigned_role' => count($roles) === 1 ? $roles[0] : null,
            'status' => WorkflowTaskStatus::Open,
            'assigned_at' => now(),
        ], WorkflowInstanceStatus::AwaitingApproval, null);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function revision(PublishedGraph $graph, WorkflowInstance $instance, User $actor, array $node, string $nodeId): ?string
    {
        $max = $this->data($node)['max_returns'] ?? null;

        if (! is_int($max) || $max < 1 || $max > 10) {
            $this->fail($instance, $actor, WorkflowFailureCode::InvalidGraph, $nodeId);

            return null;
        }

        $counter = WorkflowRevisionCounter::query()
            ->where('workflow_instance_id', $instance->id)
            ->where('revision_node_id', $nodeId)
            ->lockForUpdate()
            ->first();
        $attempts = $counter?->attempt_count ?? 0;

        if ($attempts >= $max) {
            $this->events->record($instance, $actor, WorkflowEventType::RevisionExhausted, $nodeId, [
                'attempt_count' => $attempts,
                'max_returns' => $max,
            ]);

            return $this->successor($graph, $instance, $actor, $nodeId, 'exhausted');
        }

        if ($instance->submitted_by === null) {
            $this->fail($instance, $actor, WorkflowFailureCode::CorruptState, $nodeId);

            return null;
        }

        if ($counter === null) {
            $counter = new WorkflowRevisionCounter([
                'organization_id' => $instance->organization_id,
                'workflow_instance_id' => $instance->id,
                'revision_node_id' => $nodeId,
                'attempt_count' => 0,
            ]);
        }

        $counter->attempt_count = $attempts + 1;
        $counter->save();

        $this->openTask($instance, $actor, [
            'node_id' => $nodeId,
            'task_type' => WorkflowTaskType::Revision,
            'assigned_user_id' => $instance->submitted_by,
            'status' => WorkflowTaskStatus::Open,
            'assigned_at' => now(),
        ], WorkflowInstanceStatus::AwaitingRevision, [
            'attempt_count' => $counter->attempt_count,
            'max_returns' => $max,
        ]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function finish(WorkflowInstance $instance, User $actor, array $node, string $nodeId): void
    {
        $result = $this->data($node)['result'] ?? null;

        if ($result === 'completed') {
            $this->state->apply($instance, WorkflowInstanceStatus::Completed);
            $this->events->record($instance, $actor, WorkflowEventType::InstanceCompleted, $nodeId, [
                'result' => 'completed',
            ]);

            return;
        }

        if ($result === 'rejected') {
            $this->state->apply($instance, WorkflowInstanceStatus::Rejected);
            $this->events->record($instance, $actor, WorkflowEventType::InstanceRejected, $nodeId, [
                'result' => 'rejected',
            ]);

            return;
        }

        $this->fail($instance, $actor, WorkflowFailureCode::InvalidGraph, $nodeId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $revision
     */
    private function openTask(WorkflowInstance $instance, User $actor, array $attributes, WorkflowInstanceStatus $waiting, ?array $revision): void
    {
        $open = WorkflowTask::query()
            ->where('workflow_instance_id', $instance->id)
            ->where('status', WorkflowTaskStatus::Open)
            ->lockForUpdate()
            ->exists();

        if ($open) {
            $this->fail($instance, $actor, WorkflowFailureCode::CorruptState, $attributes['node_id']);

            return;
        }

        try {
            $task = WorkflowTask::query()->create([
                'organization_id' => $instance->organization_id,
                'workflow_instance_id' => $instance->id,
                ...$attributes,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new WorkflowConflictException('This workflow is already advancing.');
        }

        $this->state->apply($instance, $waiting);
        $this->events->record($instance, $actor, WorkflowEventType::TaskCreated, $task->node_id, [
            'task_uuid' => $task->uuid,
            'task_type' => $task->task_type->value,
        ]);

        if ($revision !== null) {
            $this->events->record($instance, $actor, WorkflowEventType::RevisionRequested, $task->node_id, [
                'task_uuid' => $task->uuid,
                ...$revision,
            ]);
        }
    }

    private function successor(PublishedGraph $graph, WorkflowInstance $instance, User $actor, string $nodeId, string $handle): ?string
    {
        $target = $graph->successor($nodeId, $handle);

        if ($target === null || $graph->node($target) === null) {
            $this->fail($instance, $actor, WorkflowFailureCode::InvalidGraph, $nodeId);

            return null;
        }

        return $target;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function roles(array $node): array
    {
        $roles = $this->data($node)['assignee_roles'] ?? null;

        if (! is_array($roles)) {
            return [];
        }

        return array_values(array_filter($roles, is_string(...)));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function data(array $node): array
    {
        return is_array($node['data'] ?? null) ? $node['data'] : [];
    }
}
