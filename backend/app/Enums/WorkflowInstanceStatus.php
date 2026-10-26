<?php

namespace App\Enums;

enum WorkflowInstanceStatus: string
{
    case Running = 'running';
    case AwaitingApproval = 'awaiting_approval';
    case AwaitingRevision = 'awaiting_revision';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Rejected], true);
    }
}
