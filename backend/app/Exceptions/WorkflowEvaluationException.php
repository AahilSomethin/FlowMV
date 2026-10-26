<?php

namespace App\Exceptions;

use App\Enums\WorkflowFailureCode;
use RuntimeException;

class WorkflowEvaluationException extends RuntimeException
{
    public function __construct(public readonly WorkflowFailureCode $failureCode, string $message)
    {
        parent::__construct($message);
    }
}
