<?php

Schedule::command('integrations:recover-email')->everyMinute()->withoutOverlapping();

use App\Jobs\OperationsHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('workflow:resume-due-delays')->everyMinute();
Schedule::command('workflow:recover-notifications')->everyMinute();
Schedule::command('integrations:recover-telegram')->everyMinute();
Schedule::command('integrations:recover-slack')->everyMinute()->withoutOverlapping();

Schedule::command('integrations:recover-sms')->everyMinute()->withoutOverlapping();

Schedule::command('integrations:recover-ai')->everyMinute()->withoutOverlapping();

if (config('operations.heartbeat_enabled')) {
    Schedule::call(function (): void {
        Cache::put('operations.scheduler', time(), 600);
        OperationsHeartbeat::dispatch(time());
    })->name('operations:heartbeat')->everyMinute()->withoutOverlapping();
}
