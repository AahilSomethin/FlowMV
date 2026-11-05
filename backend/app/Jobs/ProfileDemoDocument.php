<?php

namespace App\Jobs;

use App\Services\Integrations\DocumentExtractor;
use App\Services\Integrations\DocumentScanner;
use App\Support\DemoEnvironment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ProfileDemoDocument implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $run, public int $sequence, public float $enqueuedAt) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $at = hrtime(true);
        $started = microtime(true);
        $before = function_exists('getrusage') ? getrusage() : null;
        app(DemoEnvironment::class)->assertOwned();
        abort_unless(Str::isUuid($this->run) && $this->sequence >= 0 && $this->sequence < 200 && is_finite($this->enqueuedAt), 422);
        $path = tempnam(sys_get_temp_dir(), 'flowmv-demo-profile-');
        try {
            $zip = new \ZipArchive;
            $zip->open($path, \ZipArchive::OVERWRITE);
            $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
            $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Employee name: Synthetic Applicant</w:t></w:r></w:p></w:body></w:document>');
            $zip->close();
            abort_unless(app(DocumentScanner::class)->scan(file_get_contents($path)) === 'clean', 503);
            $result = app(DocumentExtractor::class)->extract($path, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            abort_unless($result['status'] === 'extracted', 503);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $after = function_exists('getrusage') ? getrusage() : null;
        $cpu = null;
        if ($before && $after) {
            $cpu = 0;
            foreach (['ru_utime', 'ru_stime'] as $key) {
                $cpu += ($after[$key.'.tv_sec'] - $before[$key.'.tv_sec']) * 1000 + ($after[$key.'.tv_usec'] - $before[$key.'.tv_usec']) / 1000;
            }
        }
        $directory = rtrim(config('demo.private_root'), '/\\').'/profiles/'.$this->run;
        File::ensureDirectoryExists($directory, 0700);
        File::put($directory.'/'.$this->sequence.'.json', json_encode(['processing_ms' => (hrtime(true) - $at) / 1e6, 'queue_wait_ms' => ($started - $this->enqueuedAt) * 1000, 'end_to_end_ms' => (microtime(true) - $this->enqueuedAt) * 1000, 'worker_cpu_ms' => $cpu, 'worker_peak_php_bytes' => memory_get_peak_usage(true), 'extraction' => 'real synthetic DOCX', 'scanner' => 'simulated'], JSON_THROW_ON_ERROR));
    }
}
