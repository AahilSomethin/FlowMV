<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Organizations\InviteOrganizationMember;
use App\Actions\Organizations\RevokeInvitation;
use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\StoreInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Support\ActiveMembershipLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class InvitationController extends Controller
{
    public function index(Request $request, ActiveMembershipLocator $locator): AnonymousResourceCollection
    {
        $organization = $locator->organizationFrom($request);
        $this->authorize('viewInvitations', $organization);

        $invitations = $organization->invitations()
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderBy('created_at')
            ->get();

        return InvitationResource::collection($invitations);
    }

    public function store(
        StoreInvitationRequest $request,
        ActiveMembershipLocator $locator,
        InviteOrganizationMember $invite,
    ): JsonResponse {
        $organization = $locator->organizationFrom($request);
        $invitation = $invite(
            $request->user(),
            $organization,
            $request->string('email')->toString(),
            OrganizationRole::from($request->string('role')->toString()),
        );

        return (new InvitationResource($invitation))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(
        Request $request,
        ActiveMembershipLocator $locator,
        RevokeInvitation $revoke,
    ): Response {
        $organization = $locator->organizationFrom($request);
        $invitation = $locator->invitation($organization, (string) $request->route('invitation'));
        $this->authorize('delete', $invitation);
        $revoke($request->user(), $organization, $invitation);

        return response()->noContent();
    }
}
