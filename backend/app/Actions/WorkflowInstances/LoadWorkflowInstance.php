<?php

namespace App\Actions\WorkflowInstances;

use App\Exceptions\WorkflowConflictException;
use App\Models\WorkflowInstance;

class LoadWorkflowInstance
{
    public function __invoke(WorkflowInstance $instance): WorkflowInstance
    {
        $fresh = $instance->fresh();

        if ($fresh === null) {
            throw new WorkflowConflictException('This workflow instance is no longer consistent.');
        }

        $fresh->load([
            'workflow',
            'version',
            'submitter',
            'tasks',
            'tasks.decisionSnapshot',
            'events' => fn ($query) => $query->orderBy('id')->with('actor'),
        ]);
        $fresh->setAttribute('present_timeline', true);

        return $fresh;
    }
}
