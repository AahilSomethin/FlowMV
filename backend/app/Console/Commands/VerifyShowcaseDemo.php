<?php

namespace App\Console\Commands;

use App\Services\Demo\ShowcaseDataset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo:verify')]
#[Description('Verify the owned showcase dataset without changing it')]
class VerifyShowcaseDemo extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ShowcaseDataset $dataset): int
    {
        $this->line(json_encode($dataset->verify(), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
