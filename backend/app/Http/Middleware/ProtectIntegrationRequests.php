<?php

namespace App\Http\Middleware;

use App\Services\Integrations\SensitiveInput;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ProtectIntegrationRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $preview = $request->is('api/v1/organizations/*/workflows/*/preview');
        if (! $preview && ! $request->is('api/v1/*connections*', 'api/v1/*integration*')) {
            return $next($request);
        }
        $private = [];
        if ($preview) {
            abort_if(strlen($request->getContent()) > 200000, 413);
            foreach (['graph_definition', 'form_definition', 'scenario'] as $key) {
                if ($request->exists($key)) {
                    $private[$key] = $request->input($key);
                    $request->merge([$key => '[redacted]']);
                }
            }
        }
        if ($request->is('api/v1/integration-webhooks/sms/*')) {
            $raw = $request->getContent();
            $oversized = strlen($raw) > 32768;
            $private['sms_raw'] = $oversized ? '' : $raw;
            $private['sms_signature'] = $request->header('X-Twilio-Signature', '');
            $request->server->set('HTTP_X_TWILIO_SIGNATURE', '[redacted]');
            $request->initialize([], [], $request->attributes->all(), $request->cookies->all(), [], $request->server->all(), '[redacted]');
            $request->setJson(new InputBag(['redacted' => true]));
            abort_if($oversized, 413);
        }
        if ($request->is('api/v1/integration-webhooks/slack/*')) {
            $raw = $request->getContent();
            $oversized = strlen($raw) > 65536;
            $private['slack_raw'] = $oversized ? '' : $raw;
            $private['slack_signature'] = $request->header('X-Slack-Signature', '');
            $private['slack_timestamp'] = $request->header('X-Slack-Request-Timestamp', '');
            $request->server->set('HTTP_X_SLACK_SIGNATURE', '[redacted]');
            $request->initialize([], [], $request->attributes->all(), $request->cookies->all(), [], $request->server->all(), '[redacted]');
            $request->setJson(new InputBag(['redacted' => true]));
            abort_if($oversized, 413);
        }
        if ($request->hasFile('document')) {
            $private['document_upload'] = $request->file('document');
            $request->files->remove('document');
            $request->initialize($request->query->all(), $request->request->all(), $request->attributes->all(), $request->cookies->all(), [], $request->server->all(), '[redacted]');
        }
        if ($request->is('api/v1/integration-webhooks/email/*')) {
            $oversized = strlen($request->getContent()) > 131072;
            $private['email_push_authorization'] = $request->bearerToken();
            $private['email_notification'] = $oversized ? [] : $request->json()->all();
            $private['email_validation_token'] = $request->query('validationToken');
            $request->server->set('HTTP_AUTHORIZATION', '[redacted]');
            $query = $request->query->all();
            if (isset($query['validationToken'])) {
                $query['validationToken'] = '[redacted]';
            }
            $request->initialize($query, [], $request->attributes->all(), $request->cookies->all(), [], $request->server->all(), '[redacted]');
            $request->setJson(new InputBag(['redacted' => true]));
            abort_if($oversized, 413);
        }
        if ($request->is('api/v1/integration-webhooks/telegram/*')) {
            abort_if(strlen($request->getContent()) > 65536, 413);
            $private['telegram_webhook_secret'] = $request->header('X-Telegram-Bot-Api-Secret-Token');
            $private['telegram_update'] = $request->json()->all();
            $request->server->set('HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN', '[redacted]');
            $request->initialize($request->query->all(), [], $request->attributes->all(), $request->cookies->all(), [], $request->server->all(), '[redacted]');
            $request->setJson(new InputBag(['redacted' => true]));
        }
        foreach (['credentials', 'password', 'state', 'code', 'signature', 'simulation_text', 'simulation_email', 'data', 'preview_inputs', 'phone', 'template_text'] as $key) {
            if ($request->exists($key)) {
                $private[$key] = $request->input($key);
                $request->merge([$key => '[redacted]']);
            }
        }
        if (! $request->is('api/v1/integration-webhooks/telegram/*') && $private !== [] && $request->isJson()) {
            $sanitized = $request->json()->all();
            $request->initialize($request->query->all(), [], $request->attributes->all(), $request->cookies->all(), [], $request->server->all(), json_encode($sanitized));
            $request->setJson(new InputBag($sanitized));
        }
        $request->attributes->set('integration_input', new SensitiveInput($private));
        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $status = $exception instanceof AuthorizationException ? 403 : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : ($exception instanceof ValidationException ? 422 : 503));
            $messages = [401 => 'Sign in to continue.', 403 => 'Integration permission denied.', 404 => 'Not found.', 409 => 'Connection cannot perform this operation.', 419 => 'Secure session expired.', 422 => 'Check the connection fields and provider schema.', 428 => 'Confirm your password to continue.', 429 => 'Too many requests. Try again shortly.'];
            $response = response()->json(['message' => $messages[$status] ?? 'Integration unavailable.'], $status);
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
