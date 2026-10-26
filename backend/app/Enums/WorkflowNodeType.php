<?php

namespace App\Enums;

enum WorkflowNodeType: string
{
    case Start = 'start';
    case Approval = 'approval';
    case Condition = 'condition';
    case Revision = 'revision';
    case End = 'end';
}
