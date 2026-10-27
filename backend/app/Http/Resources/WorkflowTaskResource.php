<?php

namespace App\Http\Resources;

use App\Enums\WorkflowTaskType;
use App\Models\WorkflowTask;
use App\Services\Workflows\Execution\NodeLabels;
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
        $labels = NodeLabels::forVersion($version);
        $node = collect($version?->graph_definition['nodes'] ?? [])->firstWhere('id', $this->node_id);
        $config = is_array($node['data'] ?? null) ? $node['data'] : [];
        $instructions = $this->task_type === WorkflowTaskType::Assignment
            ? ($config['instructions'] ?? null)
            : ($config['description'] ?? null);

        return [
            'uuid' => $this->uuid,
            'task_type' => $this->task_type->value,
            'status' => $this->status->value,
            'decision' => $this->decision?->value,
            'node_id' => $this->node_id,
            'node_label' => NodeLabels::text($labels, $this->node_id),
            'instructions' => is_string($instructions) ? $instructions : null,
            'assigned_role' => $this->assigned_role,
            'assigned_at' => $this->assigned_at,
            'completed_at' => $this->completed_at,
            'decision_snapshot' => $this->when(
                $this->relationLoaded('decisionSnapshot'),
                fn () => $this->decisionSnapshot?->decisionPayload(),
            ),
            'instance' => $instance ? [
                'uuid' => $instance->uuid,
                'status' => $instance->status->value,
                'current_node_id' => $instance->current_node_id,
                'current_node_label' => NodeLabels::text($labels, $instance->current_node_id),
                'submitted_data' => $instance->submitted_data,
                'workflow' => $instance->relationLoaded('workflow') && $instance->workflow ? [
                    'uuid' => $instance->workflow->uuid,
                    'name' => $instance->workflow->name,
                ] : null,
                'workflow_version' => $version ? [
                    'uuid' => $version->uuid,
                    'version_number' => $version->version_number,
                ] : null,
                'submitted_by' => $instance->relationLoaded('submitter') && $instance->submitter ? [
                    'name' => $instance->submitter->name,
                ] : null,
                'form_definition' => $version?->form_definition,
            ] : null,
        ];
    }
}
