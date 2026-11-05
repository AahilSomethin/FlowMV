<?php

namespace App\Exceptions;

class SlackApiFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $retryable = false, public readonly int $retryAfter = 0, public readonly bool $ambiguous = false)
    {
        parent::__construct('Slack request could not be completed.');
    }
}
