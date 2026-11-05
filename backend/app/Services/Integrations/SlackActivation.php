<?php

namespace App\Services\Integrations;

use App\Actions\WorkflowInstances\StartWorkflowInstance;
use App\Enums\WorkflowEventType;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Services\Workflows\Execution\PublishedGraph;
use App\Services\Workflows\Execution\WorkflowEventWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SlackActivation
{
    public function __construct(private SlackAuthorization $authorization, private ConnectionAccess $access, private CredentialVault $vault, private ConnectionManager $audit) {}

    public function process(string $id): void
    {
        try {
            DB::transaction(function () use ($id) {
                $a = DB::table('slack_trigger_activations')->where('id', $id)->lockForUpdate()->first();
                if (! $a || $a->status !== 'pending' || $a->available_at > now()->toDateTimeString()) {
                    return;
                }
                $s = DB::table('slack_trigger_subscriptions')->where('id', $a->subscription_id)->lockForUpdate()->first();
                abort_unless($s && $s->status === 'active', 403);
                abort_unless($this->access->role($s->authorized_by, $s->organization_id) === 'admin', 403);
                $event = DB::table('slack_inbound_events')->where('id', $a->event_id)->first();
                abort_unless($event && $event->connection_id === $s->connection_id, 403);
                $payload = $this->vault->decrypt($event, $event->connection_id.':event:'.$event->id);
                $link = DB::table('slack_identity_links')->where('connection_id', $s->connection_id)->where('slack_user_id', $payload['sender_id'])->where('user_id', $payload['actor_user_id'])->where('confirmed', true)->first();
                abort_unless($link, 403);
                $this->authorization->workflow($s->organization_id, $s->workflow_id, $s->connection_id, 'slack.message', $s->destination, (int) $link->user_id);
                abort_unless($s->destination === $payload['channel_id'], 403);
                $version = WorkflowVersion::findOrFail($s->workflow_version_id);
                abort_unless($version->workflow_id === $s->workflow_id && in_array($version->node_registry_version, [3, 4], true), 403);
                $node = PublishedGraph::fromVersion($version)->node($s->node_id);
                abort_unless(($node['type'] ?? '') === 'slack_trigger' && $node['data']['connection_id'] === $s->connection_id && $node['data']['destination'] === $s->destination, 403);
                $data = [];
                $fields = collect($version->form_definition['fields'])->keyBy('id');
                foreach ($node['data']['input_mapping'] as $field => $source) {
                    $value = $payload[$source] ?? '';
                    $data[$field] = ($fields[$field]['type'] ?? '') === 'number' ? (int) $value : (string) $value;
                }
                DB::table('slack_trigger_activations')->where('id', $id)->update(['status' => 'processing', 'attempt_count' => $a->attempt_count + 1, 'updated_at' => now()]);
                $actor = User::findOrFail($link->user_id);
                $instance = app(StartWorkflowInstance::class)($actor, Organization::findOrFail($s->organization_id), Workflow::findOrFail($s->workflow_id), $data, $version, null, null, $id);
                app(WorkflowEventWriter::class)->record($instance, $actor, WorkflowEventType::SlackTriggerActivated, $s->node_id, ['activation_uuid' => $id, 'subscription_uuid' => $s->id]);
                DB::table('slack_trigger_activations')->where('id', $id)->update(['status' => 'done', 'workflow_instance_id' => $instance->id, 'updated_at' => now()]);
                $this->audit->audit($s->organization_id, $s->connection_id, $actor->id, 'slack.trigger.activated');
            }, 3);
        } catch (\Throwable $error) {
            if (app()->environment('demo') && config('demo.seeding') === true) {
                throw $error;
            }
            $blocked = $error instanceof HttpExceptionInterface || $error instanceof ValidationException;
            DB::transaction(function () use ($id, $blocked) {
                $a = DB::table('slack_trigger_activations')->where('id', $id)->where('status', 'pending')->lockForUpdate()->first();
                if (! $a) {
                    return;
                }
                $attempts = $a->attempt_count + 1;
                DB::table('slack_trigger_activations')->where('id', $id)->update(['status' => $blocked ? 'blocked' : ($attempts >= 3 ? 'failed' : 'pending'), 'error_code' => $blocked ? 'authorization_or_input' : 'activation_unavailable', 'attempt_count' => $attempts, 'available_at' => now()->addSeconds(30), 'updated_at' => now()]);
                $s = DB::table('slack_trigger_subscriptions')->where('id', $a->subscription_id)->first();
                if ($s) {
                    $this->audit->audit($s->organization_id, $s->connection_id, null, 'slack.trigger.blocked');
                }
            });
        }
    }
}
