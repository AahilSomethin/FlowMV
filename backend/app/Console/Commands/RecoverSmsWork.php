<?php

namespace App\Console\Commands;

use App\Services\Integrations\SmsDelivery;
use Illuminate\Console\Command;

class RecoverSmsWork extends Command
{
    protected $signature = 'integrations:recover-sms';

    protected $description = 'Recover due SMS actions and reconcile known provider messages';

    public function handle(SmsDelivery $delivery): int
    {
        $delivery->recover();

        return self::SUCCESS;
    }
}
