<?php

namespace App\Enums;

enum WorkflowEventType: string
{
    case InstanceStarted = 'instance_started';
    case ConditionEvaluated = 'condition_evaluated';
    case TaskCreated = 'task_created';
    case TaskApproved = 'task_approved';
    case TaskRejected = 'task_rejected';
    case TaskCompleted = 'task_completed';
    case TaskReassigned = 'task_reassigned';
    case RevisionRequested = 'revision_requested';
    case RevisionSubmitted = 'revision_submitted';
    case RevisionExhausted = 'revision_exhausted';
    case NotificationQueued = 'notification_queued';
    case NotificationSent = 'notification_sent';
    case NotificationFailed = 'notification_failed';
    case DelayStarted = 'delay_started';
    case DelayResumed = 'delay_resumed';
    case DelayCancelled = 'delay_cancelled';
    case InstanceCompleted = 'instance_completed';
    case InstanceRejected = 'instance_rejected';
    case InstanceFailed = 'instance_failed';
}
