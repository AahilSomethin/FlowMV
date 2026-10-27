<?php

namespace App\Http\Resources;

use App\Enums\WorkflowInstanceStatus;
use App\Models\WorkflowInstance;
use App\Services\Workflows\Execution\NodeLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkflowInstance
 */
class WorkflowInstanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $detailed = (bool) $this->resource->getAttribute('present_timeline');
        $version = $this->relationLoaded('version') ? $this->version : null;
        $labels = NodeLabels::forVersion($version);

        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'failure_code' => $this->failure_code?->value,
            'retryable' => $this->status === WorkflowInstanceStatus::Failed && $this->failure_code?->recoverable() === true,
            'current_node_id' => $this->current_node_id,
            'current_node_label' => $version ? NodeLabels::text($labels, $this->current_node_id) : null,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'workflow' => $this->relationLoaded('workflow') && $this->workflow ? [
                'uuid' => $this->workflow->uuid,
                'name' => $this->workflow->name,
                'status' => $this->workflow->status->value,
            ] : null,
            'workflow_version' => $version ? [
                'uuid' => $version->uuid,
                'version_number' => $version->version_number,
            ] : null,
            'submitted_by' => $this->relationLoaded('submitter') && $this->submitter ? [
                'name' => $this->submitter->name,
            ] : null,
            'submitted_data' => $this->when($detailed, fn () => $this->submitted_data),
            'form_definition' => $this->when($detailed && $version !== null, fn () => $version->form_definition),
            'tasks' => $this->when($detailed && $this->relationLoaded('tasks'), fn () => $this->tasks->map(fn ($task) => [
                'uuid' => $task->uuid,
                'task_type' => $task->task_type->value,
                'status' => $task->status->value,
                'decision' => $task->decision?->value,
                'node_id' => $task->node_id,
                'node_label' => NodeLabels::text($labels, $task->node_id),
                'assigned_role' => $task->assigned_role,
                'assigned_at' => $task->assigned_at,
                'completed_at' => $task->completed_at,
                'decision_snapshot' => $task->relationLoaded('decisionSnapshot') && $task->decisionSnapshot
                    ? $task->decisionSnapshot->decisionPayload()
                    : null,
            ])->values()),
            'events' => $this->when($detailed && $this->relationLoaded('events'), fn () => $this->events->map(fn ($event) => [
                'uuid' => $event->uuid,
                'event_type' => $event->event_type->value,
                'node_id' => $event->node_id,
                'node_label' => NodeLabels::text($labels, $event->node_id),
                'payload' => $event->event_payload,
                'created_at' => $event->created_at,
                'actor' => $event->relationLoaded('actor') && $event->actor ? ['name' => $event->actor->name] : null,
            ])->values()),
        ];
    }
}
