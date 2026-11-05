<?php

namespace App\Jobs;

use App\Services\Integrations\TelegramDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessTelegramDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $deliveryId)
    {
        $this->afterCommit = true;
    }

    public function handle(TelegramDelivery $delivery): void
    {
        $delivery->process($this->deliveryId);
    }
}
