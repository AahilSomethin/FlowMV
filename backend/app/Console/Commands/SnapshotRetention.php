<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('workflow:snapshot-retention {--days= : Proposed retention age in days} {--apply : Request destructive cleanup (disabled pending policy approval)}')]
#[Description('Count terminal-instance snapshot candidates without exposing submission values')]
class SnapshotRetention extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('apply')) {
            $this->error('Destructive cleanup is disabled. An approved retention policy and maintenance-role implementation are required.');

            return self::FAILURE;
        }

        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($days === false || $days === null) {
            $this->error('Provide --days as a positive integer for this dry run.');

            return self::FAILURE;
        }

        $count = DB::table('workflow_submission_snapshots')->whereIn('workflow_instance_id', function ($query) use ($days): void {
            $query->select('id')->from('workflow_instances')->whereIn('status', ['completed', 'rejected'])
                ->where('completed_at', '<', now()->subDays($days));
        })->count();
        $this->info("Dry run: {$count} snapshots belong to terminal requests completed more than {$days} days ago. No rows were deleted.");

        return self::SUCCESS;
    }
}
