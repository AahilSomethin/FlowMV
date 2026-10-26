<?php

namespace App\Http\Resources;

use App\Models\OrganizationMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrganizationMembership
 */
class OrganizationSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->organization->uuid,
            'name' => $this->organization->name,
            'slug' => $this->organization->slug,
            'role' => $this->role->value,
            'created_at' => $this->organization->created_at,
        ];
    }
}
