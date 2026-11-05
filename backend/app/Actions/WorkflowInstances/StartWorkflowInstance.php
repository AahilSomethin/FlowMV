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
    public function __invoke(User $user, Organization $organization, Workflow $workflow, array $data, ?WorkflowVersion $triggerVersion = null, ?string $activationId = null, ?string $emailIntakeId = null, ?string $slackActivationId = null): WorkflowInstance
    {
        return DB::transaction(function () use ($user, $organization, $workflow, $data, $triggerVersion, $activationId, $emailIntakeId, $slackActivationId) {
            $locked = Workflow::query()->whereKey($workflow->id)->lockForUpdate()->firstOrFail();

            if ($locked->organization_id !== $organization->id) {
                throw (new ModelNotFoundException)->setModel(Workflow::class, [$workflow->uuid]);
            }

            if ($locked->status !== WorkflowStatus::Published || $locked->current_published_version_id === null) {
                throw new WorkflowConflictException('Only a published workflow can accept new requests.');
            }

            $version = WorkflowVersion::query()->whereKey($triggerVersion?->id ?? $locked->current_published_version_id)->firstOrFail();

            if ($version->workflow_id !== $locked->id) {
                throw new WorkflowConflictException('This workflow instance is no longer consistent.');
            }

            $graph = PublishedGraph::fromVersion($version);
            $triggerType = $graph->node($graph->startId() ?? '')['type'] ?? null;
            $external = in_array($triggerType, ['telegram_trigger', 'email_trigger', 'slack_trigger'], true);
            abort_if($external && ($triggerVersion === null || ($activationId === null && $emailIntakeId === null && $slackActivationId === null)), 422, 'This workflow starts through its authorized external trigger.');
            if ($triggerVersion !== null) {
                $proof = $triggerType === 'email_trigger'
                    ? DB::table('email_intakes as i')->join('email_trigger_bindings as b', 'b.id', '=', 'i.binding_id')->where('i.id', $emailIntakeId)->where('i.actor_user_id', $user->id)->where('i.status', 'processing')->whereNull('i.workflow_instance_id')->where('b.workflow_version_id', $version->id)->where('b.organization_id', $organization->id)->exists()
                    : DB::table('integration_trigger_activations as a')->join('integration_trigger_subscriptions as s', 's.id', '=', 'a.subscription_id')->where('a.id', $activationId)->where('a.status', 'processing')->where('s.workflow_version_id', $version->id)->where('s.organization_id', $organization->id)->exists();
                if ($triggerType === 'slack_trigger') {
                    $proof = DB::table('slack_trigger_activations as a')->join('slack_trigger_subscriptions as s', 's.id', '=', 'a.subscription_id')->where('a.id', $slackActivationId)->where('a.status', 'processing')->whereNull('a.workflow_instance_id')->where('s.workflow_version_id', $version->id)->where('s.organization_id', $organization->id)->exists();
                }
                abort_unless($external && in_array($version->node_registry_version, [3, 4], true) && $proof, 403);
            }

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
            if ($activationId !== null) {
                DB::table('integration_trigger_activations')->where('id', $activationId)->update(['workflow_instance_id' => $instance->id]);
            }
            if ($slackActivationId !== null) {
                DB::table('slack_trigger_activations')->where('id', $slackActivationId)->update(['workflow_instance_id' => $instance->id]);
            }
            if ($emailIntakeId !== null) {
                DB::table('email_intakes')->where('id', $emailIntakeId)->update(['workflow_instance_id' => $instance->id]);
            }
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
