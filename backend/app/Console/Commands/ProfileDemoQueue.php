<?php

namespace App\Console\Commands;

use App\Jobs\ProfileDemoDocument;
use App\Support\DemoEnvironment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

#[Signature('demo:queue-profile {--jobs=40 : Bounded synthetic document jobs, 10 to 200}')]
#[Description('Measure durable queue and document processing only in the owned demo')]
class ProfileDemoQueue extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        app(DemoEnvironment::class)->assertOwned();
        $count = filter_var($this->option('jobs'), FILTER_VALIDATE_INT);
        if ($count === false || $count < 10 || $count > 200 || config('queue.default') !== 'database') {
            $this->error('A database queue and 10 to 200 jobs are required.');

            return self::INVALID;
        }
        $run = (string) Str::uuid();
        $queue = 'demo-profile-'.$run;
        $at = hrtime(true);
        for ($i = 0; $i < $count; $i++) {
            ProfileDemoDocument::dispatch($run, $i, microtime(true))->onQueue($queue);
        }
        $worker = new Process([PHP_BINARY, base_path('artisan'), 'queue:work', '--env=demo', '--queue='.$queue, '--stop-when-empty', '--sleep=1', '--tries=1', '--timeout=30', '--max-jobs='.$count], base_path());
        $worker->setTimeout(120);
        $worker->run();
        if (! $worker->isSuccessful()) {
            $this->error('Owned profiling worker failed. Inspect safe job status; no dataset reset was performed.');

            return self::FAILURE;
        }
        $directory = rtrim(config('demo.private_root'), '/\\').'/profiles/'.$run;
        $metrics = [];
        foreach (File::glob($directory.'/*.json') as $file) {
            $metrics[] = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);
        }
        if (count($metrics) !== $count) {
            $this->error('Profiling completion count mismatch.');

            return self::FAILURE;
        }
        $wall = (hrtime(true) - $at) / 1e6;
        $statistics = [];
        foreach (['processing_ms', 'queue_wait_ms', 'end_to_end_ms'] as $key) {
            $values = array_column($metrics, $key);
            sort($values);
            $statistics[$key] = ['p50' => $values[(int) ceil($count * .5) - 1], 'p95' => $values[(int) ceil($count * .95) - 1]];
        }
        $output = ['profile' => config('demo.profile'), 'jobs' => $count, 'successful_jobs' => count($metrics), 'concurrency' => 1, 'wall_ms' => round($wall, 3), 'throughput_per_second' => round($count / ($wall / 1000), 2), 'statistics' => $statistics, 'worker_cpu_ms' => array_sum(array_column($metrics, 'worker_cpu_ms')), 'worker_peak_php_bytes' => max(array_column($metrics, 'worker_peak_php_bytes')), 'limitations' => ['Real durable database jobs and bounded DOCX parser; simulated scanner.', 'Includes queue insertion and worker startup/shutdown overhead.', 'PHP allocated peak is not OS RSS; CPU excludes process boot and is timer-resolution limited.', 'No provider traffic or domain/audit records; no injected transport delay; no p99 below 10000 jobs.']];
        $path = storage_path('app/phase10-audit/queue-profile-'.config('demo.profile').'-'.$run.'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->line(json_encode($output, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
