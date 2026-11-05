<?php

namespace App\Exceptions;

class EmailProviderFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status, public readonly int $retryAfter = 30)
    {
        parent::__construct('Email provider operation unavailable.');
    }
}
