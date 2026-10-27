<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'current_organization_id'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function activeMemberships(): HasMany
    {
        return $this->memberships()->whereNull('revoked_at');
    }

    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function syncCurrentOrganization(): void
    {
        if ($this->current_organization_id === null) {
            return;
        }

        $active = $this->activeMemberships()
            ->where('organization_id', $this->current_organization_id)
            ->exists();

        if (! $active) {
            $this->forceFill(['current_organization_id' => null])->save();
        }
    }

    /**
     * @return array{current_organization: array<string, mixed>|null, organizations: list<array<string, mixed>>}
     */
    public function organizationState(): array
    {
        $activeOrganizations = Organization::query()
            ->join('organization_memberships', 'organization_memberships.organization_id', '=', 'organizations.id')
            ->where('organization_memberships.user_id', $this->id)
            ->whereNull('organization_memberships.revoked_at')
            ->orderBy('organization_memberships.created_at')
            ->select('organizations.*', 'organization_memberships.role as membership_role')
            ->get();
        $organizations = [];
        $current = null;

        foreach ($activeOrganizations as $organization) {
            $summary = [
                'uuid' => $organization->uuid,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'role' => $organization->membership_role,
                'created_at' => $organization->created_at,
            ];
            $organizations[] = $summary;

            if ($organization->id === $this->current_organization_id) {
                $current = $summary;
            }
        }

        if ($this->current_organization_id !== null && $current === null) {
            $this->forceFill(['current_organization_id' => null])->save();
        }

        return [
            'current_organization' => $current,
            'organizations' => $organizations,
        ];
    }
}
