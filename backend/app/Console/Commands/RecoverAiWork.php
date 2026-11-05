<?php

namespace App\Console\Commands;

use App\Enums\WorkflowInstanceStatus;
use App\Jobs\ProcessAiRun;
use App\Jobs\RunAiWorkflowNode;
use App\Models\WorkflowInstance;
use App\Services\Integrations\ConnectionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecoverAiWork extends Command
{
    protected $signature = 'integrations:recover-ai';

    protected $description = 'Expire private AI advice and recover safe references without retrying ambiguous provider calls';

    public function handle(): int
    {
        DB::table('ai_processing_runs')->where(fn ($q) => $q->where('expires_at', '<=', now())->orWhere(fn ($q) => $q->where('status', 'processing')->where('processing_at', '<=', now()->subMinutes(2))))->where(fn ($q) => $q->whereNotNull('ciphertext')->orWhereIn('status', ['pending', 'processing']))->orderBy('id')->chunkById(100, function ($runs) {
            foreach ($runs as $run) {
                DB::transaction(function () use ($run) {
                    $row = DB::table('ai_processing_runs')->where('id', $run->id)->lockForUpdate()->first();
                    $expired = Carbon::parse($row->expires_at)->isPast();
                    $stale = $row->status === 'processing' && $row->processing_at && Carbon::parse($row->processing_at)->lte(now()->subMinutes(2));
                    if (! $expired && ! $stale) {
                        return;
                    }
                    DB::table('ai_processing_runs')->where('id', $row->id)->update(['ciphertext' => null, 'key_version' => null, 'status' => $expired ? 'expired' : 'failed', 'error_code' => $expired ? 'retention_expired' : 'manual_review_required', 'updated_at' => now()]);
                    DB::table('ai_usage_reservations')->where('run_id', $row->id)->update(['state' => 'uncertain', 'updated_at' => now()]);
                    app(ConnectionManager::class)->audit($row->organization_id, $row->connection_id, null, $expired ? 'ai.processing.expired' : 'ai.processing.interrupted');
                }, 3);
            }
        }, 'id');
        DB::table('ai_processing_runs')->where('status', 'pending')->where('expires_at', '>', now())->orderBy('id')->chunkById(100, function ($runs) {
            foreach ($runs as $run) {
                ProcessAiRun::dispatch($run->id);
            }
        }, 'id');
        WorkflowInstance::where('status', WorkflowInstanceStatus::AwaitingAi)->orderBy('id')->chunkById(100, function ($instances) {
            foreach ($instances as $instance) {
                RunAiWorkflowNode::dispatch($instance->id, $instance->current_node_id);
            }
        });

        return self::SUCCESS;
    }
}
