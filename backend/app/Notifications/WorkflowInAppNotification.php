<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class WorkflowInAppNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly array $payload,
        private readonly string $idempotencyKey,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'subject' => (string) ($this->payload['subject'] ?? 'Workflow update'),
            'body' => (string) ($this->payload['body'] ?? ''),
            'workflow_uuid' => $this->payload['workflow_uuid'] ?? null,
            'instance_uuid' => $this->payload['instance_uuid'] ?? null,
            'organization_uuid' => $this->payload['organization_uuid'] ?? null,
            'node_id' => $this->payload['node_id'] ?? null,
            'idempotency_key' => $this->idempotencyKey,
            'provider_message_id' => $this->payload['provider_message_id'] ?? null,
        ];
    }
}
