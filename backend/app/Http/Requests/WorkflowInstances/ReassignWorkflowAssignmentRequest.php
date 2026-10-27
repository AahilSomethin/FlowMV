<?php

namespace App\Http\Requests\WorkflowInstances;

use App\Enums\OrganizationRole;
use App\Services\Workflows\Execution\ApprovalEligibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Rule;

class ReassignWorkflowAssignmentRequest extends ExecutionRequest
{
    public function authorize(): bool
    {
        $task = $this->task()->load('instance');
        $user = $this->user();
        $role = $user === null ? null : app(ApprovalEligibility::class)->role($user, $this->organization()->id);

        if ($role === null) {
            throw (new ModelNotFoundException)->setModel($task::class, [$task->uuid]);
        }

        if ($role !== OrganizationRole::Admin) {
            throw new AuthorizationException;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assignee_user_id' => [
                'required',
                'integer',
                Rule::exists('organization_memberships', 'user_id')
                    ->where(fn ($query) => $query
                        ->where('organization_id', $this->organization()->id)
                        ->whereNull('revoked_at')),
            ],
        ];
    }
}
