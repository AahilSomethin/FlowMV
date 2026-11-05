<?php

namespace App\Console\Commands;

use App\Services\AI\AiClient;
use App\Services\Integrations\DocumentTemplates;
use App\Support\DemoEnvironment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BenchmarkAiGo extends Command
{
    protected $signature = 'demo:ai-benchmark {--samples=80}';

    protected $description = 'Compare bounded synthetic extraction with authenticated Go round trips; no provider inference';

    public function handle(AiClient $client, DocumentTemplates $templates): int
    {
        app(DemoEnvironment::class)->assertOwned();
        abort_unless(config('ai.simulated'), 503);
        $samples = filter_var($this->option('samples'), FILTER_VALIDATE_INT);
        abort_unless($samples !== false && $samples >= 20 && $samples <= 100, 422);
        $fields = [];
        $lines = [];
        for ($i = 0; $i < 20; $i++) {
            $fields[] = ['id' => 'field_'.$i, 'label' => 'Field '.$i, 'type' => 'text', 'required' => true];
            $lines[] = 'Field '.$i.': synthetic value '.$i;
        }
        $text = implode("\n", $lines)."\n".str_repeat('Synthetic ordinary workplace text without personal information. ', 1000);
        $payload = ['contract' => 'flowmv.ai.v1', 'request_id' => (string) Str::uuid(), 'organization' => 'synthetic', 'actor' => 'synthetic', 'workflow' => 'synthetic', 'connection' => '', 'operation' => 'extract/deterministic', 'provider' => '', 'model' => '', 'credential' => '', 'text' => $text, 'fields' => $fields, 'categories' => [], 'routes' => [], 'template' => 'workplace-v1', 'max_output_tokens' => 1024];
        $php = [];
        $go = [];
        $throttled = 0;
        for ($i = 0; $i < $samples; $i++) {
            $at = hrtime(true);
            $expected = $templates->extract($fields, $text);
            $php[] = (hrtime(true) - $at) / 1000000;
            $payload['request_id'] = (string) Str::uuid();
            $at = hrtime(true);
            try {
                $actual = $client->call('extract/deterministic', $payload);
            } catch (HttpException $error) {
                if ($error->getStatusCode() === 429) {
                    $throttled++;

                    continue;
                }throw $error;
            }
            $go[] = (hrtime(true) - $at) / 1000000;
            abort_unless($expected == $actual, 409, 'Deterministic extraction parity failed.');
        }
        $summarize = function (array $values): array {
            sort($values);
            $n = count($values);

            return ['successful' => $n, 'p50_ms' => $n ? $values[(int) floor(($n - 1) * .5)] : null, 'p95_ms' => $n ? $values[(int) ceil(($n - 1) * .95)] : null, 'successful_per_second' => $n ? 1000 * $n / array_sum($values) : null];
        };
        $result = ['contract' => 'phase11.synthetic-extraction.v1', 'samples' => $samples, 'concurrency' => 1, 'input_bytes' => strlen($text), 'fields' => 20, 'php_in_process' => $summarize($php), 'go_signed_http_including_readiness_and_json' => $summarize($go), 'throttled' => $throttled, 'sql_queries' => 0, 'injected_transport_delay_ms' => 0, 'parity' => 'all successful samples matched', 'provider_calls' => 0, 'p99' => 'not reported: fewer than 10000 samples', 'decision' => 'retain PHP extraction; Go owns new AI orchestration'];
        $root = storage_path('app/phase11-audit');
        File::ensureDirectoryExists($root, 0700);
        $path = $root.'/extraction-'.now()->utc()->format('Ymd-His').'-'.Str::random(6).'.json';
        File::put($path, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
