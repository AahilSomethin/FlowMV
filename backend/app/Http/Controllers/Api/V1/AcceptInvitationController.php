<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Organizations\AcceptOrganizationInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\AcceptInvitationRequest;
use App\Http\Resources\OrganizationSummaryResource;

class AcceptInvitationController extends Controller
{
    public function __invoke(
        AcceptInvitationRequest $request,
        AcceptOrganizationInvitation $accept,
    ): OrganizationSummaryResource {
        return new OrganizationSummaryResource(
            $accept($request->user(), $request->invitationToken()),
        );
    }
}
