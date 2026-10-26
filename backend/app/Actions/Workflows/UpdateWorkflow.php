<?php

namespace App\Actions\Workflows;

use App\Models\Workflow;
use Illuminate\Support\Facades\DB;

class UpdateWorkflow
{
    public function __invoke(Workflow $workflow, string $name, ?string $description): Workflow
    {
        return DB::transaction(function () use ($workflow, $name, $description) {
            $locked = Workflow::query()->whereKey($workflow->id)->lockForUpdate()->firstOrFail();
            $locked->fill([
                'name' => $name,
                'description' => $description,
            ])->save();

            return $locked->fresh(['draft', 'currentPublishedVersion.publisher']);
        });
    }
}
