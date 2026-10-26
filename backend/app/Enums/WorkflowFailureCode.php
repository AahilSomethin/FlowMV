<?php

namespace App\Enums;

enum WorkflowFailureCode: string
{
    case NoEligibleApprover = 'no_eligible_approver';
    case InvalidGraph = 'invalid_graph';
    case UnknownOperator = 'unknown_operator';
    case CorruptState = 'corrupt_state';

    public function recoverable(): bool
    {
        return $this === self::NoEligibleApprover;
    }
}
