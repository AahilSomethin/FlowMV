<?php

namespace App\Console\Commands;

use App\Services\Integrations\SlackDelivery;
use Illuminate\Console\Command;

class RecoverSlackWork extends Command
{
    protected $signature = 'integrations:recover-slack';

    protected $description = 'Recover due Slack work without resending ambiguous actions';

    public function handle(SlackDelivery $delivery): int
    {
        $delivery->recover();

        return self::SUCCESS;
    }
}
