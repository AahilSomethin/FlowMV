<?php

namespace App\Jobs;

use App\Actions\WorkflowInstances\ResumeDelayedWorkflowInstance as ResumeAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ResumeDelayedWorkflowInstance implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $delayId)
    {
        $this->afterCommit = true;
    }

    public function handle(ResumeAction $resume): void
    {
        $resume($this->delayId);
    }
}
