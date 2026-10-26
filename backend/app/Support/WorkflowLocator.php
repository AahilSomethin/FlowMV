<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkflowLocator
{
    public function __construct(private readonly ActiveMembershipLocator $memberships) {}

    public function workflow(Organization $organization, string $uuid): Workflow
    {
        $this->guardUuid(Workflow::class, $uuid);

        return $organization->workflows()->where('uuid', $uuid)->firstOrFail();
    }

    public function workflowFrom(Request $request): Workflow
    {
        $resolved = $request->attributes->get('resolved_workflow');

        if ($resolved instanceof Workflow) {
            return $resolved;
        }

        $workflow = $this->workflow(
            $this->memberships->organizationFrom($request),
            (string) $request->route('workflow'),
        );
        $request->attributes->set('resolved_workflow', $workflow);

        return $workflow;
    }

    public function version(Workflow $workflow, string $uuid): WorkflowVersion
    {
        $this->guardUuid(WorkflowVersion::class, $uuid);

        return $workflow->versions()->where('uuid', $uuid)->firstOrFail();
    }

    public function versionFrom(Request $request): WorkflowVersion
    {
        $resolved = $request->attributes->get('resolved_workflow_version');

        if ($resolved instanceof WorkflowVersion) {
            return $resolved;
        }

        $version = $this->version($this->workflowFrom($request), (string) $request->route('version'));
        $request->attributes->set('resolved_workflow_version', $version);

        return $version;
    }

    private function guardUuid(string $model, string $uuid): void
    {
        if (! Str::isUuid($uuid)) {
            throw (new ModelNotFoundException)->setModel($model, [$uuid]);
        }
    }
}
