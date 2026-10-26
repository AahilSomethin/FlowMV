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
        $memberships = $this->activeMemberships()->with('organization')->orderBy('created_at')->get();
        $organizations = [];
        $current = null;

        foreach ($memberships as $membership) {
            $summary = [
                'uuid' => $membership->organization->uuid,
                'name' => $membership->organization->name,
                'slug' => $membership->organization->slug,
                'role' => $membership->role->value,
                'created_at' => $membership->organization->created_at,
            ];
            $organizations[] = $summary;

            if ($membership->organization_id === $this->current_organization_id) {
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
