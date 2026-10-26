<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkflowExecutionLocator
{
    public function __construct(private readonly ActiveMembershipLocator $memberships) {}

    public function instance(Organization $organization, string $uuid): WorkflowInstance
    {
        $this->guardUuid(WorkflowInstance::class, $uuid);

        return $organization->workflowInstances()->where('uuid', $uuid)->firstOrFail();
    }

    public function instanceFrom(Request $request): WorkflowInstance
    {
        $resolved = $request->attributes->get('resolved_workflow_instance');

        if ($resolved instanceof WorkflowInstance) {
            return $resolved;
        }

        $instance = $this->instance(
            $this->memberships->organizationFrom($request),
            (string) $request->route('instance'),
        );
        $request->attributes->set('resolved_workflow_instance', $instance);

        return $instance;
    }

    public function task(Organization $organization, string $uuid): WorkflowTask
    {
        $this->guardUuid(WorkflowTask::class, $uuid);

        return WorkflowTask::query()
            ->where('organization_id', $organization->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    public function taskFrom(Request $request): WorkflowTask
    {
        $resolved = $request->attributes->get('resolved_workflow_task');

        if ($resolved instanceof WorkflowTask) {
            return $resolved;
        }

        $task = $this->task(
            $this->memberships->organizationFrom($request),
            (string) $request->route('task'),
        );
        $request->attributes->set('resolved_workflow_task', $task);

        return $task;
    }

    private function guardUuid(string $model, string $uuid): void
    {
        if (! Str::isUuid($uuid)) {
            throw (new ModelNotFoundException)->setModel($model, [$uuid]);
        }
    }
}
