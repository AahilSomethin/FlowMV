<?php

namespace App\Enums;

enum WorkflowStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
