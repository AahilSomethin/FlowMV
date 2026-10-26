<?php

namespace App\Http\Resources;

use App\Enums\OrganizationRole;
use App\Enums\WorkflowStatus;
use App\Models\Workflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Workflow
 */
class WorkflowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->viewerRole();
        $includeDraft = $role !== OrganizationRole::Employee;
        $detailed = (bool) $this->resource->getAttribute('present_definition');
        $version = $this->currentPublishedVersion;

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'has_unpublished_changes' => $includeDraft ? $this->hasUnpublishedChanges() : false,
            'current_version_number' => $version?->version_number,
            'draft_revision' => $includeDraft ? $this->draft?->revision : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'can_update' => in_array($role, [OrganizationRole::Admin, OrganizationRole::Manager], true)
                && $this->status !== WorkflowStatus::Archived,
            'can_publish' => $role === OrganizationRole::Admin && $this->status !== WorkflowStatus::Archived,
            'can_archive' => $role === OrganizationRole::Admin && $this->status !== WorkflowStatus::Archived,
            'draft' => $this->when($detailed && $includeDraft && $this->relationLoaded('draft') && $this->draft !== null, fn () => [
                'revision' => $this->draft->revision,
                'graph_definition' => $this->draft->graph_definition,
                'form_definition' => $this->draft->form_definition,
            ]),
            'published' => $this->when($detailed && $version !== null, fn () => [
                'uuid' => $version->uuid,
                'version_number' => $version->version_number,
                'graph_definition' => $version->graph_definition,
                'form_definition' => $version->form_definition,
                'published_at' => $version->published_at,
                'published_by' => $version->relationLoaded('publisher') && $version->publisher
                    ? ['name' => $version->publisher->name]
                    : null,
            ]),
        ];
    }

    private function viewerRole(): ?OrganizationRole
    {
        $role = $this->resource->getAttribute('viewer_role');

        return $role instanceof OrganizationRole ? $role : null;
    }
}
