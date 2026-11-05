<?php

namespace App\Console\Commands;

use App\Services\Integrations\TelegramDecisions;
use App\Services\Integrations\TelegramDelivery;
use Illuminate\Console\Command;

class RecoverTelegramWork extends Command
{
    protected $signature = 'integrations:recover-telegram';

    protected $description = 'Recover due Telegram activations, deliveries and interrupted claims';

    public function handle(TelegramDelivery $delivery): int
    {
        $delivery->recover();
        app(TelegramDecisions::class)->recover();
        $this->info('Telegram recovery scan complete.');

        return self::SUCCESS;
    }
}
