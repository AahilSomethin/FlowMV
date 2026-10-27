<?php

namespace App\Console\Commands;

use App\Actions\WorkflowInstances\ResumeDelayedWorkflowInstance;
use App\Enums\WorkflowDelayStatus;
use App\Models\WorkflowDelay;
use Illuminate\Console\Command;

class ResumeDueWorkflowDelays extends Command
{
    protected $signature = 'workflow:resume-due-delays {--limit=100}';

    protected $description = 'Recover delayed workflow instances whose resume jobs were lost';

    public function handle(ResumeDelayedWorkflowInstance $resume): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $due = WorkflowDelay::query()
            ->where('status', WorkflowDelayStatus::Pending)
            ->where('resume_at', '<=', now())
            ->orderBy('resume_at')
            ->limit($limit)
            ->pluck('id');

        $resumed = 0;

        foreach ($due as $delayId) {
            if ($resume((int) $delayId)) {
                $resumed++;
            }
        }

        $this->info("Resumed {$resumed} delayed workflow instance(s).");

        return self::SUCCESS;
    }
}
