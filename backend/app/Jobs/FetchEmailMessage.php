<?php

namespace App\Jobs;

use App\Services\Integrations\EmailMessageReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FetchEmailMessage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $workId) {}

    /**
     * Execute the job.
     */
    public function handle(EmailMessageReader $reader): void
    {
        $reader->process($this->workId);
    }
}
