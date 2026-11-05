<?php

namespace App\Jobs;

use App\Services\Integrations\SlackDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessSlackDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $deliveryId)
    {
        $this->afterCommit = true;
    }

    public function handle(SlackDelivery $delivery): void
    {
        $delivery->process($this->deliveryId);
    }
}
