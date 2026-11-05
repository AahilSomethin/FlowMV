<?php

namespace App\Jobs;

use App\Services\AI\AiProcessing;
use App\Services\AI\AiWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAiRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 40;

    public function __construct(public string $runId) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        app(AiProcessing::class)->process($this->runId);
        app(AiWorkflow::class)->resume($this->runId);
    }
}
