<?php

namespace Tests\Feature;

use App\Jobs\ProfileDemoDocument;
use App\Services\Integrations\DocumentExtractor;
use App\Services\Integrations\DocumentScanner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DemoQueueProfileTest extends TestCase
{
    public function test_document_profiling_job_refuses_non_demo_before_parser_or_storage_work(): void
    {
        $this->mock(DocumentExtractor::class)->shouldNotReceive('extract');
        $this->mock(DocumentScanner::class)->shouldNotReceive('scan');
        File::shouldReceive('put')->never();
        $this->expectException(HttpException::class);
        (new ProfileDemoDocument((string) Str::uuid(), 0, microtime(true)))->handle();
    }
}
