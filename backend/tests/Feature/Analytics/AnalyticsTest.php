<?php

namespace Tests\Feature\Analytics;

use App\Enums\OrganizationRole;
use App\Enums\SnapshotReason;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Models\WorkflowInstance;
use App\Models\WorkflowSubmissionSnapshot;
use App\Support\CsvCell;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Workflows\InteractsWithExecution;
use Tests\Support\ExecutionGraphs;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use InteractsWithExecution, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_metrics_use_terminal_outcomes_and_a_minimum_sample(): void
    {
        [$organization, $workflow, $employee, $manager] = $this->approvalWorkspace();
        $this->finish($organization, $workflow, $employee, $manager, 'one', '2026-10-01 00:00:00', '2026-10-01 01:00:00');
        $this->finish($organization, $workflow, $employee, $manager, 'two', '2026-10-01 00:00:00', '2026-10-01 02:00:00');
        $this->finish($organization, $workflow, $employee, $manager, 'three', '2026-10-02 00:00:00', '2026-10-02 03:00:00');
        $this->finish($organization, $workflow, $employee, $manager, 'four', '2026-10-02 00:00:00', '2026-10-02 04:00:00');
        $this->finish($organization, $workflow, $employee, $manager, 'five', '2026-10-03 00:00:00', '2026-10-03 05:00:00');
        $this->finish($organization, $workflow, $employee, $manager, 'outside', '2026-09-15 00:00:00', '2026-09-15 00:01:40');

        Carbon::setTestNow('2026-10-04 00:00:00');
        $this->signIn($employee);
        $this->startInstance($organization, $workflow, ['note' => 'still-open'])->assertCreated();

        $this->signIn($this->admin($organization));
        $report = $this->getJson($this->analytics($organization).'?from=2026-10-01&to=2026-10-31')->assertOk();

        $report->assertJsonPath('data.timezone', 'UTC')
            ->assertJsonPath('data.scope', 'organization')
            ->assertJsonPath('data.scope_label', 'Organization-wide')
            ->assertJsonPath('data.cohort.total', 6)
            ->assertJsonPath('data.completion.completed', 5)
            ->assertJsonPath('data.completion.rejected', 0)
            ->assertJsonPath('data.completion.denominator', 5)
            ->assertJsonPath('data.completion.rate', 1)
            ->assertJsonPath('data.completion.sufficient', true)
            ->assertJsonPath('data.rejection.rate', 0)
            ->assertJsonPath('data.completion_duration.sample_size', 5)
            ->assertJsonPath('data.completion_duration.sufficient', true)
            ->assertJsonPath('data.completion_duration.median_seconds', 10800)
            ->assertJsonPath('data.completion_duration.average_seconds', 10800)
            ->assertJsonPath('data.approval_wait.median_seconds', 10800)
            ->assertJsonPath('data.approval_wait.open_count', 1)
            ->assertJsonPath('data.task_decisions.approved', 5)
            ->assertJsonPath('data.task_decisions.rejected', 0)
            ->assertJsonPath('data.volume.0.date', '2026-10-01')
            ->assertJsonPath('data.volume.0.count', 2)
            ->assertJsonPath('data.workflows.0.terminal_denominator', 5);

        $filtered = $this->getJson($this->analytics($organization).'?from=2026-10-01&to=2026-10-31&status=completed')->assertOk();
        $filtered->assertJsonPath('data.cohort.total', 5)
            ->assertJsonPath('data.completion.denominator', 5)
            ->assertJsonPath('data.completion.rate', 1);

        $this->assertStringNotContainsString('still-open', $report->getContent());
    }

    public function test_small_samples_do_not_publish_rates(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $definition = ExecutionGraphs::condition(
            [ExecutionGraphs::rule('note', 'equals', 'reject-me')],
            fields: [$this->noteField()],
        );
        $workflow = $this->publishWorkflow($organization, $definition);
        Carbon::setTestNow('2026-10-10 12:00:00');
        $this->startInstance($organization, $workflow, ['note' => 'reject-me'])->assertCreated()->assertJsonPath('data.status', 'completed');
        $this->startInstance($organization, $workflow, ['note' => 'keep'])->assertCreated()->assertJsonPath('data.status', 'rejected');

        $report = $this->getJson($this->analytics($organization).'?from=2026-10-01&to=2026-10-31')->assertOk();
        $report->assertJsonPath('data.completion.completed', 1)
            ->assertJsonPath('data.completion.rejected', 1)
            ->assertJsonPath('data.completion.denominator', 2)
            ->assertJsonPath('data.completion.rate', null)
            ->assertJsonPath('data.completion.sufficient', false)
            ->assertJsonPath('data.completion_duration.sufficient', false)
            ->assertJsonPath('data.completion_duration.median_seconds', null)
            ->assertJsonPath('data.revisions.sufficient', false);
    }

    public function test_scope_and_organization_filters_do_not_leak(): void
    {
        [$organization, $workflow, $employee, $manager] = $this->approvalWorkspace('Alpha');
        $other = $this->addMember($organization, OrganizationRole::Employee);
        $this->finish($organization, $workflow, $employee, $manager, 'visible-note', '2026-10-05 00:00:00', '2026-10-05 02:00:00');
        $this->finish($organization, $workflow, $other, $manager, 'hidden-note', '2026-10-06 00:00:00', '2026-10-06 04:00:00');

        $second = $this->signInMember(OrganizationRole::Admin);
        $this->getJson($this->analytics($organization).'?from=2026-10-01&to=2026-10-31')->assertNotFound();

        $this->signIn($employee);
        $own = $this->getJson($this->analytics($organization).'?from=2026-10-01&to=2026-10-31')->assertOk();
        $own->assertJsonPath('data.scope', 'visible_requests')
            ->assertJsonPath('data.scope_label', 'Limited to requests you can view')
            ->assertJsonPath('data.cohort.total', 1);
        $this->assertStringNotContainsString('hidden-note', $own->getContent());

        $this->signIn($manager);
        $this->getJson($this->analytics($organization).'?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('data.scope', 'visible_requests')
            ->assertJsonPath('data.cohort.total', 2);

        $this->signIn($this->admin($organization));
        $beta = $this->publishWorkflow($organization, ExecutionGraphs::approval(fields: [$this->noteField()]));
        Carbon::setTestNow('2026-10-08 00:00:00');
        $this->signIn($employee);
        $this->startInstance($organization, $beta, ['note' => 'beta-only'])->assertCreated();
        $this->signIn($this->admin($organization));
        $this->getJson($this->analytics($organization).'?from=2026-10-01&to=2026-10-31&workflow='.$workflow)
            ->assertOk()
            ->assertJsonPath('data.cohort.total', 2)
            ->assertJsonPath('data.workflows.0.name', 'Alpha');

        $this->getJson("/api/v1/organizations/{$organization->uuid}/instances?workflow={$workflow}")
            ->assertOk()
            ->assertJsonPath('data.total', 2);
        $this->signIn($this->admin($second));
        $this->getJson("/api/v1/organizations/{$second->uuid}/analytics?from=2026-10-01&to=2026-10-31")
            ->assertOk()
            ->assertJsonPath('data.cohort.total', 0);
        $this->signIn($this->admin($organization));

        $export = $this->get($this->analytics($organization).'/export?from=2026-10-01&to=2026-10-31&workflow='.$workflow);
        $export->assertOk();
        $export->assertHeader('X-Export-Truncated', 'false');
        $csv = $export->streamedContent();
        $this->assertStringContainsString('Alpha', $csv);
        $this->assertStringNotContainsString('hidden-note', $csv);
        $this->assertStringNotContainsString('beta-only', $csv);
        $this->assertStringNotContainsString('workflow_submission_snapshots', $csv);
    }

    public function test_dates_are_bounded_and_aggregates_do_not_grow_with_rows(): void
    {
        [$organization, $workflow, $employee, $manager] = $this->approvalWorkspace();
        $this->getJson($this->analytics($organization).'?from=2026-10-10&to=2026-10-01')->assertUnprocessable();
        $this->getJson($this->analytics($organization).'?from=2024-01-01&to=2026-01-01')->assertUnprocessable();
        $this->getJson("/api/v1/organizations/{$organization->uuid}/audit?per_page=101&from=2026-10-01&to=2026-10-31")->assertUnprocessable();

        $this->finish($organization, $workflow, $employee, $manager, 'first', '2026-10-02 00:00:00', '2026-10-02 01:00:00');
        $this->signIn($this->admin($organization));
        $before = $this->capture(fn () => $this->getJson($this->analytics($organization).'?from=2026-10-01&to=2026-10-31')->assertOk());
        $this->finish($organization, $workflow, $employee, $manager, 'second', '2026-10-03 00:00:00', '2026-10-03 02:00:00');
        $this->finish($organization, $workflow, $employee, $manager, 'third', '2026-10-04 00:00:00', '2026-10-04 03:00:00');
        $this->signIn($this->admin($organization));
        $after = $this->capture(fn () => $this->getJson($this->analytics($organization).'?from=2026-10-01&to=2026-10-31')->assertOk());

        $this->assertSame(count($before), count($after));
        $joined = strtolower(implode("\n", $after));
        $this->assertStringContainsString('row_number', $joined);
        $this->assertStringContainsString('group by', $joined);
        $this->assertStringNotContainsString('workflow_submission_snapshots', $joined);
        $this->assertStringNotContainsString('submitted_data', $joined);
        $this->assertStringNotContainsString('json_each', $joined);

        $this->signIn($employee);
        $employeeSql = strtolower(implode("\n", $this->capture(
            fn () => $this->getJson($this->analytics($organization).'?from=2026-10-01&to=2026-10-31')->assertOk(),
        )));
        $this->assertStringContainsString('json_each', $employeeSql);
    }

    public function test_audit_is_scoped_and_hides_submission_values(): void
    {
        [$organization, $workflow, $employee, $manager] = $this->approvalWorkspace();
        $this->finish($organization, $workflow, $employee, $manager, 'audit-secret', '2026-10-09 00:00:00', '2026-10-09 01:00:00');
        $membership = OrganizationMembership::query()->where('user_id', $manager->id)->where('organization_id', $organization->id)->firstOrFail();
        $instance = WorkflowInstance::query()->firstOrFail();

        $this->signIn($this->admin($organization));
        $audit = $this->getJson($this->audit($organization).'?from=2026-10-01&to=2026-10-31&actor='.$membership->uuid.'&instance='.$instance->uuid.'&event_type=task_approved')
            ->assertOk();
        $audit->assertJsonPath('data.scope', 'organization')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.event_type', 'task_approved')
            ->assertJsonPath('data.items.0.payload.decision', 'approved');
        $this->assertStringNotContainsString('audit-secret', $audit->getContent());

        $this->signIn($employee);
        $this->getJson($this->audit($organization).'?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('data.scope', 'visible_requests')
            ->assertJsonPath('data.scope_label', 'Limited to requests you can view');

        $export = $this->get($this->audit($organization).'/export?from=2026-10-01&to=2026-10-31');
        $export->assertOk();
        $this->assertStringNotContainsString('audit-secret', $export->streamedContent());
        $this->assertStringContainsString('limit 2001', strtolower(implode("\n", $this->capture(
            fn () => $this->get($this->audit($organization).'/export?from=2026-10-01&to=2026-10-31')->assertOk(),
        ))));
    }

    public function test_decision_snapshots_keep_the_reviewed_submission(): void
    {
        [$organization, $workflow, $employee, $manager] = $this->approvalWorkspace();
        Carbon::setTestNow('2026-10-11 00:00:00');
        $this->signIn($employee);
        $started = $this->startInstance($organization, $workflow, ['note' => 'first-secret'])->assertCreated();
        $task = $started->json('data.tasks.0.uuid');
        $this->signIn($manager);
        $rejected = $this->decide($organization, $task, 'rejected')->assertOk();
        $revision = collect($rejected->json('data.tasks'))->firstWhere('task_type', 'revision')['uuid'];
        $this->signIn($employee);
        $revised = $this->revise($organization, $revision, ['note' => 'second-secret'])->assertOk();
        $next = collect($revised->json('data.tasks'))->first(fn (array $row): bool => $row['status'] === 'open')['uuid'];
        $this->signIn($manager);
        $approved = $this->decide($organization, $next, 'approved')->assertOk();

        $tasks = collect($approved->json('data.tasks'));
        $this->assertSame('first-secret', $tasks->firstWhere('decision', 'rejected')['decision_snapshot']['submitted_data']['note']);
        $this->assertSame('second-secret', $tasks->firstWhere('decision', 'approved')['decision_snapshot']['submitted_data']['note']);
        $this->assertSame('second-secret', $approved->json('data.submitted_data.note'));
        $this->assertStringNotContainsString('first-secret', json_encode($approved->json('data.events')));

        $this->assertSame(1, WorkflowSubmissionSnapshot::query()->where('reason', SnapshotReason::Initial)->count());
        $this->assertSame(2, WorkflowSubmissionSnapshot::query()->where('reason', SnapshotReason::ApprovalDecision)->count());
        $this->assertSame(1, WorkflowSubmissionSnapshot::query()->where('reason', SnapshotReason::RevisionSubmitted)->count());

        DB::table('workflow_submission_snapshots')->where('reason', SnapshotReason::ApprovalDecision->value)->delete();
        $cleared = $this->getJson("/api/v1/organizations/{$organization->uuid}/instances/".$started->json('data.uuid'))->assertOk();
        $this->assertNull(collect($cleared->json('data.tasks'))->firstWhere('decision', 'approved')['decision_snapshot']);
        $this->assertSame('second-secret', $cleared->json('data.submitted_data.note'));

        $snapshot = WorkflowSubmissionSnapshot::query()->firstOrFail();
        try {
            $snapshot->update(['submitted_data' => ['note' => 'changed']]);
            $this->fail('A snapshot was updated.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        try {
            WorkflowEvent::query()->firstOrFail()->delete();
            $this->fail('An event was deleted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $listed = $this->getJson("/api/v1/organizations/{$organization->uuid}/instances")->assertOk();
        $this->assertStringNotContainsString('decision_snapshot', $listed->getContent());
        $this->assertStringNotContainsString('first-secret', $listed->getContent());
        $inbox = $this->getJson("/api/v1/organizations/{$organization->uuid}/tasks?status=completed")->assertOk();
        $this->assertStringNotContainsString('decision_snapshot', $inbox->getContent());
    }

    public function test_csv_cells_neutralize_formulas_and_audit_payloads_drop_values(): void
    {
        $this->assertSame("'=1+1", CsvCell::text('=1+1'));
        $this->assertSame([
            'decision' => 'approved',
            'changed_field_ids' => ['note'],
            'rules' => [['field_id' => 'note', 'operator' => 'equals', 'matched' => true]],
        ], \App\Services\Analytics\AuditExplorer::payload([
            'decision' => 'approved',
            'changed_field_ids' => ['note'],
            'submitted_data' => ['note' => 'secret'],
            'rules' => [['field_id' => 'note', 'operator' => 'equals', 'matched' => true, 'value' => 'secret']],
        ]));
    }

    /**
     * @return array{0: Organization, 1: string, 2: User, 3: User}
     */
    private function approvalWorkspace(string $name = 'Alpha'): array
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $workflow = $this->publishWorkflow($organization, ExecutionGraphs::approval(fields: [$this->noteField()]));
        $this->patchJson("/api/v1/organizations/{$organization->uuid}/workflows/{$workflow}", [
            'name' => $name,
            'description' => 'Routes a purchase for approval.',
        ])->assertOk();
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $manager = $this->addMember($organization, OrganizationRole::Manager);

        return [$organization, $workflow, $employee, $manager];
    }

    private function finish(
        Organization $organization,
        string $workflow,
        User $employee,
        User $manager,
        string $note,
        string $start,
        string $end,
    ): void {
        Carbon::setTestNow($start);
        $this->signIn($employee);
        $started = $this->startInstance($organization, $workflow, ['note' => $note])->assertCreated();
        Carbon::setTestNow($end);
        $this->signIn($manager);
        $this->decide($organization, $started->json('data.tasks.0.uuid'), 'approved')->assertOk();
    }

    /**
     * @param  callable(): mixed  $callback
     * @return list<string>
     */
    private function capture(callable $callback): array
    {
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });
        $callback();

        return $sql;
    }

    private function analytics(Organization $organization): string
    {
        return "/api/v1/organizations/{$organization->uuid}/analytics";
    }

    private function audit(Organization $organization): string
    {
        return "/api/v1/organizations/{$organization->uuid}/audit";
    }

    /**
     * @return array<string, mixed>
     */
    private function noteField(): array
    {
        return ExecutionGraphs::field('note', 'text', [
            'required' => true,
            'validation' => ['min_length' => 1, 'max_length' => 100],
        ]);
    }

    private function admin(Organization $organization): User
    {
        return User::query()->findOrFail($organization->created_by);
    }
}
