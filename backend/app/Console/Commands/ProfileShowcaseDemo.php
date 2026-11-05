<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Analytics\OrganizationAnalytics;
use App\Services\Demo\ShowcaseDataset;
use App\Services\Integrations\DocumentExtractor;
use App\Services\Integrations\SlackQaTransport;
use App\Services\Workflows\WorkflowDefinitionNormalizer;
use App\Support\DemoEnvironment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

#[Signature('demo:profile {--samples=100 : Repetitions per workload, 20 to 1000}')]
#[Description('Measure bounded read-only showcase workloads without recording secrets or query bindings')]
class ProfileShowcaseDemo extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        app(DemoEnvironment::class)->assertOwned();
        $samples = filter_var($this->option('samples'), FILTER_VALIDATE_INT);
        if ($samples === false || $samples < 20 || $samples > 1000) {
            $this->error('Samples must be between 20 and 1000.');

            return self::INVALID;
        }
        $dataset = DB::table('demo_datasets')->where('recipe_version', ShowcaseDataset::RECIPE)->firstOrFail();
        $org = Organization::findOrFail($dataset->organization_id);
        $user = $org->creator;
        $manifest = json_decode($dataset->manifest, true, flags: JSON_THROW_ON_ERROR);
        $workflow = $org->workflows()->where('uuid', $manifest['workflows']['leave-request'])->with('currentPublishedVersion')->firstOrFail();
        $version = $workflow->currentPublishedVersion;
        $slack = DB::table('connections')->where('organization_id', $org->id)->where('provider_id', 'slack')->value('id');
        $queryCount = 0;
        $sqlMs = 0.0;
        DB::listen(function ($query) use (&$queryCount, &$sqlMs): void {
            $queryCount++;
            $sqlMs += $query->time;
        });
        $temp = tempnam(sys_get_temp_dir(), 'flowmv-profile-');
        $zip = new \ZipArchive;
        $zip->open($temp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Employee name: Synthetic Applicant</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $workloads = [
            'analytics_admin_100_requests' => fn () => app(OrganizationAnalytics::class)->summary($user, $org, Carbon::parse($manifest['clock_start']), now()->addDay(), null, null),
            'definition_normalization' => fn () => app(WorkflowDefinitionNormalizer::class)->normalize($version->graph_definition, $version->form_definition),
            'docx_extraction_synthetic' => fn () => app(DocumentExtractor::class)->extract($temp, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            'verified_slack_duplicate_ingress' => fn () => app(SlackQaTransport::class)->event($slack, 'U90000002', '/request', 'Synthetic cross-channel request 1', 'showcase-request-0'),
        ];
        $results = [];
        try {
            foreach ($workloads as $name => $run) {
                for ($i = 0; $i < 3; $i++) {
                    $run();
                }
                $queryCount = 0;
                $sqlMs = 0;
                $times = [];
                $start = hrtime(true);
                $cpuBefore = function_exists('getrusage') ? getrusage() : null;
                for ($i = 0; $i < $samples; $i++) {
                    $at = hrtime(true);
                    $result = $run();
                    if ($name === 'docx_extraction_synthetic' && $result['status'] !== 'extracted') {
                        throw new \RuntimeException('Document benchmark extraction did not succeed.');
                    } if ($name === 'verified_slack_duplicate_ingress' && ($result['duplicate'] ?? false) !== true) {
                        throw new \RuntimeException('Replay workload was not deduplicated.');
                    } $times[] = (hrtime(true) - $at) / 1e6;
                }
                $wall = (hrtime(true) - $start) / 1e6;
                $cpuAfter = function_exists('getrusage') ? getrusage() : null;
                $cpu = null;
                if ($cpuBefore && $cpuAfter) {
                    $cpu = 0;
                    foreach (['ru_utime', 'ru_stime'] as $key) {
                        $cpu += ($cpuAfter[$key.'.tv_sec'] - $cpuBefore[$key.'.tv_sec']) * 1000 + ($cpuAfter[$key.'.tv_usec'] - $cpuBefore[$key.'.tv_usec']) / 1000;
                    }
                }
                sort($times);
                $p = fn (float $q) => round($times[(int) ceil(count($times) * $q) - 1], 4);
                $results[$name] = ['successful_samples' => $samples, 'p50_ms' => $p(.5), 'p95_ms' => $p(.95), 'throughput_per_second' => round($samples / ($wall / 1000), 2), 'wall_ms' => round($wall, 3), 'sql_ms' => round($sqlMs, 3), 'query_count' => $queryCount, 'application_and_client_wait_ms' => round(max(0, $wall - $sqlMs), 3), 'process_cpu_ms' => $cpu, 'injected_transport_delay_ms' => 0];
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
        $output = ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'target' => 'owned demo PostgreSQL / 100 synthetic requests', 'concurrency' => 1, 'warmups_per_workload' => 3, 'peak_process_bytes' => memory_get_peak_usage(true), 'results' => $results, 'limitations' => ['In-process services; authenticated HTTP and durable queue throughput are separate measurements.', 'Application and client wait is wall time minus SQL; it is not CPU.', 'No p99: fewer than 10000 samples.', 'Provider transports and scanner are simulated. No injected network delay.']];
        $path = storage_path('app/phase10-audit/profile-'.$samples.'-'.Str::uuid().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->line(json_encode($output, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
