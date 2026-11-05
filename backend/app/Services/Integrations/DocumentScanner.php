<?php

namespace App\Services\Integrations;

interface DocumentScanner
{
    /** Returns clean, infected, or unavailable; implementations must not log the bytes. */
    public function scan(#[\SensitiveParameter] string $bytes): string;
}
