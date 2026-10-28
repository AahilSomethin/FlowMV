<?php

use App\Http\Middleware\ProtectIntegrationRequests;
use App\Http\Middleware\RedactInvitationToken;
use App\Http\Middleware\RequireStatefulFrontend;
use App\Http\Middleware\SyncCurrentOrganization;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->throttleApi();
        $middleware->api(prepend: [
            ProtectIntegrationRequests::class,
            RedactInvitationToken::class,
        ]);
        $middleware->prependToPriorityList(
            HandlePrecognitiveRequests::class,
            RedactInvitationToken::class,
        );
        $middleware->alias([
            'stateful.frontend' => RequireStatefulFrontend::class,
            'sync.organization' => SyncCurrentOrganization::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReportWhen(fn (Throwable $exception) => request()->is('api/v1/*connections*', 'api/v1/*integration*'));
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->is('api/v1/*connections*', 'api/v1/*integration*')) {
                $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : ($exception instanceof ValidationException ? 422 : ($exception instanceof AuthenticationException ? 401 : 503));
                $messages = [401 => 'Sign in to continue.', 403 => 'Integration permission denied.', 404 => 'Not found.', 409 => 'Connection cannot perform this operation.', 419 => 'Secure session expired.', 422 => 'Check the connection fields and provider schema.', 428 => 'Confirm your password to continue.', 429 => 'Too many requests. Try again shortly.'];

                return response()->json(['message' => $messages[$status] ?? 'Integration unavailable.'], $status)->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
            }
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Not found.'], 404);
            }
        });
    })->create();
