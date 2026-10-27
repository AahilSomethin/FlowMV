<?php

namespace App\Enums;

enum WorkflowFailureCode: string
{
    case NoEligibleApprover = 'no_eligible_approver';
    case NoEligibleAssignee = 'no_eligible_assignee';
    case NoNotificationRecipients = 'no_notification_recipients';
    case InvalidGraph = 'invalid_graph';
    case UnknownOperator = 'unknown_operator';
    case CorruptState = 'corrupt_state';

    public function recoverable(): bool
    {
        return in_array($this, [
            self::NoEligibleApprover,
            self::NoEligibleAssignee,
            self::NoNotificationRecipients,
        ], true);
    }
}
