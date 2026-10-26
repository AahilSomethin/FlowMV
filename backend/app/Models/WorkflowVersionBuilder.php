<?php

namespace App\Models;

use App\Exceptions\WorkflowVersionImmutableException;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<WorkflowVersion>
 */
class WorkflowVersionBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values)
    {
        throw new WorkflowVersionImmutableException('Published workflow versions cannot be modified.');
    }
}
