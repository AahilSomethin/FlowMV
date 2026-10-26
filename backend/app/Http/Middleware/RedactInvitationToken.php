<?php

namespace App\Http\Middleware;

use App\Support\InvitationPlaintext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedactInvitationToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1/invitations/accept') && $request->isMethod('POST')) {
            $token = $request->input('token');

            if (is_string($token) && $token !== '') {
                $request->attributes->set('invitation_token', new InvitationPlaintext($token));
                $request->merge(['token' => '[redacted]']);
            }
        }

        return $next($request);
    }
}
