<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\WorkflowInstance;
use App\Models\WorkflowSubmissionSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workflows\InteractsWithExecution;
use Tests\Support\AutomationGraphs;
use Tests\TestCase;

class SnapshotRetentionTest extends TestCase
{
    use InteractsWithExecution, RefreshDatabase;

    public function test_retention_counts_only_old_terminal_requests_and_never_deletes_without_approved_policy(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $workflow = $this->publishWorkflow($organization, AutomationGraphs::delay(['duration_seconds' => 1]));
        $this->startInstance($organization, $workflow)->assertCreated();
        $instance = WorkflowInstance::query()->firstOrFail();
        $instance->forceFill(['status' => 'completed', 'completed_at' => now()->subDays(400)])->save();
        $this->artisan('workflow:snapshot-retention', ['--days' => 365])->expectsOutput('Dry run: 1 snapshots belong to terminal requests completed more than 365 days ago. No rows were deleted.')->assertSuccessful();
        $instance->forceFill(['status' => 'awaiting_delay'])->save();
        $this->artisan('workflow:snapshot-retention', ['--days' => 365])->expectsOutput('Dry run: 0 snapshots belong to terminal requests completed more than 365 days ago. No rows were deleted.')->assertSuccessful();
        $this->artisan('workflow:snapshot-retention', ['--days' => 365, '--apply' => true])->assertFailed();
        $this->artisan('workflow:snapshot-retention', ['--days' => 0])->assertFailed();
        $this->assertSame(1, WorkflowSubmissionSnapshot::query()->count());
    }
}
