<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Validator;

class MockConnector implements Connector
{
    public function manifest(): array
    {
        return [
            'id' => 'local_mock', 'version' => 3, 'name' => 'Local demonstration',
            'reads' => 'Synthetic event identifiers only. No inboxes, files or contacts.',
            'sends' => 'Records a simulated notification receipt locally. No messages leave FlowMV.',
            'credential_types' => ['api_key', 'bot_token', 'oauth'],
            'credential_schemas' => [
                'api_key' => ['api_key' => 'required string, 16–512 characters'],
                'bot_token' => ['bot_token' => 'required string, 16–512 characters'],
                'oauth' => ['access_token' => 'issued through authorization code + S256 PKCE'],
            ],
            'actions' => ['mock.notify'], 'triggers' => ['mock.event'],
            'configuration_schema' => ['destination' => ['mock:inbox', 'mock:review']],
            'rate_limit' => ['per_minute' => 30], 'retry' => ['max_attempts' => 3, 'backoff_seconds' => [10, 60, 300]],
            'audit_metadata' => ['connection_id', 'actor_user_id', 'event'],
            'webhook' => 'HMAC-SHA256 over timestamp.event_id; 5 minute window; durable event deduplication',
        ];
    }

    public function validateCredentials(string $type, #[\SensitiveParameter] array $credentials): array
    {
        $field = match ($type) {
            'api_key' => 'api_key', 'bot_token' => 'bot_token', 'oauth' => 'access_token', default => null
        };
        abort_if($field === null, 422, 'Unsupported credential type.');
        $validator = Validator::make($credentials, [$field => ['required', 'string', 'min:16', 'max:512']]);
        abort_if($validator->fails() || array_keys($credentials) !== [$field], 422, 'Credential does not match the provider schema.');

        return [$field => $credentials[$field]];
    }

    public function validateConfiguration(string $operation, array $configuration): void
    {
        abort_unless(in_array($operation, ['mock.notify', 'mock.event'], true) && array_keys($configuration) === ['destination'] && in_array($configuration['destination'], ['mock:inbox', 'mock:review'], true), 422, 'Unsupported integration configuration.');
    }

    public function test(#[\SensitiveParameter] array $credentials): bool
    {
        return count($credentials) === 1 && strlen((string) reset($credentials)) >= 16;
    }

    public function execute(string $operation, string $destination, #[\SensitiveParameter] array $credentials, string $idempotencyKey): void
    {
        $this->validateConfiguration($operation, ['destination' => $destination]);
        abort_unless($operation === 'mock.notify' && $this->test($credentials), 403, 'Integration operation denied.');
    }

    public function verifyWebhook(string $eventId, int $timestamp, string $signature, #[\SensitiveParameter] array $credentials): bool
    {
        return abs(now()->timestamp - $timestamp) <= 300 && hash_equals(hash_hmac('sha256', $timestamp.'.'.$eventId, (string) reset($credentials)), $signature);
    }
}
