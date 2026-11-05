<?php

namespace App\Jobs;

use App\Services\Integrations\TelegramDecisions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessTelegramApproval implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $id, public readonly bool $callback = false)
    {
        $this->afterCommit = true;
    }

    public function handle(TelegramDecisions $decisions): void
    {
        if ($this->callback) {
            $decisions->process($this->id);
        } else {
            $decisions->deliver($this->id);
        }
    }
}
