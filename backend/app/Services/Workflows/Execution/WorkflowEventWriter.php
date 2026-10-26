<?php

namespace App\Services\Workflows\Execution;

use App\Enums\WorkflowEventType;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Models\WorkflowInstance;

class WorkflowEventWriter
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        WorkflowInstance $instance,
        ?User $actor,
        WorkflowEventType $type,
        ?string $nodeId,
        array $payload = [],
    ): WorkflowEvent {
        return WorkflowEvent::query()->create([
            'organization_id' => $instance->organization_id,
            'workflow_instance_id' => $instance->id,
            'actor_id' => $actor?->id,
            'event_type' => $type,
            'node_id' => $nodeId,
            'event_payload' => $payload,
        ]);
    }
}
