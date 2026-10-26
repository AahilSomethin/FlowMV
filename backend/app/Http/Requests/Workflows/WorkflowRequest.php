<?php

namespace App\Http\Requests\Workflows;

use App\Models\Organization;
use App\Models\Workflow;
use App\Support\ActiveMembershipLocator;
use App\Support\WorkflowLocator;
use Illuminate\Foundation\Http\FormRequest;

abstract class WorkflowRequest extends FormRequest
{
    public function organization(): Organization
    {
        return app(ActiveMembershipLocator::class)->organizationFrom($this);
    }

    public function workflow(): Workflow
    {
        return app(WorkflowLocator::class)->workflowFrom($this);
    }
}
