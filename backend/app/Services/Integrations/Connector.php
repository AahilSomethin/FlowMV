<?php
namespace App\Services\Integrations;
interface Connector
{
    public function manifest(): array;
    public function validateCredentials(string $type, #[\SensitiveParameter] array $credentials): array;
    public function validateConfiguration(string $operation, array $configuration): void;
    public function test(#[\SensitiveParameter] array $credentials): bool;
    public function execute(string $operation, string $destination, #[\SensitiveParameter] array $credentials, string $idempotencyKey): void;
    public function verifyWebhook(string $eventId, int $timestamp, string $signature, #[\SensitiveParameter] array $credentials): bool;
}
