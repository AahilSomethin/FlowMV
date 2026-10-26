<?php

namespace App\Http\Resources;

use App\Models\WorkflowVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkflowVersion
 */
class WorkflowVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $detailed = (bool) $this->resource->getAttribute('present_definition');

        return [
            'uuid' => $this->uuid,
            'version_number' => $this->version_number,
            'published_at' => $this->published_at,
            'published_by' => $this->relationLoaded('publisher') && $this->publisher
                ? ['name' => $this->publisher->name]
                : null,
            'graph_definition' => $this->when($detailed, fn () => $this->graph_definition),
            'form_definition' => $this->when($detailed, fn () => $this->form_definition),
        ];
    }
}
