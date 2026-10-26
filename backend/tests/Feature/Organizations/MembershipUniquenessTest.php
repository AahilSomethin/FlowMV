<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipUniquenessTest extends TestCase
{
    use InteractsWithOrganizations, RefreshDatabase;

    public function test_a_user_cannot_have_two_memberships_in_one_organization(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        OrganizationMembership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]);

        try {
            OrganizationMembership::factory()->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ]);
            $this->fail('A duplicate membership was stored.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, OrganizationMembership::query()
                ->where('organization_id', $organization->id)
                ->where('user_id', $user->id)
                ->count());
        }
    }
}
