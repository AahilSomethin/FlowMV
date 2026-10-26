<?php

namespace App\Enums;

enum WorkflowTaskStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
}
