<?php

namespace App\Actions\Workflows;

use App\Enums\WorkflowStatus;
use App\Exceptions\WorkflowConflictException;
use App\Models\Workflow;
use Illuminate\Support\Facades\DB;

class ArchiveWorkflow
{
    public function __invoke(Workflow $workflow): Workflow
    {
        return DB::transaction(function () use ($workflow) {
            $locked = Workflow::query()->whereKey($workflow->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === WorkflowStatus::Archived) {
                throw new WorkflowConflictException('This workflow is already archived.');
            }

            $locked->forceFill(['status' => WorkflowStatus::Archived])->save();

            return $locked->fresh(['draft', 'currentPublishedVersion.publisher']);
        });
    }
}
