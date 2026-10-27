<?php

namespace Tests\Feature\Workflows;

use App\Actions\WorkflowInstances\ResumeDelayedWorkflowInstance;
use App\Enums\NotificationOutboxStatus;
use App\Enums\OrganizationRole;
use App\Enums\WorkflowDelayStatus;
use App\Enums\WorkflowInstanceStatus;
use App\Jobs\ProcessNotificationOutbox;
use App\Models\NotificationOutbox;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WorkflowDelay;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Models\WorkflowVersion;
use App\Services\Workflows\Nodes\NodeRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\AutomationGraphs;
use Tests\Support\ExecutionGraphs;
use Tests\TestCase;

class Phase7AutomationNodesTest extends TestCase
{
    use InteractsWithExecution, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_v1_published_graphs_keep_executing_without_reinterpretation(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $workflow = $this->publishWorkflow($organization, ExecutionGraphs::approval());
        $version = WorkflowVersion::query()->firstOrFail();
        DB::table('workflow_versions')->where('id', $version->id)->update([
            'node_registry_version' => NodeRegistry::V1,
        ]);
        $this->assertSame(NodeRegistry::V1, (int) $version->fresh()->node_registry_version);

        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $manager = $this->addMember($organization, OrganizationRole::Manager);
        $this->signIn($employee);
        $started = $this->startInstance($organization, $workflow)->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_approval');
        $this->signIn($manager);
        $this->decide($organization, $started->json('data.tasks.0.uuid'), 'approved')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_new_publishes_stamp_registry_v2(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $this->publishWorkflow($organization, AutomationGraphs::delay());
        $this->assertSame(NodeRegistry::V2, (int) WorkflowVersion::query()->value('node_registry_version'));
    }

    public function test_notification_queues_per_recipient_and_does_not_duplicate_on_idempotent_replay(): void
    {
        Mail::fake();
        $organization = $this->signInMember(OrganizationRole::Admin);
        $workflow = $this->publishWorkflow($organization, AutomationGraphs::notification());
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $this->addMember($organization, OrganizationRole::Manager);
        $this->signIn($employee);
        $key = (string) Str::uuid();
        $first = $this->startInstance($organization, $workflow, [], $key)->assertCreated()
            ->assertJsonPath('data.status', 'completed');
        $second = $this->startInstance($organization, $workflow, [], $key)->assertSuccessful();
        $this->assertSame($first->json('data.uuid'), $second->json('data.uuid'));

        $rows = NotificationOutbox::query()->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(2, $rows->count());
        $this->assertSame($rows->count(), $rows->unique('delivery_key')->count());
        $this->assertTrue($rows->every(fn (NotificationOutbox $row) => $row->status === NotificationOutboxStatus::Sent
            || $row->status === NotificationOutboxStatus::Pending
            || $row->status === NotificationOutboxStatus::Processing));

        ProcessNotificationOutbox::dispatchSync($rows->first()->workflow_instance_id, $rows->first()->node_id);
        $after = NotificationOutbox::query()->count();
        $this->assertSame($rows->count(), $after);
    }

    public function test_notification_config_failure_is_recoverable_and_delivery_failure_does_not_rewind(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $workflow = $this->publishWorkflow($organization, AutomationGraphs::notification([
            'recipient_roles' => ['manager'],
            'include_submitter' => false,
        ]));
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $this->signIn($employee);
        $failed = $this->startInstance($organization, $workflow)->assertCreated()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure_code', 'no_notification_recipients')
            ->assertJsonPath('data.retryable', true);

        $manager = $this->addMember($organization, OrganizationRole::Manager);
        $this->signIn($this->admin($organization));
        $this->postJson(
            "/api/v1/organizations/{$organization->uuid}/instances/{$failed->json('data.uuid')}/retry",
            [],
            ['Idempotency-Key' => (string) Str::uuid()],
        )->assertOk()->assertJsonPath('data.status', 'completed');

        $instance = WorkflowInstance::query()->firstOrFail();
        $this->assertSame(WorkflowInstanceStatus::Completed, $instance->status);
        $email = NotificationOutbox::query()->where('channel', 'email')->firstOrFail();
        $email->forceFill([
            'status' => NotificationOutboxStatus::Pending,
            'attempt_count' => $email->max_attempts - 1,
            'available_at' => now(),
            'last_error' => null,
            'sent_at' => null,
            'failed_at' => null,
        ])->save();

        Mail::swap(new class
        {
            public function to(mixed $users): never
            {
                throw new \RuntimeException('provider down');
            }
        });
        ProcessNotificationOutbox::dispatchSync($instance->id, $email->node_id);
        $instance->refresh();
        $this->assertSame(WorkflowInstanceStatus::Completed, $instance->status);
        $this->assertSame(NotificationOutboxStatus::Failed, $email->fresh()->status);
        $this->assertNotNull($manager->id);
    }

    public function test_assignment_pauses_until_completed_and_revalidates_membership(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $workflow = $this->publishWorkflow($organization, AutomationGraphs::assignment());
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $manager = $this->addMember($organization, OrganizationRole::Manager);
        $this->signIn($employee);
        $started = $this->startInstance($organization, $workflow)->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_assignment');
        $task = $started->json('data.tasks.0.uuid');

        $this->signIn($employee);
        $this->postJson(
            "/api/v1/organizations/{$organization->uuid}/tasks/{$task}/complete",
            [],
            ['Idempotency-Key' => (string) Str::uuid()],
        )->assertForbidden();

        OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $manager->id)
            ->update(['revoked_at' => now()]);

        $this->signIn($manager);
        $this->postJson(
            "/api/v1/organizations/{$organization->uuid}/tasks/{$task}/complete",
            [],
            ['Idempotency-Key' => (string) Str::uuid()],
        )->assertNotFound();

        OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $manager->id)
            ->update(['revoked_at' => null]);

        $this->signIn($manager);
        $this->postJson(
            "/api/v1/organizations/{$organization->uuid}/tasks/{$task}/complete",
            [],
            ['Idempotency-Key' => (string) Str::uuid()],
        )->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_assignment_instructions_come_from_the_pinned_version_after_a_draft_edit(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $definition = AutomationGraphs::assignment(['instructions' => 'Check the equipment inventory.']);
        $workflow = $this->publishWorkflow($organization, $definition);
        $manager = $this->addMember($organization, OrganizationRole::Manager);
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $this->signIn($employee);
        $task = $this->startInstance($organization, $workflow)->assertCreated()->json('data.tasks.0.uuid');
        $this->signIn($this->admin($organization));
        $definition['graph_definition']['nodes'][1]['data']['instructions'] = 'A newer draft instruction.';
        $this->putJson("/api/v1/organizations/{$organization->uuid}/workflows/{$workflow}/draft", [
            'revision' => 3,
            ...$definition,
        ])->assertOk();

        $this->signIn($manager);
        $this->getJson("/api/v1/organizations/{$organization->uuid}/tasks/{$task}")
            ->assertOk()
            ->assertJsonPath('data.instructions', 'Check the equipment inventory.');
    }

    public function test_assignment_reassignment_is_admin_only_and_audited(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $workflow = $this->publishWorkflow($organization, AutomationGraphs::assignment());
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $manager = $this->addMember($organization, OrganizationRole::Manager);
        $other = $this->addMember($organization, OrganizationRole::Manager);
        $this->signIn($employee);
        $started = $this->startInstance($organization, $workflow)->assertCreated();
        $task = $started->json('data.tasks.0.uuid');

        $this->signIn($manager);
        $this->getJson("/api/v1/organizations/{$organization->uuid}/tasks/{$task}")
            ->assertOk()->assertJsonPath('data.can_complete', true)->assertJsonPath('data.can_reassign', false);
        $this->postJson(
            "/api/v1/organizations/{$organization->uuid}/tasks/{$task}/reassign",
            ['assignee_user_id' => $other->id],
            ['Idempotency-Key' => (string) Str::uuid()],
        )->assertForbidden();

        $this->signIn($this->admin($organization));
        $this->getJson("/api/v1/organizations/{$organization->uuid}/tasks/{$task}")
            ->assertOk()->assertJsonPath('data.can_complete', false)->assertJsonPath('data.can_reassign', true);
        $this->postJson(
            "/api/v1/organizations/{$organization->uuid}/tasks/{$task}/reassign",
            ['assignee_user_id' => $other->id],
            ['Idempotency-Key' => (string) Str::uuid()],
        )->assertOk();

        $this->assertSame($other->id, WorkflowTask::query()->firstOrFail()->assigned_user_id);
        $this->signIn($other);
        $this->postJson(
            "/api/v1/organizations/{$organization->uuid}/tasks/{$task}/complete",
            [],
            ['Idempotency-Key' => (string) Str::uuid()],
        )->assertOk()->assertJsonPath('data.status', 'completed');

        $events = collect($this->getJson("/api/v1/organizations/{$organization->uuid}/instances/".$started->json('data.uuid'))
            ->assertOk()
            ->json('data.events'));
        $this->assertTrue($events->contains(fn (array $event) => $event['event_type'] === 'task_reassigned'));
    }

    public function test_delay_persists_resume_and_recovers_idempotently(): void
    {
        Carbon::setTestNow('2026-10-27 10:00:00');
        $organization = $this->signInMember(OrganizationRole::Admin);
        $workflow = $this->publishWorkflow($organization, AutomationGraphs::delay([
            'duration_seconds' => 120,
        ]));
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $this->signIn($employee);
        $started = $this->startInstance($organization, $workflow)->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_delay');

        $delay = WorkflowDelay::query()->firstOrFail();
        $this->assertSame(WorkflowDelayStatus::Pending, $delay->status);
        $this->assertTrue($delay->resume_at->equalTo(Carbon::parse('2026-10-27 10:02:00')));

        $resume = app(ResumeDelayedWorkflowInstance::class);
        $this->assertFalse($resume($delay->id));

        Carbon::setTestNow('2026-10-27 10:02:00');
        $this->assertTrue($resume($delay->id));
        $this->assertFalse($resume($delay->id));
        $this->assertSame(WorkflowDelayStatus::Consumed, $delay->fresh()->status);
        $this->assertSame('completed', $this->getJson(
            "/api/v1/organizations/{$organization->uuid}/instances/{$started->json('data.uuid')}",
        )->json('data.status'));
    }

    public function test_concurrent_delay_recovery_advances_once(): void
    {
        Carbon::setTestNow('2026-10-27 12:00:00');
        $organization = $this->signInMember(OrganizationRole::Admin);
        $workflow = $this->publishWorkflow($organization, AutomationGraphs::delay([
            'duration_seconds' => 1,
        ]));
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $this->signIn($employee);
        $this->startInstance($organization, $workflow)->assertCreated();
        Carbon::setTestNow('2026-10-27 12:00:02');
        $delayId = (int) WorkflowDelay::query()->value('id');
        $resume = app(ResumeDelayedWorkflowInstance::class);
        $results = [$resume($delayId), $resume($delayId), $resume($delayId)];
        $this->assertSame(1, collect($results)->filter()->count());
        $this->assertSame(1, WorkflowInstance::query()->where('status', WorkflowInstanceStatus::Completed)->count());
        $this->assertSame(1, WorkflowDelay::query()->where('status', WorkflowDelayStatus::Consumed)->count());
    }

    public function test_cross_organization_isolation_for_assignment_and_notifications(): void
    {
        $alpha = $this->signInMember(OrganizationRole::Admin);
        $alphaWorkflow = $this->publishWorkflow($alpha, AutomationGraphs::assignment());
        $alphaEmployee = $this->addMember($alpha, OrganizationRole::Employee);
        $alphaManager = $this->addMember($alpha, OrganizationRole::Manager);
        $this->signIn($alphaEmployee);
        $started = $this->startInstance($alpha, $alphaWorkflow)->assertCreated();
        $task = $started->json('data.tasks.0.uuid');

        $beta = $this->signInMember(OrganizationRole::Admin);
        $betaManager = $this->addMember($beta, OrganizationRole::Manager);
        $this->signIn($betaManager);
        $this->postJson(
            "/api/v1/organizations/{$alpha->uuid}/tasks/{$task}/complete",
            [],
            ['Idempotency-Key' => (string) Str::uuid()],
        )->assertNotFound();

        $this->signIn($alphaManager);
        $this->getJson('/api/v1/notifications')->assertOk();
        $this->assertSame(0, WorkflowTask::query()->where('organization_id', $beta->id)->count());
    }

    private function admin(Organization $organization): User
    {
        return User::query()->findOrFail($organization->created_by);
    }
}
