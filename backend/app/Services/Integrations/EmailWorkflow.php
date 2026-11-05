<?php

namespace App\Services\Integrations;

use App\Actions\WorkflowInstances\StartWorkflowInstance;
use App\Enums\WorkflowEventType;
use App\Jobs\ProcessEmailDelivery;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowVersion;
use App\Services\Workflows\Execution\PublishedGraph;
use App\Services\Workflows\Execution\WorkflowEventWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmailWorkflow
{
    public function __construct(private EmailAuthorization $authorization, private CredentialVault $vault, private EmailNodes $nodes, private DocumentTemplates $templates, private DocumentPipeline $documents, private ConnectionManager $audit, private WorkflowEventWriter $events) {}

    public function publication(int $org, int $workflow, array $node): void
    {
        $type = $node['type'];
        $d = $node['data'];
        if (in_array($type, ['email_trigger', 'email_send', 'email_reply'], true)) {
            $this->authorization->workflow($org, $workflow, $d['connection_id'], $type === 'email_trigger' ? 'email.receive' : ($type === 'email_reply' ? 'email.reply' : 'email.send'), $d['destination'] ?? '');
        }
        if ($type === 'document_validation') {
            $template = DB::table('document_templates')->where('organization_id', $org)->where('id', $d['template_id'])->first();
            abort_unless($template && $this->templates->schema(json_decode($template->fields, true)) === $d['document_fields'], 422);
        }
    }

    public function subscribe(User $user, Workflow $workflow, WorkflowVersion $version): void
    {
        DB::table('email_trigger_bindings')->where('workflow_id', $workflow->id)->where('status', 'active')->update(['status' => 'paused', 'updated_at' => now()]);
        foreach ($version->graph_definition['nodes'] as $node) {
            if ($node['type'] !== 'email_trigger') {
                continue;
            }
            DB::table('email_trigger_bindings')->insert(['id' => (string) Str::uuid(), 'organization_id' => $workflow->organization_id, 'connection_id' => $node['data']['connection_id'], 'workflow_id' => $workflow->id, 'workflow_version_id' => $version->id, 'node_id' => $node['id'], 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->audit($workflow->organization_id, $node['data']['connection_id'], $user->id, 'email.trigger.subscribed');
        }
    }

    public function ingest(string $messageId): void
    {
        DB::transaction(function () use ($messageId) {
            $message = DB::table('email_messages')->where('id', $messageId)->lockForUpdate()->first();
            if (! $message || ! $message->sender_user_id || ! Carbon::parse($message->expires_at)->isFuture() || DB::table('email_attachment_fetches')->where('message_id', $messageId)->whereIn('status', ['pending', 'processing'])->exists()) {
                return;
            }
            $payload = $this->vault->decrypt($message, 'email-message:'.$messageId);
            $documents = DB::table('private_documents')->where('message_id', $messageId)->where('organization_id', $message->organization_id)->get();
            $bindings = DB::table('email_trigger_bindings')->where('connection_id', $message->connection_id)->where('status', 'active')->orderBy('created_at')->limit(50)->get();
            foreach ($bindings as $binding) {
                if (Carbon::parse($message->received_at)->lt(Carbon::parse($binding->created_at)) || DB::table('email_intakes')->where('message_id', $messageId)->where('binding_id', $binding->id)->exists()) {
                    continue;
                }
                try {
                    $this->authorization->workflow($message->organization_id, $binding->workflow_id, $message->connection_id, 'email.receive', '', $message->sender_user_id);
                } catch (\Throwable) {
                    continue;
                }
                $version = WorkflowVersion::findOrFail($binding->workflow_version_id);
                $node = PublishedGraph::fromVersion($version)->node($binding->node_id);
                abort_unless($node && $node['type'] === 'email_trigger' && in_array($version->node_registry_version, [3, 4], true), 403);
                $d = $node['data'];
                if (! $this->nodes->matches($d['rules'], $d['match'], $payload, $documents->count())) {
                    continue;
                }
                $text = '';
                $docIds = [];
                $blocked = false;
                foreach ($documents as $document) {
                    $docIds[] = $document->id;
                    if ($document->status !== 'clean') {
                        $blocked = true;

                        continue;
                    }
                    $text .= mb_strcut($this->documents->extracted($document)['text'], 0, max(0, 100000 - strlen($text)))."\n";
                }
                $text = mb_strcut($text, 0, 100000);
                $validation = collect($version->graph_definition['nodes'])->firstWhere('type', 'document_validation');
                $extracted = $validation ? $this->templates->extract($validation['data']['document_fields'], $text) : ['values' => [], 'uncertain' => []];
                $diagnostic = $validation ? $this->templates->validate($validation['data']['document_fields'], $extracted['values'], $docIds, $extracted['uncertain']) : ['values' => [], 'missing' => [], 'invalid' => [], 'uncertain' => [], 'status' => 'complete'];
                $inputs = [];
                foreach ($d['input_mapping'] as $field => $source) {
                    $value = str_starts_with($source, 'document.') ? ($diagnostic['values'][substr($source, 9)] ?? null) : ($payload[$source] ?? null);
                    if ($value !== null) {
                        $inputs[$field] = $value;
                    }
                }
                $id = (string) Str::uuid();
                $context = ['inputs' => $inputs, 'document_ids' => $docIds, 'raw_fields' => $extracted['values'], 'uncertain' => $extracted['uncertain'], 'initial_validation' => $diagnostic, 'attachment_blocked' => $blocked || ! empty($payload['attachment_issue']), 'last_validation' => null];
                DB::table('email_intakes')->insert(['id' => $id, 'message_id' => $messageId, 'binding_id' => $binding->id, 'organization_id' => $message->organization_id, 'actor_user_id' => $message->sender_user_id, 'status' => 'awaiting_confirmation', ...$this->vault->encrypt($context, 'email-intake:'.$id), 'created_at' => now(), 'updated_at' => now()]);
                $this->audit->audit($message->organization_id, $message->connection_id, null, 'email.intake.awaiting_confirmation');
            }
            DB::table('email_messages')->where('id', $messageId)->update(['status' => 'processed']);
        });
    }

    public function confirm(User $user, int $org, string $id, array $corrections = []): WorkflowInstance
    {
        return DB::transaction(function () use ($user, $org, $id, $corrections) {
            app(ConnectionAccess::class)->role($user->id, $org);
            $intake = DB::table('email_intakes')->where('organization_id', $org)->where('actor_user_id', $user->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($intake, 404);
            if ($intake->workflow_instance_id !== null) {
                return WorkflowInstance::findOrFail($intake->workflow_instance_id);
            }
            abort_unless($intake->status === 'awaiting_confirmation' && $user->email_verified_at !== null, 403);
            $message = DB::table('email_messages')->where('id', $intake->message_id)->first();
            $binding = DB::table('email_trigger_bindings')->where('id', $intake->binding_id)->first();
            abort_unless($message && $binding && $binding->status === 'active' && Carbon::parse($message->expires_at)->isFuture(), 403);
            $this->authorization->workflow($org, $binding->workflow_id, $message->connection_id, 'email.receive', '', $user->id);
            $email = $this->vault->decrypt($message, 'email-message:'.$message->id);
            abort_unless(strtolower($user->email) === $email['sender'], 403);
            $context = $this->vault->decrypt($intake, 'email-intake:'.$id);
            $inputs = array_replace($context['inputs'], $corrections);
            DB::table('email_intakes')->where('id', $id)->update(['status' => 'processing', 'updated_at' => now()]);
            $version = WorkflowVersion::findOrFail($binding->workflow_version_id);
            $instance = app(StartWorkflowInstance::class)($user, Organization::findOrFail($org), Workflow::findOrFail($binding->workflow_id), $inputs, $version, null, $id);
            foreach ($context['document_ids'] as $documentId) {
                abort_unless(DB::table('private_documents')->where('organization_id', $org)->where('message_id', $message->id)->where('id', $documentId)->exists(), 403);
                DB::table('workflow_document_evidence')->insertOrIgnore(['organization_id' => $org, 'workflow_instance_id' => $instance->id, 'document_id' => $documentId]);
            }
            DB::table('email_intakes')->where('id', $id)->update(['status' => 'activated', 'workflow_instance_id' => $instance->id, 'updated_at' => now()]);
            $this->events->record($instance, $user, WorkflowEventType::EmailConfirmed, $binding->node_id, ['intake_uuid' => $id]);
            $this->audit->audit($org, $message->connection_id, $user->id, 'email.intake.confirmed');

            return $instance;
        });
    }

    public function context(WorkflowInstance $instance): array
    {
        $intake = DB::table('email_intakes')->where('workflow_instance_id', $instance->id)->where('organization_id', $instance->organization_id)->first();
        abort_unless($intake, 403);
        $message = DB::table('email_messages')->where('id', $intake->message_id)->first();
        abort_unless($message, 403);
        $binding = DB::table('email_trigger_bindings')->where('id', $intake->binding_id)->first();
        abort_unless($binding && $binding->workflow_version_id === $instance->workflow_version_id, 403);
        $this->authorization->workflow($instance->organization_id, $instance->workflow_id, $message->connection_id, 'email.receive', '', $instance->submitted_by);

        return [$intake, $this->vault->decrypt($intake, 'email-intake:'.$intake->id), $message, $this->vault->decrypt($message, 'email-message:'.$message->id)];
    }

    public function execute(WorkflowInstance $instance, WorkflowVersion $version, User $actor, array $node): string
    {
        abort_unless($version->id === $instance->workflow_version_id && in_array($version->node_registry_version, [3, 4], true) && PublishedGraph::fromVersion($version)->node($node['id']) === $node, 403);
        $type = $node['type'];
        $d = $node['data'];
        if (in_array($type, ['email_send', 'email_reply'], true)) {
            $this->enqueue($instance, $version, $actor, $node);

            return 'out';
        }
        [$intake,$context,$message,$payload] = $this->context($instance);
        $docs = DB::table('private_documents')->where('organization_id', $instance->organization_id)->whereIn('id', $context['document_ids'])->get();
        if ($type === 'email_filter') {
            $match = $this->nodes->matches($d['rules'], $d['match'], $payload, $docs->count());
            $this->events->record($instance, $actor, WorkflowEventType::EmailFilterEvaluated, $node['id'], ['matched' => $match]);

            return $match ? 'matched' : 'unmatched';
        }
        if ($type === 'attachment_inspector') {
            $handle = $context['attachment_blocked'] || $docs->contains(fn ($doc) => $doc->status !== 'clean' || Carbon::parse($doc->expires_at)->isPast()) ? 'blocked' : ($d['require_attachments'] && $docs->isEmpty() ? 'missing' : 'inspected');
            $this->events->record($instance, $actor, WorkflowEventType::AttachmentsInspected, $node['id'], ['status' => $handle, 'count' => $docs->count()]);

            return $handle;
        }
        $values = [];
        $uncertain = [];
        foreach ($d['field_map'] as $templateField => $formField) {
            $value = $instance->submitted_data[$formField] ?? null;
            if ($value !== null && $value !== '') {
                $values[$templateField] = $value;
            } elseif (array_key_exists($templateField, $context['raw_fields'])) {
                $values[$templateField] = $context['raw_fields'][$templateField];
            }
            if (in_array($templateField, $context['uncertain'], true) && ($value === null || $value === '')) {
                $uncertain[] = $templateField;
            }
        }
        $result = $this->templates->validate($d['document_fields'], $values, $docs->where('status', 'clean')->pluck('id')->all(), $uncertain);
        if ($d['require_document'] && ($docs->isEmpty() || $context['attachment_blocked'] || $docs->contains(fn ($doc) => $doc->status !== 'clean' || Carbon::parse($doc->expires_at)->isPast()))) {
            $result['status'] = 'unable_to_determine';
        }
        $context['last_validation'] = $result;
        DB::table('email_intakes')->where('id', $intake->id)->update([...$this->vault->encrypt($context, 'email-intake:'.$intake->id), 'updated_at' => now()]);
        $this->events->record($instance, $actor, WorkflowEventType::DocumentValidated, $node['id'], ['status' => $result['status'], 'missing_count' => count($result['missing']), 'invalid_count' => count($result['invalid']), 'uncertain_count' => count($result['uncertain'])]);

        return match ($result['status']) {
            'complete' => 'complete','missing_information' => 'missing','invalid_information' => 'invalid',default => 'unknown'
        };
    }

    private function enqueue(WorkflowInstance $instance, WorkflowVersion $version, User $actor, array $node): void
    {
        $d = $node['data'];
        $operation = $node['type'] === 'email_reply' ? 'email.reply' : 'email.send';
        $c = $this->authorization->workflow($instance->organization_id, $instance->workflow_id, $d['connection_id'], $operation, $d['destination'], $instance->submitted_by);
        $revision = (int) $instance->revisionCounters()->sum('attempt_count');
        $hash = hash('sha256', $instance->uuid.':'.$node['id'].':'.$revision);
        if (DB::table('email_action_outbox')->where('dedupe_hash', $hash)->exists()) {
            return;
        }
        $context = null;
        $payload = null;
        if ($node['type'] === 'email_reply') {
            [, $context,,$payload] = $this->context($instance);
            abort_unless($payload['sender'] === $d['destination'] && $payload['reply_to'] === $d['destination'], 403);
            $next = PublishedGraph::fromVersion($version)->node(PublishedGraph::fromVersion($version)->successor($node['id'], 'out') ?? '');
            if (($next['type'] ?? '') === 'revision' && $revision >= ($next['data']['max_returns'] ?? 0)) {
                return;
            }
        }
        $decision = $instance->tasks()->whereNotNull('decision')->orderByDesc('id')->value('decision');
        $variables = ['request.uuid' => $instance->uuid, 'request.status' => $instance->status->value, 'request.link' => rtrim(config('flowmv.frontend_url'), '/').'/requests/'.$instance->uuid, 'approval.decision' => $decision instanceof \BackedEnum ? $decision->value : ($decision ?? 'pending'), 'validation.missing' => implode(', ', array_unique([...($context['last_validation']['missing'] ?? []), ...($context['last_validation']['invalid'] ?? [])]))];
        foreach ($d['allowed_field_ids'] as $field) {
            $value = $instance->submitted_data[$field] ?? '';
            abort_unless(is_scalar($value) || $value === null, 422);
            $variables['input.'.$field] = (string) $value;
        }
        $render = fn ($template) => preg_replace_callback('/\{\{([^}]+)\}\}/u', fn ($m) => $variables[$m[1]] ?? '', $template);
        abort_unless($this->nodes->templateValid($d['text'], $d['allowed_field_ids']), 422);
        $subject = $node['type'] === 'email_reply' ? 'Re: '.mb_substr($payload['subject'], 0, 190) : $render($d['subject']);
        $text = $render($d['text']);
        abort_if(preg_match('/[\r\n]/', $subject) || mb_strlen($subject) > 200 || mb_strlen($text) > 8000 || $text === '', 422);
        $id = (string) Str::uuid();
        $mailbox = DB::table('email_mailboxes')->where('connection_id', $c->id)->first();
        $body = ['subject' => $subject, 'text' => $text, 'reply_message_id' => $payload['internet_message_id'] ?? null, 'thread_id' => $payload['thread_id'] ?? null];
        DB::table('email_action_outbox')->insert(['id' => $id, 'connection_id' => $c->id, 'organization_id' => $instance->organization_id, 'workflow_id' => $instance->workflow_id, 'workflow_version_id' => $version->id, 'workflow_instance_id' => $instance->id, 'node_id' => $node['id'], 'operation' => $operation, 'destination' => $d['destination'], 'credential_generation' => $mailbox->credential_generation, 'dedupe_hash' => $hash, ...$this->vault->encrypt($body, 'email-action:'.$id), 'status' => 'pending', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->events->record($instance, $actor, WorkflowEventType::EmailQueued, $node['id'], ['delivery_uuid' => $id, 'connection_id' => $c->id]);
        $this->audit->audit($instance->organization_id, $c->id, $actor->id, 'email.delivery.queued');
        ProcessEmailDelivery::dispatch($id)->afterCommit();
    }
}
