<?php

namespace App\Support;

final class InvitationPlaintext
{
    public function __construct(#[\SensitiveParameter] private string $value) {}

    public function value(): string
    {
        return $this->value;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    public function __toString(): string
    {
        return '[redacted]';
    }
}
