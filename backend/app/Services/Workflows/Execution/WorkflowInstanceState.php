<?php

namespace App\Services\Workflows\Execution;

use App\Enums\WorkflowInstanceStatus;
use App\Exceptions\WorkflowConflictException;
use App\Models\WorkflowInstance;

class WorkflowInstanceState
{
    public function apply(WorkflowInstance $instance, WorkflowInstanceStatus $to): void
    {
        $allowed = match ($instance->status) {
            WorkflowInstanceStatus::Running => [
                WorkflowInstanceStatus::AwaitingApproval,
                WorkflowInstanceStatus::AwaitingRevision,
                WorkflowInstanceStatus::Completed,
                WorkflowInstanceStatus::Rejected,
                WorkflowInstanceStatus::Failed,
            ],
            WorkflowInstanceStatus::AwaitingApproval,
            WorkflowInstanceStatus::AwaitingRevision,
            WorkflowInstanceStatus::Failed => [WorkflowInstanceStatus::Running],
            WorkflowInstanceStatus::Completed,
            WorkflowInstanceStatus::Rejected => [],
        };

        if (! in_array($to, $allowed, true)) {
            throw new WorkflowConflictException('This workflow instance cannot change to that state.');
        }

        if ($to !== WorkflowInstanceStatus::Failed) {
            $instance->failure_code = null;
        }

        if ($to === WorkflowInstanceStatus::Running) {
            $instance->completed_at = null;
        }

        if (in_array($to, [WorkflowInstanceStatus::Completed, WorkflowInstanceStatus::Rejected], true)) {
            $instance->completed_at = now();
        }

        if ($to === WorkflowInstanceStatus::Failed) {
            $instance->completed_at = $instance->failure_code?->recoverable() === true ? null : now();
        }

        $instance->status = $to;
        $instance->save();
    }
}
