<?php

namespace App\Console\Commands;

use App\Services\Workflows\Execution\ConditionEvaluator;
use App\Services\Workflows\WorkflowDefinitionNormalizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('flowmv:benchmark {--samples=2000 : Samples per deterministic workload}')]
#[Description('Measure bounded deterministic work without database writes or provider calls')]
class BenchmarkFlowmv extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $samples = filter_var($this->option('samples'), FILTER_VALIDATE_INT);
        if ($samples === false || $samples < 100 || $samples > 20000) {
            $this->error('Samples must be between 100 and 20000.');

            return self::FAILURE;
        }
        $id = '10000000-0000-4000-8000-000000000001';
        $end = '10000000-0000-4000-8000-000000000002';
        $graph = ['nodes' => [['id' => $id, 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Start', 'description' => '']], ['id' => $end, 'type' => 'end', 'position' => ['x' => 200, 'y' => 0], 'data' => ['label' => 'End', 'description' => '', 'result' => 'completed']]], 'edges' => [['id' => '10000000-0000-4000-8000-000000000003', 'source' => $id, 'target' => $end, 'source_handle' => 'out', 'target_handle' => 'in']]];
        $normalizer = app(WorkflowDefinitionNormalizer::class);
        $evaluator = app(ConditionEvaluator::class);
        $condition = ['data' => ['match' => 'all', 'rules' => [['field_id' => 'amount', 'operator' => 'greater_than', 'value' => 100]]]];
        $workloads = ['definition_normalization' => fn () => $normalizer->normalize($graph, ['fields' => []]), 'numeric_condition' => fn () => $evaluator->evaluate($condition, ['amount' => ['id' => 'amount', 'type' => 'number']], ['amount' => 125])];
        $result = ['schema_version' => 1, 'measured_at_utc' => now()->utc()->toIso8601String(), 'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'cpu' => getenv('PROCESSOR_IDENTIFIER') ?: 'not recorded', 'scope' => 'In-process deterministic work only; no HTTP, SQL, queues, providers or document parsing. Not an end-to-end performance claim.', 'workloads' => []];
        foreach ($workloads as $name => $work) {
            for ($i = 0; $i < 100; $i++) {
                $work();
            }
            $times = [];
            $start = hrtime(true);
            for ($i = 0; $i < $samples; $i++) {
                $at = hrtime(true);
                $work();
                $times[] = (hrtime(true) - $at) / 1000000;
            }
            $seconds = (hrtime(true) - $start) / 1000000000;
            sort($times, SORT_NUMERIC);
            $percentile = fn ($fraction) => round($times[max(0, (int) ceil($samples * $fraction) - 1)], 6);
            $result['workloads'][$name] = ['samples' => $samples, 'p50_ms' => $percentile(.5), 'p95_ms' => $percentile(.95), 'p99_ms' => $samples >= 10000 ? $percentile(.99) : null, 'throughput_per_second' => round($samples / $seconds, 2), 'process_peak_memory_bytes' => memory_get_peak_usage(true)];
        }
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
