<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Published workflow versions are insert-only.
 *
 * Eloquent updates are rejected on every database driver. PostgreSQL also
 * installs a BEFORE UPDATE trigger in the workflow migration. The sqlite
 * database used by tests does not create that trigger, so a raw query that
 * bypasses Eloquent is not rejected there. That raw path is not exposed by
 * the API and remains a non-PostgreSQL hardening gap.
 */
class WorkflowVersionImmutableException extends RuntimeException
{
}
