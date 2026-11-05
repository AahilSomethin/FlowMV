<?php

namespace App\Jobs;

use App\Services\Integrations\EmailDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessEmailDelivery implements ShouldQueue
{
    use Queueable;

    public int $timeout = 40;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $deliveryId) {}

    /**
     * Execute the job.
     */
    public function handle(EmailDelivery $delivery): void
    {
        $delivery->process($this->deliveryId);
    }
}
