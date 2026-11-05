<?php

namespace App\Console\Commands;

use App\Services\Demo\AiShowcaseDataset;
use Illuminate\Console\Command;

class SeedAiShowcase extends Command
{
    protected $signature = 'demo:ai-seed {--verify : Verify only without creating records} {--rehearse : Exercise the composed email AI and Telegram leave workflow}';

    protected $description = 'Extend only the owned fictional showcase with simulated AI scenarios; never overwrite';

    public function handle(AiShowcaseDataset $dataset): int
    {
        try {
            $result = $this->option('rehearse') ? $dataset->rehearse((bool) $this->option('verify')) : ($this->option('verify') ? $dataset->verify() : $dataset->seed());
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error('AI showcase unavailable: '.get_class($error));
            foreach (array_slice($error->getTrace(), 0, 8) as $frame) {
                $this->line(($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'].' ('.basename($frame['file'] ?? '').':'.($frame['line'] ?? 0).')');
            }

            return self::FAILURE;
        }
    }
}
