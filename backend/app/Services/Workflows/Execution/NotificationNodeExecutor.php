<?php

namespace App\Services\Workflows\Execution;

use App\Enums\NotificationChannel;
use App\Enums\NotificationOutboxStatus;
use App\Enums\WorkflowEventType;
use App\Enums\WorkflowFailureCode;
use App\Enums\WorkflowTaskStatus;
use App\Jobs\ProcessNotificationOutbox;
use App\Models\NotificationOutbox;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Models\WorkflowVersion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Queues durable per-recipient deliveries inside the engine transaction.
 * Delivery failures after queueing never rewind the instance.
 */
class NotificationNodeExecutor
{
    public const MAX_ATTEMPTS = 8;

    public function __construct(private readonly WorkflowEventWriter $events) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public function execute(
        WorkflowInstance $instance,
        WorkflowVersion $version,
        User $actor,
        array $node,
        string $nodeId,
        WorkflowEngine $engine,
        PublishedGraph $graph,
    ): ?string {
        $instance->loadMissing(['workflow', 'organization', 'submitter']);
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        $channels = array_values(array_filter($data['channels'] ?? [], is_string(...)));
        $recipients = $this->recipients($instance, $data);

        if ($channels === [] || $recipients === []) {
            $engine->fail($instance, $actor, WorkflowFailureCode::NoNotificationRecipients, $nodeId);

            return null;
        }

        $payload = $this->payload($instance, $version, $graph, $data, $nodeId);
        $queued = 0;

        foreach ($recipients as $userId) {
            foreach ($channels as $channel) {
                if (NotificationChannel::tryFrom($channel) === null) {
                    $engine->fail($instance, $actor, WorkflowFailureCode::InvalidGraph, $nodeId);

                    return null;
                }

                $deliveryKey = $channel.':'.$userId;
                $idempotencyKey = hash('sha256', implode('|', [
                    $instance->id,
                    $nodeId,
                    $deliveryKey,
                ]));

                try {
                    NotificationOutbox::query()->create([
                        'organization_id' => $instance->organization_id,
                        'workflow_instance_id' => $instance->id,
                        'node_id' => $nodeId,
                        'recipient_user_id' => $userId,
                        'channel' => $channel,
                        'delivery_key' => $deliveryKey,
                        'idempotency_key' => $idempotencyKey,
                        'status' => NotificationOutboxStatus::Pending,
                        'attempt_count' => 0,
                        'max_attempts' => self::MAX_ATTEMPTS,
                        'available_at' => now(),
                        'payload' => $payload,
                    ]);
                    $queued++;
                } catch (UniqueConstraintViolationException) {
                    // Idempotent replay of the same engine visit.
                }
            }
        }

        $this->events->record($instance, $actor, WorkflowEventType::NotificationQueued, $nodeId, [
            'recipient_count' => count($recipients),
            'channel_count' => count($channels),
            'queued_count' => $queued,
        ]);

        DB::afterCommit(function () use ($instance, $nodeId): void {
            ProcessNotificationOutbox::dispatch($instance->id, $nodeId);
        });

        $target = $graph->successor($nodeId, 'out');

        if ($target === null || $graph->node($target) === null) {
            $engine->fail($instance, $actor, WorkflowFailureCode::InvalidGraph, $nodeId);

            return null;
        }

        return $target;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function recipients(WorkflowInstance $instance, array $data): array
    {
        $ids = [];
        $roles = array_values(array_filter($data['recipient_roles'] ?? [], is_string(...)));

        if ($roles !== []) {
            $fromRoles = OrganizationMembership::query()
                ->where('organization_id', $instance->organization_id)
                ->whereNull('revoked_at')
                ->whereIn('role', $roles)
                ->pluck('user_id')
                ->all();

            foreach ($fromRoles as $userId) {
                $ids[(int) $userId] = (int) $userId;
            }
        }

        if (($data['include_submitter'] ?? false) === true && $instance->submitted_by !== null) {
            $active = OrganizationMembership::query()
                ->where('organization_id', $instance->organization_id)
                ->where('user_id', $instance->submitted_by)
                ->whereNull('revoked_at')
                ->exists();

            if ($active) {
                $ids[(int) $instance->submitted_by] = (int) $instance->submitted_by;
            }
        }

        if (($data['include_task_participants'] ?? false) === true) {
            $participants = WorkflowTask::query()
                ->where('workflow_instance_id', $instance->id)
                ->where('status', WorkflowTaskStatus::Completed)
                ->whereNotNull('completed_by')
                ->pluck('completed_by')
                ->all();

            foreach ($participants as $userId) {
                $active = OrganizationMembership::query()
                    ->where('organization_id', $instance->organization_id)
                    ->where('user_id', $userId)
                    ->whereNull('revoked_at')
                    ->exists();

                if ($active) {
                    $ids[(int) $userId] = (int) $userId;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(
        WorkflowInstance $instance,
        WorkflowVersion $version,
        PublishedGraph $graph,
        array $data,
        string $nodeId,
    ): array {
        $node = $graph->node($nodeId) ?? [];
        $label = is_array($node['data'] ?? null) ? (string) ($node['data']['label'] ?? '') : '';
        $fields = [];
        $allowed = array_values(array_filter($data['allowed_field_ids'] ?? [], is_string(...)));
        $submitted = is_array($instance->submitted_data) ? $instance->submitted_data : [];
        $fieldMeta = [];

        foreach ($graph->fields() as $field) {
            if (is_string($field['id'] ?? null)) {
                $fieldMeta[$field['id']] = $field;
            }
        }

        foreach ($allowed as $fieldId) {
            $meta = $fieldMeta[$fieldId] ?? null;

            if ($meta === null || ($meta['type'] ?? null) === 'file') {
                continue;
            }

            if (! array_key_exists($fieldId, $submitted)) {
                continue;
            }

            $value = $submitted[$fieldId];

            if (is_scalar($value) || $value === null) {
                $fields[$fieldId] = $value;
            }
        }

        $tokens = [
            'workflow.name' => (string) ($instance->workflow?->name ?? 'Workflow'),
            'workflow.uuid' => (string) ($instance->workflow?->uuid ?? ''),
            'instance.uuid' => $instance->uuid,
            'instance.status' => $instance->status->value,
            'node.label' => $label,
            'organization.name' => (string) ($instance->organization?->name ?? ''),
            'submitter.name' => (string) ($instance->submitter?->name ?? ''),
        ];

        foreach ($fields as $fieldId => $value) {
            $tokens['field.'.$fieldId] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return [
            'subject' => $this->render((string) ($data['subject'] ?? 'Workflow update'), $tokens),
            'body' => $this->render((string) ($data['body'] ?? ''), $tokens),
            'workflow_uuid' => (string) ($instance->workflow?->uuid ?? ''),
            'instance_uuid' => $instance->uuid,
            'organization_uuid' => (string) ($instance->organization?->uuid ?? ''),
            'node_id' => $nodeId,
            'version_number' => $version->version_number,
            'provider_message_id' => (string) Str::uuid(),
        ];
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private function render(string $template, array $tokens): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function (array $matches) use ($tokens) {
            $key = strtolower($matches[1]);

            return array_key_exists($key, $tokens) ? $tokens[$key] : '';
        }, $template);
    }
}
