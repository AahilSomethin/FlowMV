<?php

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateOrganization
{
    public function __invoke(User $user, string $name, ?string $slug): OrganizationMembership
    {
        try {
            return DB::transaction(function () use ($user, $name, $slug) {
                $organization = Organization::query()->create([
                    'name' => $name,
                    'slug' => $this->slug($name, $slug),
                    'created_by' => $user->id,
                    'admin_count' => 1,
                ]);

                $membership = OrganizationMembership::query()->create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'role' => OrganizationRole::Admin,
                ]);

                $user->forceFill(['current_organization_id' => $organization->id])->save();

                return $membership->load('organization');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'slug' => 'That slug is already in use.',
            ]);
        }
    }

    private function slug(string $name, ?string $requested): string
    {
        $base = Str::slug($requested ?: $name);

        if ($base === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $base) !== 1) {
            throw ValidationException::withMessages([
                'slug' => 'Use letters, numbers, and single hyphens.',
            ]);
        }

        if (filled($requested)) {
            return $base;
        }

        $slug = $base;
        $suffix = 2;

        while (Organization::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;

            if ($suffix > 100) {
                return $base.'-'.Str::lower(Str::random(6));
            }
        }

        return $slug;
    }
}
