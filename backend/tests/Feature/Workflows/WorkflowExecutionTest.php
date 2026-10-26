<?php

namespace Tests\Feature\Workflows;

use App\Enums\OrganizationRole;
use App\Enums\WorkflowInstanceStatus;
use App\Enums\WorkflowTaskDecision;
use App\Enums\WorkflowTaskStatus;
use App\Exceptions\WorkflowVersionImmutableException;
use App\Models\IdempotencyRecord;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Models\WorkflowVersion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\ExecutionGraphs;
use Tests\TestCase;

class WorkflowExecutionTest extends TestCase
{
    use InteractsWithExecution, RefreshDatabase;

    public function test_a_published_request_can_be_approved_and_recorded(): void
    {
        [$organization, $workflow, $versionUuid, $hash] = $this->publishedApproval();
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $manager = $this->addMember($organization, OrganizationRole::Manager);
        $this->signIn($employee);

        $started = $this->startInstance($organization, $workflow, ['note' => 'private-note-value'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_approval')
            ->assertJsonPath('data.workflow_version.uuid', $versionUuid)
            ->assertJsonPath('data.submitted_data.note', 'private-note-value');

        $instance = $started->json('data.uuid');
        $task = $started->json('data.tasks.0.uuid');
        $this->assertSame(['instance_started', 'task_created'], $this->eventTypes($started->json('data.events')));
        $this->assertPayloadsOmit($started->json('data.events'), 'private-note-value');

        $this->signIn($manager);
        $this->getJson("/api/v1/organizations/{$organization->uuid}/tasks")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.uuid', $task);
        $approved = $this->decide($organization, $task, 'approved')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.workflow_version.uuid', $versionUuid);
        $this->assertSame(
            ['instance_started', 'task_created', 'task_approved', 'instance_completed'],
            $this->eventTypes($approved->json('data.events')),
        );
        $this->assertPayloadsOmit($approved->json('data.events'), 'private-note-value');
        $this->assertSame(1, WorkflowTask::query()->where('status', WorkflowTaskStatus::Completed)->count());
        $this->assertSame($hash, WorkflowVersion::query()->value('content_hash'));
        $this->assertSame(0, WorkflowTask::query()->where('status', WorkflowTaskStatus::Open)->count());

        try {
            WorkflowVersion::query()->firstOrFail()->update(['graph_definition' => ['nodes' => [], 'edges' => []]]);
            $this->fail('Execution was able to change a published version.');
        } catch (WorkflowVersionImmutableException) {
            $this->assertSame($hash, WorkflowVersion::query()->value('content_hash'));
        }
    }

    public function test_rejection_returns_to_approval_on_a_new_task_and_can_exhaust(): void
    {
        [$organization, $workflow, $versionUuid] = $this->publishedApproval(maxReturns: 1);
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $manager = $this->addMember($organization, OrganizationRole::Manager);
        $this->signIn($employee);
        $started = $this->startInstance($organization, $workflow, ['note' => 'first'])->assertCreated();
        $instance = $started->json('data.uuid');
        $firstTask = $started->json('data.tasks.0.uuid');

        $this->signIn($manager);
        $this->decide($organization, $firstTask, 'rejected')->assertOk()->assertJsonPath('data.status', 'awaiting_revision');
        $this->getJson("/api/v1/organizations/{$organization->uuid}/tasks")->assertOk()->assertJsonPath('data.total', 0);

        $this->signIn($employee);
        $revision = $this->getJson("/api/v1/organizations/{$organization->uuid}/tasks")->assertOk()->json('data.items.0.uuid');
        $returned = $this->revise($organization, $revision, ['note' => 'corrected'])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_approval')
            ->assertJsonPath('data.workflow_version.uuid', $versionUuid)
            ->assertJsonPath('data.submitted_data.note', 'corrected');
        $tasks = collect($returned->json('data.tasks'));
        $this->assertCount(2, $tasks->where('task_type', 'approval'));
        $this->assertSame('rejected', $tasks->firstWhere('uuid', $firstTask)['decision']);
        $this->assertSame('completed', $tasks->firstWhere('uuid', $firstTask)['status']);
        $secondTask = $tasks->firstWhere('status', 'open')['uuid'];
        $this->assertNotSame($firstTask, $secondTask);
        $this->assertContains('revision_requested', $this->eventTypes($returned->json('data.events')));
        $this->assertContains('revision_submitted', $this->eventTypes($returned->json('data.events')));
        $submitted = collect($returned->json('data.events'))->firstWhere('event_type', 'revision_submitted');
        $this->assertSame(['note'], $submitted['payload']['changed_field_ids']);
        $this->assertPayloadsOmit($returned->json('data.events'), 'corrected');

        $this->signIn($manager);
        $exhausted = $this->decide($organization, $secondTask, 'rejected')->assertOk();
        $this->assertSame('rejected', $exhausted->json('data.status'));
        $this->assertContains('revision_exhausted', $this->eventTypes($exhausted->json('data.events')));
        $this->assertSame(1, (int) DB::table('workflow_revision_counters')->value('attempt_count'));
        $this->assertSame($versionUuid, $exhausted->json('data.workflow_version.uuid'));
        $this->getJson("/api/v1/organizations/{$organization->uuid}/instances/{$instance}")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_instances_stay_pinned_when_the_workflow_changes_or_is_archived(): void
    {
        [$organization, $workflow, $versionUuid, $hash] = $this->publishedApproval();
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $manager = $this->addMember($organization, OrganizationRole::Manager);
        $this->signIn($employee);
        $task = $this->startInstance($organization, $workflow, ['note' => 'kept'])->assertCreated()->json('data.tasks.0.uuid');

        $this->signIn($this->admin($organization));
        $definition = ExecutionGraphs::approval(fields: [$this->noteField()]);
        $definition['graph_definition']['nodes'][1]['data']['label'] = 'Finance approval';
        $this->putJson("/api/v1/organizations/{$organization->uuid}/workflows/{$workflow}/draft", [
            'revision' => 3,
            ...$definition,
        ])->assertOk();
        $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows/{$workflow}/publish", [
            'revision' => 4,
        ])->assertOk()->assertJsonPath('data.current_version_number', 2);
        $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows/{$workflow}/archive")->assertOk();

        $this->signIn($employee);
        $this->startInstance($organization, $workflow, ['note' => 'new'])->assertStatus(409);
        $this->signIn($manager);
        $this->decide($organization, $task, 'approved')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.workflow_version.uuid', $versionUuid);
        $this->assertSame($hash, WorkflowVersion::query()->where('uuid', $versionUuid)->value('content_hash'));
    }

    public function test_drafts_archived_workflows_invalid_forms_and_files_do_not_start(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $draft = $this->createWorkflow($organization);
        $this->startInstance($organization, $draft)->assertStatus(409);
        $workflow = $this->publishWorkflow($organization, ExecutionGraphs::approval(fields: [
            ExecutionGraphs::field('receipt', 'file'),
        ]));
        $this->startInstance($organization, $workflow)->assertStatus(422)->assertJsonValidationErrors('data');
        $this->assertSame(0, WorkflowInstance::query()->count());

        $workflow = $this->publishWorkflow($organization, ExecutionGraphs::approval(fields: [$this->noteField()]));
        $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows/{$workflow}/archive")->assertOk();
        $this->startInstance($organization, $workflow, ['note' => 'late'])->assertStatus(409);
        $this->startInstance($organization, $workflow, ['note' => 12], (string) Str::uuid())->assertStatus(409);
    }

    public function test_conditions_route_published_instances(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $amount = ExecutionGraphs::field('amount', 'number', ['validation' => ['min' => 0, 'max' => 100000]]);
        $workflow = $this->publishWorkflow($organization, ExecutionGraphs::condition([
            ExecutionGraphs::rule('amount', 'greater_than', 1000),
        ], fields: [$amount]));

        $this->startInstance($organization, $workflow, ['amount' => 1500])
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed');
        $low = $this->startInstance($organization, $workflow, ['amount' => 20])->assertCreated();
        $this->assertSame('rejected', $low->json('data.status'));
        $this->assertFalse(collect($low->json('data.events'))->firstWhere('event_type', 'condition_evaluated')['payload']['outcome']);
        $this->startInstance($organization, $workflow, ['amount' => '20'])->assertStatus(422);
        $this->assertSame(2, WorkflowInstance::query()->count());
    }

    public function test_unsupported_graphs_fail_closed_and_cannot_be_retried(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $workflow = $this->publishWorkflow($organization, ExecutionGraphs::condition([
            ExecutionGraphs::rule('amount', 'greater_than', 1),
        ], fields: [ExecutionGraphs::field('amount', 'number')]));
        $version = WorkflowVersion::query()->firstOrFail();
        $graph = $version->graph_definition;
        $graph['nodes'][1]['data']['rules'][0]['operator'] = 'contains';
        DB::table('workflow_versions')->where('id', $version->id)->update([
            'graph_definition' => json_encode($graph),
        ]);

        $failed = $this->startInstance($organization, $workflow, ['amount' => 5])
            ->assertCreated()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure_code', 'unknown_operator')
            ->assertJsonPath('data.retryable', false);
        $this->postJson("/api/v1/organizations/{$organization->uuid}/instances/{$failed->json('data.uuid')}/retry", [], [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertStatus(409);
        $this->assertSame('contains', WorkflowVersion::query()->findOrFail($version->id)->graph_definition['nodes'][1]['data']['rules'][0]['operator']);
    }

    public function test_no_eligible_approver_can_be_recovered_by_an_admin(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $submitter = $this->addMember($organization, OrganizationRole::Manager);
        $workflow = $this->publishWorkflow($organization, ExecutionGraphs::approval(['manager'], fields: [$this->noteField()]));
        $this->signIn($submitter);
        $failed = $this->startInstance($organization, $workflow, ['note' => 'solo'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure_code', 'no_eligible_approver')
            ->assertJsonPath('data.retryable', true);
        $this->postJson("/api/v1/organizations/{$organization->uuid}/instances/{$failed->json('data.uuid')}/retry", [], [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertForbidden();

        $this->signIn($this->admin($organization));
        $this->postJson("/api/v1/organizations/{$organization->uuid}/instances/{$failed->json('data.uuid')}/retry", [], [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertStatus(409);
        $this->assertSame(0, WorkflowTask::query()->count());
        $this->addMember($organization, OrganizationRole::Manager);
        $this->postJson("/api/v1/organizations/{$organization->uuid}/instances/{$failed->json('data.uuid')}/retry", [], [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.status', 'awaiting_approval')->assertJsonPath('data.retryable', false);
        $this->assertSame(1, WorkflowTask::query()->where('status', WorkflowTaskStatus::Open)->count());
    }

    public function test_authorization_isolation_and_historical_task_locks(): void
    {
        [$organization, $workflow] = $this->publishedApproval();
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $manager = $this->addMember($organization, OrganizationRole::Manager);
        $otherManager = $this->addMember($organization, OrganizationRole::Manager);
        $removed = $this->addMember($organization, OrganizationRole::Manager);
        $this->signIn($manager);
        $own = $this->startInstance($organization, $workflow, ['note' => 'own request'])->assertCreated();
        $this->decide($organization, $own->json('data.tasks.0.uuid'), 'approved')->assertForbidden();

        $this->signIn($employee);
        $started = $this->startInstance($organization, $workflow, ['note' => 'mine'])->assertCreated();
        $instance = $started->json('data.uuid');
        $task = $started->json('data.tasks.0.uuid');
        $this->getJson("/api/v1/organizations/{$organization->uuid}/instances")
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->signIn($otherManager);
        OrganizationMembership::query()->where('user_id', $otherManager->id)->update(['role' => OrganizationRole::Employee]);
        $this->decide($organization, $task, 'approved')->assertNotFound();
        OrganizationMembership::query()->where('user_id', $removed->id)->update(['revoked_at' => now()]);
        $this->signIn($removed);
        $this->decide($organization, $task, 'approved')->assertNotFound();

        $outsider = $this->signInMember(OrganizationRole::Admin);
        $this->getJson("/api/v1/organizations/{$organization->uuid}/instances/{$instance}")->assertNotFound();
        $this->getJson("/api/v1/organizations/{$outsider->uuid}/instances/{$instance}")->assertNotFound();
        $this->getJson("/api/v1/organizations/{$outsider->uuid}/tasks/{$task}")->assertNotFound();

        $this->signIn($manager);
        $this->decide($organization, $task, 'approved')->assertOk();
        $completed = WorkflowTask::query()->where('uuid', $task)->firstOrFail();
        $this->expectException(RuntimeException::class);
        $completed->forceFill(['decision' => WorkflowTaskDecision::Rejected])->save();
    }

    public function test_idempotency_is_transactional_and_replays_the_original_result(): void
    {
        [$organization, $workflow] = $this->publishedApproval();
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $other = $this->addMember($organization, OrganizationRole::Employee);
        $this->signIn($employee);
        $key = (string) Str::uuid();

        $this->startInstance($organization, $workflow, ['note' => ''])->assertStatus(422);
        $this->assertSame(0, IdempotencyRecord::query()->count());
        $created = $this->startInstance($organization, $workflow, ['note' => 'once'], $key)->assertCreated();
        $again = $this->startInstance($organization, $workflow, ['note' => 'once'], $key)->assertCreated();
        $this->assertSame($created->json('data.uuid'), $again->json('data.uuid'));
        $this->startInstance($organization, $workflow, ['note' => 'different'], $key)->assertStatus(409);
        $this->assertSame(1, WorkflowInstance::query()->count());

        $this->signIn($other);
        $this->startInstance($organization, $workflow, ['note' => 'once'], $key)->assertCreated();
        $this->assertSame(2, WorkflowInstance::query()->count());
        $this->assertSame(2, IdempotencyRecord::query()->count());
    }

    public function test_duplicate_decisions_conflict_and_database_guards_hold(): void
    {
        [$organization, $workflow] = $this->publishedApproval();
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $manager = $this->addMember($organization, OrganizationRole::Manager);
        $this->signIn($employee);
        $started = $this->startInstance($organization, $workflow, ['note' => 'pending'])->assertCreated();
        $task = $started->json('data.tasks.0.uuid');
        $this->signIn($manager);

        try {
            WorkflowTask::query()->create([
                'organization_id' => $organization->id,
                'workflow_instance_id' => WorkflowInstance::query()->where('uuid', $started->json('data.uuid'))->value('id'),
                'node_id' => $started->json('data.tasks.0.node_id'),
                'task_type' => 'approval',
                'assigned_role' => 'manager',
                'status' => WorkflowTaskStatus::Open,
                'assigned_at' => now(),
            ]);
            $this->fail('A second open task was inserted.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, WorkflowTask::query()->count());
        }

        $key = (string) Str::uuid();
        $this->decide($organization, $task, 'approved', $key)->assertOk();
        $this->decide($organization, $task, 'approved', $key)->assertOk();
        $this->decide($organization, $task, 'rejected', $key)->assertStatus(409);
        $this->decide($organization, $task, 'approved')->assertStatus(409);
        $this->assertSame(1, WorkflowTask::query()->count());
        $this->assertSame(WorkflowInstanceStatus::Completed, WorkflowInstance::query()->firstOrFail()->status);

        $this->expectException(RuntimeException::class);
        WorkflowEvent::query()->firstOrFail()->update(['event_payload' => ['tampered' => true]]);
    }

    /**
     * @return array{0: Organization, 1: string, 2?: string, 3?: string}
     */
    private function publishedApproval(int $maxReturns = 3): array
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $definition = ExecutionGraphs::approval(maxReturns: $maxReturns, fields: [$this->noteField()]);
        $workflow = $this->publishWorkflow($organization, $definition);
        $published = $this->getJson("/api/v1/organizations/{$organization->uuid}/workflows/{$workflow}")->assertOk();

        return [
            $organization,
            $workflow,
            $published->json('data.published.uuid'),
            WorkflowVersion::query()->value('content_hash'),
        ];
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

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<string>
     */
    private function eventTypes(array $events): array
    {
        return array_column($events, 'event_type');
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function assertPayloadsOmit(array $events, string $secret): void
    {
        foreach ($events as $event) {
            $this->assertStringNotContainsString($secret, (string) json_encode($event['payload']));
        }
    }
}
