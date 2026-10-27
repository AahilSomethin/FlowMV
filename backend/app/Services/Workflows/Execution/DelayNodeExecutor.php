<?php

namespace App\Services\Workflows\Execution;

use App\Enums\WorkflowDelayStatus;
use App\Enums\WorkflowEventType;
use App\Enums\WorkflowFailureCode;
use App\Enums\WorkflowInstanceStatus;
use App\Jobs\ResumeDelayedWorkflowInstance;
use App\Models\User;
use App\Models\WorkflowDelay;
use App\Models\WorkflowInstance;
use App\Services\Workflows\WorkflowDefinitionNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DelayNodeExecutor
{
    public function __construct(
        private readonly WorkflowInstanceState $state,
        private readonly WorkflowEventWriter $events,
    ) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public function execute(
        WorkflowInstance $instance,
        User $actor,
        array $node,
        string $nodeId,
        WorkflowEngine $engine,
    ): void {
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        $resumeAt = $this->resolveResumeAt($data);

        if ($resumeAt === null) {
            $engine->fail($instance, $actor, WorkflowFailureCode::InvalidGraph, $nodeId);

            return;
        }

        $pending = WorkflowDelay::query()
            ->where('workflow_instance_id', $instance->id)
            ->where('status', WorkflowDelayStatus::Pending)
            ->lockForUpdate()
            ->exists();

        if ($pending) {
            $engine->fail($instance, $actor, WorkflowFailureCode::CorruptState, $nodeId);

            return;
        }

        try {
            $delay = WorkflowDelay::query()->create([
                'organization_id' => $instance->organization_id,
                'workflow_instance_id' => $instance->id,
                'node_id' => $nodeId,
                'resume_at' => $resumeAt,
                'status' => WorkflowDelayStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new \App\Exceptions\WorkflowConflictException('This workflow is already advancing.');
        }

        $instance->resume_at = $resumeAt;
        $instance->save();
        $this->state->apply($instance, WorkflowInstanceStatus::AwaitingDelay);
        $this->events->record($instance, $actor, WorkflowEventType::DelayStarted, $nodeId, [
            'delay_uuid' => $delay->uuid,
            'resume_at' => $resumeAt->toIso8601String(),
        ]);

        $seconds = max(0, now()->diffInSeconds($resumeAt, false));

        DB::afterCommit(function () use ($delay, $seconds): void {
            ResumeDelayedWorkflowInstance::dispatch($delay->id)->delay(now()->addSeconds($seconds));
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveResumeAt(array $data): ?Carbon
    {
        $mode = ($data['resume_mode'] ?? 'duration') === 'until' ? 'until' : 'duration';

        if ($mode === 'duration') {
            $seconds = $data['duration_seconds'] ?? null;

            if (! is_int($seconds) || $seconds < 1 || $seconds > WorkflowDefinitionNormalizer::MAX_DELAY_SECONDS) {
                return null;
            }

            return now()->addSeconds($seconds);
        }

        $raw = $data['resume_at'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $resumeAt = Carbon::parse($raw)->utc();
        } catch (\Throwable) {
            return null;
        }

        if ($resumeAt->lessThanOrEqualTo(now())) {
            return null;
        }

        if ($resumeAt->greaterThan(now()->addSeconds(WorkflowDefinitionNormalizer::MAX_DELAY_SECONDS))) {
            return null;
        }

        return $resumeAt;
    }
}
