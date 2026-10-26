<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireStatefulFrontend
{
    /**
     * Reject auth mutations Sanctum did not run through the frontend session pipeline.
     *
     * EnsureFrontendRequestsAreStateful sets the "sanctum" request attribute only
     * after it selects the session and CSRF middleware. Configuration alone is not enough.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('sanctum') !== true) {
            abort(419, 'CSRF token mismatch.');
        }

        return $next($request);
    }
}
