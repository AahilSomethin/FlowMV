<?php

namespace App\Services\Workflows\Execution;

use App\Enums\SnapshotReason;
use App\Models\WorkflowInstance;
use App\Models\WorkflowSubmissionSnapshot;
use App\Models\WorkflowTask;
use RuntimeException;

/**
 * Copies the submission that existed at a workflow point.
 * Rows are not logged, backfilled, or returned from analytics and audit.
 */
class SubmissionSnapshotWriter
{
    public function capture(WorkflowInstance $instance, SnapshotReason $reason, ?WorkflowTask $task = null): void
    {
        if ($task !== null && (int) $task->organization_id !== (int) $instance->organization_id) {
            throw new RuntimeException('Snapshot organization does not match the request.');
        }

        if ($task !== null && (int) $task->workflow_instance_id !== (int) $instance->id) {
            throw new RuntimeException('Snapshot task does not match the request.');
        }

        WorkflowSubmissionSnapshot::query()->create([
            'organization_id' => $instance->organization_id,
            'workflow_instance_id' => $instance->id,
            'workflow_task_id' => $task?->id,
            'reason' => $reason,
            'submitted_data' => $instance->submitted_data ?? [],
            'captured_at' => now(),
        ]);
    }
}
