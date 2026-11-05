<?php

namespace App\Jobs;

use App\Services\Integrations\EmailSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncEmailMailbox implements ShouldQueue
{
    use Queueable;

    public int $timeout = 75;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $connectionId) {}

    /**
     * Execute the job.
     */
    public function handle(EmailSync $sync): void
    {
        $sync->process($this->connectionId);
    }
}
