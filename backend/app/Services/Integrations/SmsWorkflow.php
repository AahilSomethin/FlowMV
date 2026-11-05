<?php

namespace App\Services\Integrations;

use App\Enums\WorkflowEventType;
use App\Jobs\ProcessSmsDelivery;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowVersion;
use App\Services\Workflows\Execution\PublishedGraph;
use App\Services\Workflows\Execution\WorkflowEventWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SmsWorkflow
{
    public function __construct(private SmsAuthorization $authorization, private CredentialVault $vault, private WorkflowEventWriter $events, private ConnectionManager $audit) {}

    public function operation(string $type): string
    {
        return $type === 'sms_notification' ? 'sms.notification' : 'sms.send_message';
    }

    public function publication(int $org, int $workflow, array $node): void
    {
        $d = $node['data'];
        $this->authorization->workflow($org, $workflow, $d['connection_id'], $this->operation($node['type']), $d);
    }

    public function render(string $template, string $uuid, string $status, string $decision): string
    {
        app(SmsNodes::class)->template($template);
        $text = strtr($template, ['{{request.uuid}}' => $uuid, '{{request.status}}' => $status, '{{approval.decision}}' => $decision, '{{request.link}}' => rtrim(config('flowmv.frontend_url'), '/').'/requests/'.$uuid]);
        app(SmsNodes::class)->message($text);

        return $text;
    }

    public function enqueue(WorkflowInstance $instance, WorkflowVersion $version, User $actor, array $node): void
    {
        abort_unless($instance->workflow_version_id === $version->id && in_array($version->node_registry_version, [3, 4], true) && PublishedGraph::fromVersion($version)->node($node['id']) === $node, 403);
        $d = $node['data'];
        $op = $this->operation($node['type']);
        $resources = $this->authorization->workflow($instance->organization_id, $instance->workflow_id, $d['connection_id'], $op, $d, $instance->submitted_by);
        if (DB::table('sms_action_outbox')->where('workflow_instance_id', $instance->id)->where('node_id', $node['id'])->exists()) {
            return;
        }
        $decision = $instance->tasks()->whereNotNull('decision')->orderByDesc('id')->value('decision');
        $decision = $decision instanceof \BackedEnum ? $decision->value : ($decision ?? 'pending');
        $text = $this->render($resources['template']->body, $instance->uuid, $instance->status->value, $decision);
        $sender = $this->vault->decrypt($resources['sender'], 'sms-sender:'.$d['sender_id'])['phone'];
        $recipient = $this->vault->decrypt($resources['recipient'], 'sms-recipient:'.$d['recipient_id'])['phone'];
        $id = (string) Str::uuid();
        $account = DB::table('sms_accounts')->where('connection_id', $d['connection_id'])->first();
        DB::table('sms_action_outbox')->insert(['id' => $id, 'organization_id' => $instance->organization_id, 'connection_id' => $d['connection_id'], 'workflow_instance_id' => $instance->id, 'workflow_version_id' => $version->id, 'node_id' => $node['id'], 'operation' => $op, 'sender_id' => $d['sender_id'], 'recipient_id' => $d['recipient_id'], 'template_id' => $d['template_id'], 'recipient_fingerprint' => $resources['recipient']->fingerprint, 'credential_generation' => $account->credential_generation, ...$this->vault->encrypt(['text' => $text, 'sender_phone' => $sender, 'recipient_phone' => $recipient], 'sms-action:'.$id), 'status' => 'pending', 'available_at' => now(), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $this->events->record($instance, $actor, WorkflowEventType::SmsQueued, $node['id'], ['delivery_uuid' => $id]);
        $this->audit->audit($instance->organization_id, $d['connection_id'], $actor->id, 'sms.delivery.queued');
        ProcessSmsDelivery::dispatch($id)->afterCommit();
    }
}
