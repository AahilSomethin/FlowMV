<?php

namespace App\Services\Workflows;

use App\Models\User;
use App\Models\Workflow;
use App\Services\Integrations\DocumentTemplates;
use App\Services\Integrations\EmailAuthorization;
use App\Services\Integrations\EmailNodes;
use App\Services\Integrations\SlackAuthorization;
use App\Services\Integrations\SmsAuthorization;
use App\Services\Integrations\TelegramAuthorization;
use App\Services\Workflows\Execution\ConditionEvaluator;
use App\Services\Workflows\Execution\FormSubmissionValidator;
use App\Services\Workflows\Execution\MessageFilter;
use App\Services\Workflows\Execution\PublishedGraph;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Hypothetical traversal: no persistence, vault, jobs, transport or business decisions. */
class WorkflowPreview
{
    public function __construct(private WorkflowDefinitionNormalizer $normalizer, private WorkflowDefinitionValidator $validator, private ConditionEvaluator $conditions, private FormSubmissionValidator $forms, private MessageFilter $filter) {}

    public function run(User $user, Workflow $workflow, array $definition, array $inputs, array $scenario): array
    {
        $at = hrtime(true);
        abort_unless(strlen(json_encode([$definition, $inputs, $scenario], JSON_THROW_ON_ERROR)) <= WorkflowDefinitionNormalizer::MAX_BYTES, 413);
        $v = Validator::make($scenario, ['decisions' => 'sometimes|array|max:80', 'decisions.*' => 'required|in:approved,rejected,complete,resume,return', 'event' => 'sometimes|array:command,text,chat_type,event_type,sender,subject,recipients,channel_id,sender_id,message_id', 'event.command' => 'sometimes|string|max:100', 'event.text' => 'sometimes|string|max:2000', 'event.chat_type' => 'sometimes|in:private,group,supergroup', 'event.event_type' => 'sometimes|string|max:40', 'event.sender' => 'sometimes|string|max:254', 'event.subject' => 'sometimes|string|max:200', 'event.recipients' => 'sometimes|array|max:5', 'event.recipients.*' => 'string|max:254', 'event.channel_id' => 'sometimes|string|max:40', 'event.sender_id' => 'sometimes|string|max:40', 'event.message_id' => 'sometimes|string|max:80', 'attachment_count' => 'sometimes|integer|min:0|max:5', 'attachments_blocked' => 'sometimes|boolean', 'document_values' => 'sometimes|array|max:20', 'document_values.*' => 'nullable|string|max:2000']);
        abort_if($v->fails() || array_diff(array_keys($scenario), ['decisions', 'event', 'attachment_count', 'attachments_blocked', 'document_values']) !== [], 422);
        $n = $this->normalizer->normalize($definition['graph_definition'] ?? null, $definition['form_definition'] ?? null);
        $findings = $n->ok() ? $this->validator->validate($n->graph, $n->form) : $n->violations;
        $base = ['mode' => 'hypothetical', 'definition_hash' => $n->hash, 'persisted' => false, 'external_delivery' => false, 'trace' => [], 'skipped' => [], 'findings' => $findings, 'result' => 'invalid'];
        if ($findings !== []) {
            return $base;
        }
        $graph = PublishedGraph::fromArrays($n->graph, $n->form);
        if ($graph->requiresUpload()) {
            $base['result'] = 'unavailable';
            $base['skipped'] = array_column($n->graph['nodes'], 'id');
            $base['findings'][] = ['code' => 'preview_upload_unavailable', 'message' => 'File uploads are unavailable in editor preview. Use the guarded document demo for real evidence processing.'];

            return $base;
        }
        $fields = array_column($n->form['fields'], null, 'id');
        $event = $scenario['event'] ?? [];
        $start = $graph->node($graph->startId() ?? '');
        foreach ($start['data']['input_mapping'] ?? [] as $target => $source) {
            if (! array_key_exists($target, $inputs)) {
                $mapped = str_starts_with($source, 'document.') ? ($scenario['document_values'][substr($source, 9)] ?? null) : ($event[$source] ?? null);
                if ($mapped !== null) {
                    $inputs[$target] = $mapped;
                }
            }
        }
        try {
            $data = $this->forms->validate($fields, $inputs);
        } catch (ValidationException $error) {
            $base['skipped'] = array_column($n->graph['nodes'], 'id');
            foreach ($error->errors() as $field => $messages) {
                $label = $fields[$field]['label'] ?? 'Sample answers';
                $base['findings'][] = ['code' => 'preview_input_invalid', 'message' => $label.': '.implode(' ', $messages), 'field_id' => isset($fields[$field]) ? $field : null];
            }

            return $base;
        }
        $id = $graph->startId();
        $trace = [];
        $visits = [];
        $returns = [];
        $seen = [];
        $result = 'incomplete';
        $event = $scenario['event'] ?? [];
        while ($id !== null && count($trace) < 320 && hrtime(true) - $at < 5000000000) {
            $node = $graph->node($id);
            if (! $node) {
                $result = 'invalid';
                break;
            }
            $type = $node['type'];
            $d = $node['data'];
            $handle = 'out';
            $status = 'executed';
            $summary = 'Hypothetical step evaluated.';
            $seen[$id] = true;
            $visits[$id] = ($visits[$id] ?? 0) + 1;
            try {
                if ($type === 'condition') {
                    $handle = $this->conditions->evaluate($node, $fields, $data)['outcome'] ? 'true' : 'false';
                    $summary = 'Condition evaluated using synthetic answers.';
                } elseif (in_array($type, ['telegram_filter', 'slack_filter'], true)) {
                    $handle = $this->filter->matches($d, $event) ? 'matched' : 'unmatched';
                    $summary = 'Deterministic synthetic message rule evaluated.';
                } elseif ($type === 'email_filter') {
                    $handle = app(EmailNodes::class)->matches($d['rules'], $d['match'], $event, $scenario['attachment_count'] ?? 0) ? 'matched' : 'unmatched';
                    $summary = 'Synthetic email rules evaluated.';
                } elseif ($type === 'approval') {
                    if (isset($d['telegram_approval'])) {
                        app(TelegramAuthorization::class)->workflow($workflow->organization_id, $workflow->id, $d['telegram_approval']['connection_id'], 'telegram.approval', $d['telegram_approval']['destination'], $user->id);
                    }
                    $choice = $scenario['decisions'][$id] ?? null;
                    if (! in_array($choice, ['approved', 'rejected'], true)) {
                        $status = 'waiting';
                        $summary = 'Choose a hypothetical approval outcome. No task or decision is created.';
                    } else {
                        $handle = $choice;
                        $summary = 'Hypothetical decision only. Live reviewer eligibility and no-self-approval remain mandatory during actual execution.';
                    }
                } elseif ($type === 'revision') {
                    $returns[$id] = ($returns[$id] ?? 0) + 1;
                    if ($d['max_returns'] !== null && $returns[$id] > $d['max_returns']) {
                        $handle = 'exhausted';
                        $summary = 'Configured correction limit reached.';
                    } elseif (($scenario['decisions'][$id] ?? null) !== 'return') {
                        $status = 'waiting';
                        $summary = 'Would wait for an authenticated correction. Choose a hypothetical return to continue.';
                    } else {
                        $handle = 'return';
                        $summary = 'Synthetic correction return; supplied synthetic answers are reused.';
                    }
                } elseif ($type === 'assignment' || $type === 'delay') {
                    $expected = $type === 'assignment' ? 'complete' : 'resume';
                    if (($scenario['decisions'][$id] ?? null) !== $expected) {
                        $status = 'waiting';
                    }
                    $summary = $type === 'assignment' ? 'Would wait for authorized task completion; no task created.' : 'Configured delay only; time is not advanced and no job is scheduled.';
                } elseif ($type === 'attachment_inspector') {
                    $handle = ($scenario['attachments_blocked'] ?? false) ? 'blocked' : ($d['require_attachments'] && ($scenario['attachment_count'] ?? 0) === 0 ? 'missing' : 'inspected');
                    $summary = 'Synthetic attachment scenario. No file was scanned, opened or downloaded.';
                } elseif ($type === 'document_validation') {
                    $values = $scenario['document_values'] ?? [];
                    foreach ($d['field_map'] as $source => $target) {
                        if (isset($data[$target])) {
                            $values[$source] = $data[$target];
                        }
                    }
                    $documents = array_fill(0, $scenario['attachment_count'] ?? 0, 'synthetic-document');
                    $validation = app(DocumentTemplates::class)->validate($d['document_fields'], $values, $documents);
                    $handle = match ($validation['status']) {
                        'complete' => 'complete', 'missing_information' => 'missing', 'invalid_information' => 'invalid', default => 'unknown'
                    };
                    if ($d['require_document'] && (! $documents || ($scenario['attachments_blocked'] ?? false))) {
                        $handle = 'unknown';
                    }
                    $summary = 'Deterministic document fields evaluated against synthetic values; extraction/scanning not verified.';
                } elseif (str_starts_with($type, 'telegram_') || str_starts_with($type, 'slack_') || str_starts_with($type, 'sms_') || in_array($type, ['email_trigger', 'email_send', 'email_reply'], true)) {
                    $this->authorizeProvider($user, $workflow, $node);
                    $summary = str_ends_with($type, '_trigger') ? 'Synthetic event only; this does not verify the sender or activate a subscription.' : 'Would queue an authorized external action. Nothing is sent; delivery is not verified.';
                    if ($type === 'email_trigger' && ! app(EmailNodes::class)->matches($d['rules'], $d['match'], $event, $scenario['attachment_count'] ?? 0)) {
                        $status = 'blocked';
                        $summary = 'The synthetic email does not match this trigger. No workflow would be activated.';
                    }
                } elseif ($type === 'notification') {
                    $summary = 'Would queue internal notifications. No recipients are contacted.';
                } elseif ($type === 'integration_action') {
                    $status = 'unavailable';
                    $summary = 'This generic connector has no audited pure preview adapter.';
                } elseif ($type === 'end') {
                    $result = $d['result'];
                    $summary = 'Hypothetical '.$result.' outcome.';
                } elseif ($type !== 'start') {
                    $status = 'unavailable';
                    $summary = 'No pure preview adapter is available for this step.';
                }
            } catch (\Throwable) {
                $status = 'blocked';
                $summary = 'This step needs current connection approval, exact workflow grants or valid configuration. Review setup; no external operation was attempted.';
            }
            $trace[] = ['node_id' => $id, 'type' => $type, 'visit' => $visits[$id], 'status' => $status, 'branch' => $status === 'executed' && $type !== 'end' ? $handle : null, 'summary' => $summary];
            if ($status !== 'executed') {
                $result = $status;
                break;
            }
            if ($type === 'end') {
                break;
            }
            $id = $graph->successor($id, $handle);
        }
        $base['trace'] = $trace;
        $base['skipped'] = array_values(array_diff(array_column($n->graph['nodes'], 'id'), array_keys($seen)));
        $base['result'] = $result;
        $base['input_summary'] = array_map(fn ($f) => ['field_id' => $f['id'], 'type' => $f['type'], 'provided' => array_key_exists($f['id'], $inputs)], $n->form['fields']);
        if ($result === 'incomplete') {
            $base['findings'][] = ['code' => 'preview_limit', 'message' => 'The preview stopped at a traversal/time limit or a missing route. No completion is implied.'];
        }

        return $base;
    }

    private function authorizeProvider(User $user, Workflow $workflow, array $node): void
    {
        $d = $node['data'];
        $type = $node['type'];
        $org = $workflow->organization_id;
        if (str_starts_with($type, 'telegram_')) {
            $op = match ($type) {
                'telegram_trigger' => 'telegram.message', 'telegram_notification' => 'telegram.notification', default => 'telegram.send_message'
            };
            app(TelegramAuthorization::class)->workflow($org, $workflow->id, $d['connection_id'], $op, $d['destination'], $user->id);
        } elseif (str_starts_with($type, 'slack_')) {
            $op = match ($type) {
                'slack_trigger' => 'slack.message', 'slack_notification' => 'slack.notification', default => 'slack.send_message'
            };
            app(SlackAuthorization::class)->workflow($org, $workflow->id, $d['connection_id'], $op, $d['destination'], $user->id);
        } elseif (str_starts_with($type, 'sms_')) {
            app(SmsAuthorization::class)->workflow($org, $workflow->id, $d['connection_id'], $type === 'sms_notification' ? 'sms.notification' : 'sms.send_message', $d, $user->id);
        } else {
            $op = match ($type) {
                'email_trigger' => 'email.receive', 'email_reply' => 'email.reply', default => 'email.send'
            };
            app(EmailAuthorization::class)->workflow($org, $workflow->id, $d['connection_id'], $op, $type === 'email_trigger' ? '' : $d['destination'], $user->id);
        }
    }
}
