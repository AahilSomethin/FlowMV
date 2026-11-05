<?php

namespace App\Services\Integrations;

use App\Enums\WorkflowEventType;
use App\Jobs\ProcessSlackDelivery;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowVersion;
use App\Services\Workflows\Execution\MessageFilter;
use App\Services\Workflows\Execution\PublishedGraph;
use App\Services\Workflows\Execution\WorkflowEventWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SlackWorkflow
{
    public function __construct(private SlackAuthorization $authorization, private CredentialVault $vault, private ConnectionManager $audit, private WorkflowEventWriter $events) {}

    public function validatePublication(int $org, int $workflowId, array $node): void
    {
        if ($node['type'] === 'slack_filter') {
            return;
        }
        $d = $node['data'];
        $op = $this->operation($node['type']);
        $this->authorization->workflow($org, $workflowId, $d['connection_id'], $op, $d['destination']);
    }

    public function operation(string $type): string
    {
        return match ($type) {
            'slack_trigger' => 'slack.message','slack_send_message' => 'slack.send_message','slack_notification' => 'slack.notification',default => ''
        };
    }

    public function subscribe(User $actor, Workflow $workflow, WorkflowVersion $version): void
    {
        DB::table('slack_trigger_subscriptions')->where('workflow_id', $workflow->id)->where('status', 'active')->update(['status' => 'paused', 'updated_at' => now()]);
        foreach ($version->graph_definition['nodes'] as $node) {
            if ($node['type'] !== 'slack_trigger') {
                continue;
            }
            $d = $node['data'];
            $id = (string) Str::uuid();
            DB::table('slack_trigger_subscriptions')->insert(['id' => $id, 'organization_id' => $workflow->organization_id, 'connection_id' => $d['connection_id'], 'workflow_id' => $workflow->id, 'workflow_version_id' => $version->id, 'node_id' => $node['id'], 'destination' => $d['destination'], 'authorized_by' => $actor->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->audit($workflow->organization_id, $d['connection_id'], $actor->id, 'slack.trigger.subscribed');
        }
    }

    public function enqueue(WorkflowInstance $instance, WorkflowVersion $version, User $actor, array $node, string $nodeId): void
    {
        abort_unless($version->id === $instance->workflow_version_id && in_array((int) $version->node_registry_version, [3, 4], true) && PublishedGraph::fromVersion($version)->node($nodeId) === $node, 403);
        $d = $node['data'];
        $op = $this->operation($node['type']);
        $c = $this->authorization->workflow($instance->organization_id, $instance->workflow_id, $d['connection_id'], $op, $d['destination'], $instance->submitted_by);
        if (DB::table('slack_action_outbox')->where('workflow_instance_id', $instance->id)->where('node_id', $nodeId)->exists()) {
            return;
        }
        $decision = $instance->tasks()->whereNotNull('decision')->orderByDesc('id')->value('decision');
        $variables = ['request.uuid' => $instance->uuid, 'request.status' => $instance->status->value, 'approval.decision' => $decision instanceof \BackedEnum ? $decision->value : ($decision ?? 'pending')];
        foreach ($d['allowed_field_ids'] as $field) {
            $value = $instance->submitted_data[$field] ?? '';
            abort_unless(is_scalar($value) || $value === null, 422);
            $variables['input.'.$field] = (string) $value;
        }
        abort_unless(app(SlackNodes::class)->templateValid($d['text'], $d['allowed_field_ids']), 422);
        $text = preg_replace_callback('/\{\{([^}]+)\}\}/u', fn ($m) => $variables[$m[1]] ?? '', $d['text']);
        abort_unless($text !== '' && mb_strlen($text) <= 4096, 422);
        $id = (string) Str::uuid();
        $bot = DB::table('slack_installations')->where('connection_id', $c->id)->first();
        DB::table('slack_action_outbox')->insert(['id' => $id, 'organization_id' => $instance->organization_id, 'connection_id' => $c->id, 'workflow_instance_id' => $instance->id, 'workflow_version_id' => $version->id, 'node_id' => $nodeId, 'operation' => $op, 'destination' => $d['destination'], 'credential_generation' => $bot->credential_generation, ...$this->vault->encrypt(['text' => $text], $c->id.':action:'.$id), 'status' => 'pending', 'attempt_count' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->events->record($instance, $actor, WorkflowEventType::SlackQueued, $nodeId, ['delivery_uuid' => $id, 'connection_id' => $c->id]);
        $this->audit->audit($instance->organization_id, $c->id, $actor->id, 'slack.delivery.queued');
        ProcessSlackDelivery::dispatch($id)->afterCommit();
    }

    public function filter(WorkflowInstance $instance, array $data): bool
    {
        $a = DB::table('slack_trigger_activations')->where('workflow_instance_id', $instance->id)->first();
        abort_unless($a, 403);
        $event = DB::table('slack_inbound_events')->where('id', $a->event_id)->first();
        abort_unless($event, 403);
        $payload = $this->vault->decrypt($event, $event->connection_id.':event:'.$event->id);

        return app(MessageFilter::class)->matches($data, $payload);
    }
}
