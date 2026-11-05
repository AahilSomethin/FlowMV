<?php

namespace App\Jobs;

use App\Services\Integrations\TelegramActivation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ActivateTelegramEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $activationId)
    {
        $this->afterCommit = true;
    }

    public function handle(TelegramActivation $activation): void
    {
        $activation->process($this->activationId);
    }
}
