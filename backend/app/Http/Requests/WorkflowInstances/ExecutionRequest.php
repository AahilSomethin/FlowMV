<?php

namespace App\Http\Requests\WorkflowInstances;

use App\Models\Organization;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Support\ActiveMembershipLocator;
use App\Support\WorkflowExecutionLocator;
use App\Support\WorkflowLocator;
use Illuminate\Foundation\Http\FormRequest;

abstract class ExecutionRequest extends FormRequest
{
    public function organization(): Organization
    {
        return app(ActiveMembershipLocator::class)->organizationFrom($this);
    }

    public function workflow(): Workflow
    {
        return app(WorkflowLocator::class)->workflowFrom($this);
    }

    public function instance(): WorkflowInstance
    {
        return app(WorkflowExecutionLocator::class)->instanceFrom($this);
    }

    public function task(): WorkflowTask
    {
        return app(WorkflowExecutionLocator::class)->taskFrom($this);
    }
}
