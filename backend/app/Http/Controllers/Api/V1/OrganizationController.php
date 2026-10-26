<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Organizations\CreateOrganization;
use App\Actions\Organizations\SwitchOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\StoreOrganizationRequest;
use App\Http\Resources\OrganizationSummaryResource;
use App\Http\Resources\UserResource;
use App\Support\ActiveMembershipLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrganizationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $memberships = $request->user()
            ->activeMemberships()
            ->with('organization')
            ->orderBy('created_at')
            ->get();

        return OrganizationSummaryResource::collection($memberships);
    }

    public function store(StoreOrganizationRequest $request, CreateOrganization $create): JsonResponse
    {
        $slug = $request->filled('slug') ? $request->string('slug')->toString() : null;
        $membership = $create($request->user(), $request->string('name')->toString(), $slug);

        return (new OrganizationSummaryResource($membership))
            ->response()
            ->setStatusCode(201);
    }

    public function switch(
        Request $request,
        ActiveMembershipLocator $locator,
        SwitchOrganization $switch,
    ): UserResource {
        $organization = $locator->organizationFrom($request);
        $this->authorize('view', $organization);
        $switch($request->user(), $organization);

        return new UserResource($request->user()->fresh());
    }
}
