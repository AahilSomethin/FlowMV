<?php

namespace App\Enums;

enum WorkflowDelayStatus: string
{
    case Pending = 'pending';
    case Consumed = 'consumed';
    case Cancelled = 'cancelled';
}
