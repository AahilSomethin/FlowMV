<?php

namespace App\Enums;

enum WorkflowEventType: string
{
    case InstanceStarted = 'instance_started';
    case ConditionEvaluated = 'condition_evaluated';
    case TaskCreated = 'task_created';
    case TaskApproved = 'task_approved';
    case TaskRejected = 'task_rejected';
    case RevisionRequested = 'revision_requested';
    case RevisionSubmitted = 'revision_submitted';
    case RevisionExhausted = 'revision_exhausted';
    case InstanceCompleted = 'instance_completed';
    case InstanceRejected = 'instance_rejected';
    case InstanceFailed = 'instance_failed';
}
