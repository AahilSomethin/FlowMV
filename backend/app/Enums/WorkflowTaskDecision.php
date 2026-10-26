<?php

namespace App\Enums;

enum WorkflowTaskDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
}
