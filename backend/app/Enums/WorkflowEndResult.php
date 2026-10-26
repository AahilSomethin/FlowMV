<?php

namespace App\Enums;

enum WorkflowEndResult: string
{
    case Completed = 'completed';
    case Rejected = 'rejected';
}
