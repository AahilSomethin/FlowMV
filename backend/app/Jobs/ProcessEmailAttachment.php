<?php

namespace App\Jobs;

use App\Services\Integrations\EmailMessageReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessEmailAttachment implements ShouldQueue
{
    use Queueable;

    public int $timeout = 45;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $attachmentId) {}

    /**
     * Execute the job.
     */
    public function handle(EmailMessageReader $reader): void
    {
        $reader->attachment($this->attachmentId);
    }
}
