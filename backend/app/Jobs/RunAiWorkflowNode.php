<?php

namespace App\Jobs;

use App\Services\AI\AiWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunAiWorkflowNode implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 40;

    public function __construct(public int $instanceId, public string $nodeId) {}

    public function handle(AiWorkflow $workflow): void
    {
        $workflow->request($this->instanceId, $this->nodeId);
    }
}
