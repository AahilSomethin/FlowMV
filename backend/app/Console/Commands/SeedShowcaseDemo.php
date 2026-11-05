<?php

namespace App\Console\Commands;

use App\Services\Demo\ShowcaseDataset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo:seed')]
#[Description('Seed a new owned demo target, or verify its existing showcase without overwriting')]
class SeedShowcaseDemo extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ShowcaseDataset $dataset): int
    {
        try {
            $this->line(json_encode($dataset->seed(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error('Demo seed failed: '.get_class($error));
            foreach (array_slice($error->getTrace(), 0, 12) as $frame) {
                $this->line(($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'].' ('.basename($frame['file'] ?? '').':'.($frame['line'] ?? 0).')');
            }

            return self::FAILURE;
        }
    }
}
