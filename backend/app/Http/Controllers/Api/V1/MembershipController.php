<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Organizations\ChangeMembershipRole;
use App\Actions\Organizations\RevokeMembership;
use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\UpdateMembershipRequest;
use App\Http\Resources\MembershipResource;
use App\Models\OrganizationMembership;
use App\Support\ActiveMembershipLocator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class MembershipController extends Controller
{
    public function index(Request $request, ActiveMembershipLocator $locator): AnonymousResourceCollection
    {
        $organization = $locator->organizationFrom($request);
        $this->authorize('view', $organization);

        $memberships = $organization->activeMemberships()
            ->with('user')
            ->get()
            ->sortBy(fn (OrganizationMembership $membership) => Str::lower($membership->user->name))
            ->values();

        return MembershipResource::collection($memberships);
    }

    public function update(
        UpdateMembershipRequest $request,
        ChangeMembershipRole $change,
        ActiveMembershipLocator $locator,
    ): MembershipResource {
        $organization = $locator->organizationFrom($request);
        $membership = $request->attributes->get('resolved_membership');

        if (! $membership instanceof OrganizationMembership) {
            $membership = $locator->membership($organization, (string) $request->route('membership'));
        }

        $updated = $change(
            $request->user(),
            $organization,
            $membership,
            OrganizationRole::from($request->string('role')->toString()),
        );

        return new MembershipResource($updated);
    }

    public function destroy(
        Request $request,
        ActiveMembershipLocator $locator,
        RevokeMembership $revoke,
    ): Response {
        $organization = $locator->organizationFrom($request);
        $membership = $locator->membership($organization, (string) $request->route('membership'));
        $this->authorize('delete', $membership);
        $revoke($request->user(), $organization, $membership);

        return response()->noContent();
    }
}
