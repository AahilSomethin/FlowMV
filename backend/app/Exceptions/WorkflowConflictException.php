<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class WorkflowConflictException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(409, $message);
    }
}
