<?php

namespace App\Http\Middleware;

use App\Services\Integrations\SensitiveInput;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ProtectIntegrationRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1/*connections*', 'api/v1/*integration*')) {
            return $next($request);
        }
        $private = [];
        foreach (['credentials', 'password', 'state', 'code', 'signature'] as $key) {
            if ($request->exists($key)) {
                $private[$key] = $request->input($key);
                $request->merge([$key => '[redacted]']);
            }
        }
        $request->attributes->set('integration_input', new SensitiveInput($private));
        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : ($exception instanceof ValidationException ? 422 : 503);
            $messages = [401 => 'Sign in to continue.', 403 => 'Integration permission denied.', 404 => 'Not found.', 409 => 'Connection cannot perform this operation.', 419 => 'Secure session expired.', 422 => 'Check the connection fields and provider schema.', 428 => 'Confirm your password to continue.', 429 => 'Too many requests. Try again shortly.'];
            $response = response()->json(['message' => $messages[$status] ?? 'Integration unavailable.'], $status);
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
