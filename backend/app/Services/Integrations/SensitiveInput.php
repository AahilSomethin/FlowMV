<?php

namespace App\Services\Integrations;

final class SensitiveInput implements \JsonSerializable
{
    public function __construct(#[\SensitiveParameter] private array $values) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    public function jsonSerialize(): mixed
    {
        return '[redacted]';
    }
}
