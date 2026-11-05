<?php

namespace App\Enums;

enum WorkflowEventType: string
{
    case AiQueued = 'ai_queued';
    case AiEvaluated = 'ai_evaluated';
    case InstanceStarted = 'instance_started';
    case EmailConfirmed = 'email_confirmed';
    case EmailFilterEvaluated = 'email_filter_evaluated';
    case AttachmentsInspected = 'attachments_inspected';
    case DocumentValidated = 'document_validated';
    case EmailQueued = 'email_queued';
    case EmailAccepted = 'email_accepted';
    case EmailDeliveryFailed = 'email_delivery_failed';
    case TelegramTriggerActivated = 'telegram_trigger_activated';
    case SlackTriggerActivated = 'slack_trigger_activated';
    case SmsQueued = 'sms_queued';
    case SmsAccepted = 'sms_accepted';
    case SmsDeliveryFailed = 'sms_delivery_failed';
    case SmsStatusUpdated = 'sms_status_updated';
    case SlackQueued = 'slack_queued';
    case SlackAccepted = 'slack_accepted';
    case SlackDeliveryFailed = 'slack_delivery_failed';
    case SlackFilterEvaluated = 'slack_filter_evaluated';
    case TelegramQueued = 'telegram_queued';
    case TelegramApprovalQueued = 'telegram_approval_queued';
    case TelegramApprovalAccepted = 'telegram_approval_accepted';
    case TelegramDecisionRecorded = 'telegram_decision_recorded';
    case TelegramSent = 'telegram_sent';
    case TelegramDeliveryFailed = 'telegram_delivery_failed';
    case TelegramFilterEvaluated = 'telegram_filter_evaluated';
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
