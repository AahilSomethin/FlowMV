<?php

namespace App\Services\AI;

use App\Enums\WorkflowEventType;
use App\Enums\WorkflowInstanceStatus;
use App\Jobs\RunAiWorkflowNode;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowVersion;
use App\Services\Workflows\Execution\PublishedGraph;
use App\Services\Workflows\Execution\WorkflowEngine;
use App\Services\Workflows\Execution\WorkflowEventWriter;
use App\Services\Workflows\Execution\WorkflowInstanceState;
use Illuminate\Support\Facades\DB;

class AiWorkflow
{
    public function publication(int $user, int $org, int $workflow, array $node): void
    {
        app(AiAccess::class)->workflow($user, $org, $workflow, $node['data']['connection_id'], AiNodes::OPERATIONS[$node['type']]);
    }

    public function node(WorkflowInstance $instance, string $nodeId): array
    {
        $version = $instance->version;
        abort_unless($version && $version->workflow_id === $instance->workflow_id && $version->node_registry_version === 4, 403);
        $node = PublishedGraph::fromVersion($version)->node($nodeId);
        abort_unless($node && isset(AiNodes::OPERATIONS[$node['type']]), 403);

        return $node;
    }

    public function execute(WorkflowInstance $instance, WorkflowVersion $version, User $actor, array $node): void
    {
        abort_unless($instance->workflow_version_id === $version->id && $this->node($instance, $node['id']) === $node, 403);
        app(WorkflowInstanceState::class)->apply($instance, WorkflowInstanceStatus::AwaitingAi);
        app(WorkflowEventWriter::class)->record($instance, $actor, WorkflowEventType::AiQueued, $node['id']);
        RunAiWorkflowNode::dispatch($instance->id, $node['id'])->afterCommit();
    }

    public function request(int $instanceId, string $nodeId): void
    {
        try {
            DB::transaction(function () use ($instanceId, $nodeId) {
                $instance = WorkflowInstance::whereKey($instanceId)->lockForUpdate()->firstOrFail();
                if ($instance->status !== WorkflowInstanceStatus::AwaitingAi || $instance->current_node_id !== $nodeId) {
                    return;
                }
                $node = $this->node($instance, $nodeId);
                $d = $node['data'];
                $source = match ($d['source_kind']) {
                    'request' => $instance->uuid,
                    'email' => DB::table('email_intakes')->where('organization_id', $instance->organization_id)->where('workflow_instance_id', $instanceId)->value('id'),
                    'slack' => DB::table('slack_trigger_activations')->where('workflow_instance_id', $instanceId)->where('status', 'done')->value('id'),
                    'document' => $d['document_field'] ? ($instance->submitted_data[$d['document_field']] ?? null) : $this->singleEmailDocument($instance),
                    default => null,
                };
                abort_unless(is_string($source), 422);
                $id = app(AiProcessing::class)->request($instance->submitted_by, $instance->organization_id, $instance->workflow_id, $d['connection_id'], AiNodes::OPERATIONS[$node['type']], $d['source_kind'], $source, $d['configuration'], $nodeId);
                DB::afterCommit(fn () => $this->resume($id));
            }, 3);
        } catch (\Throwable $error) {
            if (app()->environment('demo') && config('demo.seeding') === true) {
                throw $error;
            }
            $this->unavailable($instanceId, $nodeId);
        }
    }

    private function singleEmailDocument(WorkflowInstance $instance): ?string
    {
        $ids = DB::table('workflow_document_evidence as e')->join('email_intakes as i', 'i.workflow_instance_id', '=', 'e.workflow_instance_id')->where('e.organization_id', $instance->organization_id)->where('i.organization_id', $instance->organization_id)->where('e.workflow_instance_id', $instance->id)->distinct()->pluck('e.document_id');

        return $ids->count() === 1 ? $ids->first() : null;
    }

    public function resume(string $id): void
    {
        DB::transaction(function () use ($id) {
            $run = DB::table('ai_processing_runs')->where('id', $id)->first();
            if (! $run || $run->node_id === 'manual' || in_array($run->status, ['pending', 'processing'], true)) {
                return;
            }
            $instance = WorkflowInstance::whereKey($run->workflow_instance_id)->lockForUpdate()->first();
            if (! $instance || $instance->organization_id !== $run->organization_id || $instance->status !== WorkflowInstanceStatus::AwaitingAi || $instance->current_node_id !== $run->node_id) {
                return;
            }
            $node = $this->node($instance, $run->node_id);
            abort_unless($node['data']['connection_id'] === $run->connection_id && AiNodes::OPERATIONS[$node['type']] === $run->operation, 403);
            $handle = 'unavailable';
            try {
                $result = app(AiProcessing::class)->result($run->actor_user_id, $run->organization_id, $id);
                if (in_array($run->status, ['completed', 'uncertain'], true)) {
                    $handle = $result['advice']['outcome'];
                }
            } catch (\Throwable) {
                $handle = 'unavailable';
            }
            $this->advance($instance, $run->node_id, $handle, $id);
        }, 3);
    }

    private function unavailable(int $instanceId, string $nodeId): void
    {
        DB::transaction(function () use ($instanceId, $nodeId) {
            $instance = WorkflowInstance::whereKey($instanceId)->lockForUpdate()->first();
            if ($instance && $instance->status === WorkflowInstanceStatus::AwaitingAi && $instance->current_node_id === $nodeId) {
                $this->node($instance, $nodeId);
                $this->advance($instance, $nodeId, 'unavailable', null);
            }
        }, 3);
    }

    private function advance(WorkflowInstance $instance, string $nodeId, string $handle, ?string $run): void
    {
        $actor = User::findOrFail($instance->submitted_by);
        app(AiAccess::class)->role($actor->id, $instance->organization_id);
        $version = $instance->version;
        $target = PublishedGraph::fromVersion($version)->successor($nodeId, $handle);
        abort_unless($target, 409);
        app(WorkflowInstanceState::class)->apply($instance, WorkflowInstanceStatus::Running);
        app(WorkflowEventWriter::class)->record($instance, $actor, WorkflowEventType::AiEvaluated, $nodeId, ['run_id' => $run, 'outcome' => $handle]);
        app(WorkflowEngine::class)->advance($instance, $version, $actor, $target);
    }
}
