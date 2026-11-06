<?php

namespace App\Console\Commands;

use App\Services\Demo\ShowcaseLabels;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo:align-labels')]
#[Description('Rename showcase display labels in place without touching workflows, requests, users, or audit history')]
class AlignShowcaseLabels extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ShowcaseLabels $labels): int
    {
        $result = $labels->align();
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
