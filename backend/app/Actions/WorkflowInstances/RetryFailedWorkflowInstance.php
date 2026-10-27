<?php

namespace App\Actions\WorkflowInstances;

use App\Enums\OrganizationRole;
use App\Enums\WorkflowFailureCode;
use App\Enums\WorkflowInstanceStatus;
use App\Exceptions\WorkflowConflictException;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\Workflows\Execution\ApprovalEligibility;
use App\Services\Workflows\Execution\PinnedWorkflowVersion;
use App\Services\Workflows\Execution\PublishedGraph;
use App\Services\Workflows\Execution\WorkflowEngine;
use App\Services\Workflows\Execution\WorkflowInstanceState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class RetryFailedWorkflowInstance
{
    public function __construct(
        private readonly ApprovalEligibility $eligibility,
        private readonly PinnedWorkflowVersion $versions,
        private readonly WorkflowInstanceState $state,
        private readonly WorkflowEngine $engine,
        private readonly LoadWorkflowInstance $loader,
    ) {}

    public function __invoke(User $user, WorkflowInstance $instance): WorkflowInstance
    {
        return DB::transaction(function () use ($user, $instance) {
            $locked = WorkflowInstance::query()->whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $role = $this->eligibility->role($user, $locked->organization_id);

            if ($role === null) {
                throw (new ModelNotFoundException)->setModel(WorkflowInstance::class, [$locked->uuid]);
            }

            if ($role !== OrganizationRole::Admin) {
                throw new AuthorizationException;
            }

            $code = $locked->failure_code;

            if ($locked->status !== WorkflowInstanceStatus::Failed || $code === null || ! $code->recoverable()) {
                throw new WorkflowConflictException('This workflow instance cannot be retried.');
            }

            $version = $this->versions->forInstance($locked);
            $node = PublishedGraph::fromVersion($version)->node($locked->current_node_id);
            $expectedType = match ($code) {
                WorkflowFailureCode::NoEligibleApprover => 'approval',
                WorkflowFailureCode::NoEligibleAssignee => 'assignment',
                WorkflowFailureCode::NoNotificationRecipients => 'notification',
                default => null,
            };

            if ($node === null || ($node['type'] ?? null) !== $expectedType) {
                throw new WorkflowConflictException('This workflow instance cannot be retried.');
            }

            $versionId = $version->id;
            $this->state->apply($locked, WorkflowInstanceStatus::Running);
            $this->engine->advance($locked, $version, $user, $locked->current_node_id);
            $locked->refresh();

            if ($locked->workflow_version_id !== $versionId) {
                throw new WorkflowConflictException('This workflow instance is no longer consistent.');
            }

            if ($locked->status === WorkflowInstanceStatus::Failed && $locked->failure_code === $code) {
                throw new WorkflowConflictException(match ($code) {
                    WorkflowFailureCode::NoEligibleApprover => 'Nobody is eligible to approve this request yet.',
                    WorkflowFailureCode::NoEligibleAssignee => 'Nobody is eligible to complete this assignment yet.',
                    WorkflowFailureCode::NoNotificationRecipients => 'No notification recipients are available yet.',
                    default => 'This workflow instance cannot be retried.',
                });
            }

            return ($this->loader)($locked);
        });
    }
}
