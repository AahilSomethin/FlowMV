<?php

namespace App\Services\Workflows\Execution;

use App\Exceptions\WorkflowConflictException;
use App\Models\WorkflowInstance;
use App\Models\WorkflowVersion;

class PinnedWorkflowVersion
{
    public function forInstance(WorkflowInstance $instance): WorkflowVersion
    {
        $version = WorkflowVersion::query()->find($instance->workflow_version_id);

        if (! $version instanceof WorkflowVersion || $version->workflow_id !== $instance->workflow_id) {
            throw new WorkflowConflictException('This workflow instance is no longer consistent.');
        }

        return $version;
    }
}
