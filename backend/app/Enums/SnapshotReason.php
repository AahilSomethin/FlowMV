<?php

namespace App\Enums;

enum SnapshotReason: string
{
    case Initial = 'initial';
    case ApprovalDecision = 'approval_decision';
    case RevisionSubmitted = 'revision_submitted';
}
