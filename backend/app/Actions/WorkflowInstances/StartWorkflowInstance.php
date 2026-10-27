<?php

namespace App\Actions\WorkflowInstances;

use App\Enums\SnapshotReason;
use App\Enums\WorkflowEventType;
use App\Enums\WorkflowFailureCode;
use App\Enums\WorkflowInstanceStatus;
use App\Enums\WorkflowStatus;
use App\Exceptions\WorkflowConflictException;
use App\Exceptions\WorkflowEvaluationException;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowVersion;
use App\Services\Workflows\Execution\FormSubmissionValidator;
use App\Services\Workflows\Execution\PublishedGraph;
use App\Services\Workflows\Execution\SubmissionSnapshotWriter;
use App\Services\Workflows\Execution\WorkflowEngine;
use App\Services\Workflows\Execution\WorkflowEventWriter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartWorkflowInstance
{
    public function __construct(
        private readonly FormSubmissionValidator $forms,
        private readonly WorkflowEngine $engine,
        private readonly WorkflowEventWriter $events,
        private readonly SubmissionSnapshotWriter $snapshots,
        private readonly LoadWorkflowInstance $loader,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(User $user, Organization $organization, Workflow $workflow, array $data): WorkflowInstance
    {
        return DB::transaction(function () use ($user, $organization, $workflow, $data) {
            $locked = Workflow::query()->whereKey($workflow->id)->lockForUpdate()->firstOrFail();

            if ($locked->organization_id !== $organization->id) {
                throw (new ModelNotFoundException)->setModel(Workflow::class, [$workflow->uuid]);
            }

            if ($locked->status !== WorkflowStatus::Published || $locked->current_published_version_id === null) {
                throw new WorkflowConflictException('Only a published workflow can accept new requests.');
            }

            $version = WorkflowVersion::query()->whereKey($locked->current_published_version_id)->firstOrFail();

            if ($version->workflow_id !== $locked->id) {
                throw new WorkflowConflictException('This workflow instance is no longer consistent.');
            }

            $graph = PublishedGraph::fromVersion($version);

            if ($graph->requiresUpload()) {
                throw ValidationException::withMessages([
                    'data' => 'This workflow collects files, which cannot be submitted yet.',
                ]);
            }

            $failure = null;

            try {
                $submitted = $this->forms->validate($graph->fields(), $data);
            } catch (WorkflowEvaluationException) {
                $submitted = [];
                $failure = true;
            }

            $instance = WorkflowInstance::query()->create([
                'organization_id' => $organization->id,
                'workflow_id' => $locked->id,
                'workflow_version_id' => $version->id,
                'submitted_by' => $user->id,
                'status' => WorkflowInstanceStatus::Running,
                'current_node_id' => $graph->startId() ?? WorkflowEngine::MISSING_NODE,
                'submitted_data' => $submitted,
                'started_at' => now(),
            ]);
            $this->snapshots->capture($instance, SnapshotReason::Initial);

            $this->events->record($instance, $user, WorkflowEventType::InstanceStarted, $graph->startId(), [
                'workflow_version_uuid' => $version->uuid,
                'version_number' => $version->version_number,
            ]);

            if ($failure === true || $graph->startId() === null) {
                $this->engine->fail($instance, $user, WorkflowFailureCode::InvalidGraph, $graph->startId());
            } else {
                $this->engine->run($instance, $version, $user);
            }

            $this->guardPin($instance, $version);

            return ($this->loader)($instance);
        });
    }

    private function guardPin(WorkflowInstance $instance, WorkflowVersion $version): void
    {
        if ($instance->workflow_version_id !== $version->id) {
            throw new WorkflowConflictException('This workflow instance is no longer consistent.');
        }
    }
}
