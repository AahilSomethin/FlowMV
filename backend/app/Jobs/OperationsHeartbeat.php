<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class OperationsHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public int $issuedAt) {}

    public function handle(): void
    {
        if (config('operations.heartbeat_enabled')) {
            Cache::put('operations.worker', $this->issuedAt, 600);
        }
    }
}
