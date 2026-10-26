<?php

namespace App\Http\Resources;

use App\Models\WorkflowTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkflowTask
 */
class WorkflowTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $instance = $this->relationLoaded('instance') ? $this->instance : null;
        $version = $instance?->relationLoaded('version') ? $instance->version : null;

        return [
            'uuid' => $this->uuid,
            'task_type' => $this->task_type->value,
            'status' => $this->status->value,
            'decision' => $this->decision?->value,
            'node_id' => $this->node_id,
            'assigned_role' => $this->assigned_role,
            'assigned_at' => $this->assigned_at,
            'completed_at' => $this->completed_at,
            'instance' => $instance ? [
                'uuid' => $instance->uuid,
                'status' => $instance->status->value,
                'current_node_id' => $instance->current_node_id,
                'submitted_data' => $instance->submitted_data,
                'workflow' => $instance->relationLoaded('workflow') && $instance->workflow ? [
                    'uuid' => $instance->workflow->uuid,
                    'name' => $instance->workflow->name,
                ] : null,
                'workflow_version' => $version ? [
                    'uuid' => $version->uuid,
                    'version_number' => $version->version_number,
                ] : null,
                'form_definition' => $version?->form_definition,
            ] : null,
        ];
    }
}
