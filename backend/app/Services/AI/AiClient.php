<?php

namespace App\Services\AI;

use App\Support\DemoEnvironment;
use Illuminate\Http\Client\Factory;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AiClient
{
    public readonly Factory $http;

    public function __construct()
    {
        $this->http = new Factory;
    }

    public function call(string $operation, #[\SensitiveParameter] array $payload): array
    {
        abort_unless(in_array($operation, [...AiAccess::OPERATIONS, 'extract/deterministic', 'connections/test'], true), 422);
        $origin = rtrim((string) config('ai.service_url'), '/');
        $url = parse_url($origin);
        $encodedKey = (string) config('ai.service_key');
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $encodedKey), 503);
        $key = hex2bin($encodedKey);
        abort_unless($url && isset($url['host']) && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment']) && empty($url['path']) && is_string($key) && strlen($key) === 32, 503);
        if (($url['scheme'] ?? '') !== 'https') {
            $loopback = in_array($url['host'], ['127.0.0.1', 'localhost', '[::1]'], true) && app()->environment(['local', 'testing', 'demo']);
            $container = app()->environment('demo') && $url['host'] === 'ai-go' && config('demo.profile') === 'container';
            abort_unless(($url['scheme'] ?? '') === 'http' && ($loopback || $container), 503);
            if (app()->environment('demo')) {
                app(DemoEnvironment::class)->assertOwned();
            }
        }
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        abort_if(strlen($raw) > 262144, 422);
        try {
            $client = $this->http->withOptions(['verify' => config('ai.ca_file') ?: true])->connectTimeout(3)->timeout(28)->withoutRedirecting();
            $ready = $client->get($origin.'/readyz');
            $audience = $ready->json('audience');
            abort_unless($ready->successful() && $ready->json('contract') === 'flowmv.ai.v1' && is_string($audience) && preg_match('/^[a-f0-9]{64}$/D', $audience), 503);
            $timestamp = (string) now()->timestamp;
            $nonce = bin2hex(random_bytes(32));
            $path = '/v1/'.$operation;
            $signature = hash_hmac('sha256', "POST\n$path\n$timestamp\n$nonce\n$audience\n".hash('sha256', $raw), $key);
            $response = $client->withHeaders(['X-FlowMV-Time' => $timestamp, 'X-FlowMV-Nonce' => $nonce, 'X-FlowMV-Audience' => $audience, 'X-FlowMV-Signature' => $signature])->withBody($raw, 'application/json')->post($origin.$path);
            abort_unless($response->successful() && strlen($response->body()) <= 131072, $response->status() === 429 ? 429 : 503);
            $result = $response->json();
            abort_unless(is_array($result), 503);

            return $result;
        } catch (\Throwable $exception) {
            abort($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 429 ? 429 : 503, 'AI processing unavailable.');
        }
    }
}
