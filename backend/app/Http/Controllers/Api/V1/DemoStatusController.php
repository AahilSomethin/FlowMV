<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ActiveMembershipLocator;
use App\Support\DemoEnvironment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DemoStatusController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): JsonResponse
    {
        app(ActiveMembershipLocator::class)->organizationFrom($request);
        $enabled = false;
        if (app()->environment('demo')) {
            app(DemoEnvironment::class)->assertOwned();
            $enabled = true;
        }

        return response()->json(['data' => ['enabled' => $enabled, 'notice' => $enabled ? 'Fictional demo workspace. Internal requests and decisions are real; provider transports and document scanning are simulated. Sign out to change roles.' : null]])->header('Cache-Control', 'no-store, private');
    }
}
