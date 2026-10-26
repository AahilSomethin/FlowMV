<?php

namespace App\Http\Requests\WorkflowInstances;

use App\Enums\OrganizationRole;
use App\Services\Workflows\Execution\ApprovalEligibility;
use App\Services\Workflows\Execution\WorkflowInstanceVisibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class RetryWorkflowInstanceRequest extends ExecutionRequest
{
    public function authorize(): bool
    {
        $instance = $this->instance();
        $user = $this->user();
        $visibility = app(WorkflowInstanceVisibility::class);

        if ($user === null || ! $visibility->canView($user, $instance)) {
            throw (new ModelNotFoundException)->setModel($instance::class, [$instance->uuid]);
        }

        if (app(ApprovalEligibility::class)->role($user, $instance->organization_id) !== OrganizationRole::Admin) {
            throw new AuthorizationException;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
