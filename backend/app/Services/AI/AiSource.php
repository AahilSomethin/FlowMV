<?php

namespace App\Services\AI;

use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\Integrations\CredentialVault;
use App\Services\Integrations\DocumentPipeline;
use App\Services\Integrations\EmailAuthorization;
use App\Services\Integrations\SlackAuthorization;
use App\Services\Workflows\Execution\WorkflowInstanceVisibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AiSource
{
    public function __construct(private AiAccess $access, private CredentialVault $vault) {}

    public function read(int $user, int $org, int $workflow, string $kind, string $id, array $inputFields = []): array
    {
        $this->access->role($user, $org);
        $actor = User::findOrFail($user);
        $instance = null;
        if ($kind === 'request') {
            $instance = WorkflowInstance::where('organization_id', $org)->where('workflow_id', $workflow)->where('uuid', $id)->firstOrFail();
            abort_unless(app(WorkflowInstanceVisibility::class)->canView($actor, $instance), 404);
            $fields = collect($instance->version->form_definition['fields'])->keyBy('id');
            abort_unless(count($inputFields) > 0 && count($inputFields) <= 40 && count(array_unique($inputFields)) === count($inputFields), 422);
            $lines = [];
            foreach ($inputFields as $field) {
                abort_unless(is_string($field) && $fields->has($field) && $fields[$field]['type'] !== 'file', 422);
                $value = $instance->submitted_data[$field] ?? '';
                abort_unless(is_scalar($value) || $value === null, 422);
                $lines[] = $fields[$field]['label'].': '.(string) $value;
            }
            $text = implode("\n", $lines);
            $category = 'workplace_request';
        } elseif ($kind === 'document') {
            $doc = app(DocumentPipeline::class)->authorize($actor, $org, $id);
            $instanceId = $doc->workflow_instance_id ?? DB::table('workflow_document_evidence')->where('organization_id', $org)->where('document_id', $id)->value('workflow_instance_id');
            $instance = WorkflowInstance::where('organization_id', $org)->where('workflow_id', $workflow)->where('id', $instanceId)->first();
            abort_unless($instance && app(WorkflowInstanceVisibility::class)->canView($actor, $instance), 404);
            $text = app(DocumentPipeline::class)->extracted($doc)['text'];
            $category = 'document_text';
        } elseif ($kind === 'email') {
            $intake = DB::table('email_intakes')->where('organization_id', $org)->where('id', $id)->first();
            abort_unless($intake, 404);
            $binding = DB::table('email_trigger_bindings')->where('id', $intake->binding_id)->where('organization_id', $org)->where('workflow_id', $workflow)->first();
            $message = DB::table('email_messages')->where('organization_id', $org)->where('id', $intake->message_id)->first();
            abort_unless($binding && $message && Carbon::parse($message->expires_at)->isFuture(), 404);
            if ($intake->workflow_instance_id) {
                $instance = WorkflowInstance::where('organization_id', $org)->where('workflow_id', $workflow)->findOrFail($intake->workflow_instance_id);
                abort_unless(app(WorkflowInstanceVisibility::class)->canView($actor, $instance), 404);
            } else {
                abort_unless((int) $intake->actor_user_id === $user, 404);
            }
            app(EmailAuthorization::class)->workflow($org, $workflow, $message->connection_id, 'email.receive', '', $message->sender_user_id);
            $payload = $this->vault->decrypt($message, 'email-message:'.$message->id);
            $text = ($payload['subject'] ?? '')."\n".($payload['text'] ?? '');
            $category = 'email_body';
        } elseif ($kind === 'slack') {
            $activation = DB::table('slack_trigger_activations')->where('id', $id)->where('status', 'done')->first();
            abort_unless($activation && $activation->workflow_instance_id, 404);
            $instance = WorkflowInstance::where('organization_id', $org)->where('workflow_id', $workflow)->findOrFail($activation->workflow_instance_id);
            abort_unless(app(WorkflowInstanceVisibility::class)->canView($actor, $instance), 404);
            $subscription = DB::table('slack_trigger_subscriptions')->where('id', $activation->subscription_id)->where('organization_id', $org)->where('workflow_id', $workflow)->first();
            $event = $subscription ? DB::table('slack_inbound_events')->where('id', $activation->event_id)->where('connection_id', $subscription->connection_id)->first() : null;
            abort_unless($subscription && $event, 404);
            $payload = $this->vault->decrypt($event, $event->connection_id.':event:'.$event->id);
            app(SlackAuthorization::class)->workflow($org, $workflow, $subscription->connection_id, 'slack.message', $subscription->destination, $payload['actor_user_id']);
            abort_unless(DB::table('slack_identity_links')->where('connection_id', $subscription->connection_id)->where('user_id', $payload['actor_user_id'])->where('slack_user_id', $payload['sender_id'])->where('confirmed', true)->exists(), 403);
            $text = $payload['text'];
            $category = 'slack_message';
        } else {
            abort(422);
        }
        abort_unless(is_string($text) && strlen($text) <= 100000 && mb_check_encoding($text, 'UTF-8') && trim($text) !== '', 422);

        return ['text' => $text, 'category' => $category, 'instance_id' => $instance?->id, 'fingerprint' => hash_hmac('sha256', $kind.':'.$id.':'.$text, (string) config('app.key'))];
    }
}
