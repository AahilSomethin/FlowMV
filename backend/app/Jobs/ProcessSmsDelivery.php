<?php

namespace App\Jobs;

use App\Services\Integrations\SmsDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessSmsDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $id, public readonly bool $reconcile = false)
    {
        $this->afterCommit = true;
    }

    public function handle(SmsDelivery $delivery): void
    {
        if ($this->reconcile) {
            $delivery->reconcile($this->id);
        } else {
            $delivery->process($this->id);
        }
    }
}
