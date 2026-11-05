<?php

namespace App\Jobs;

use App\Services\Integrations\SlackActivation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ActivateSlackEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $activationId)
    {
        $this->afterCommit = true;
    }

    public function handle(SlackActivation $activation): void
    {
        $activation->process($this->activationId);
    }
}
