<?php

namespace App\Enums;

enum WorkflowTaskType: string
{
    case Approval = 'approval';
    case Revision = 'revision';
    case Assignment = 'assignment';
}
