<?php

namespace Tests\Feature;

use App\Enums\NotificationOutboxStatus;
use App\Enums\OrganizationRole;
use App\Jobs\ProcessNotificationOutbox;
use App\Models\NotificationOutbox;
use App\Models\OrganizationMembership;
use App\Models\WorkflowInstance;
use App\Services\Workflows\Execution\WorkflowEventWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Workflows\InteractsWithExecution;
use Tests\Support\AutomationGraphs;
use Tests\TestCase;

class Phase85NotificationReliabilityTest extends TestCase
{
    use InteractsWithExecution, RefreshDatabase;

    public function test_notifications_are_scoped_to_the_recipient_and_active_workspace_including_legacy_payloads(): void
    {
        Mail::fake();
        $organization = $this->signInMember(OrganizationRole::Admin);
        $workflow = $this->publishWorkflow($organization, AutomationGraphs::notification());
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $this->signIn($employee);
        $this->startInstance($organization, $workflow)->assertCreated();
        $row = NotificationOutbox::query()->where('recipient_user_id', $employee->id)->where('channel', 'in_app')->firstOrFail();
        (new ProcessNotificationOutbox($row->workflow_instance_id, $row->node_id))->handle(app(WorkflowEventWriter::class));
        $notification = $employee->notifications()->firstOrFail();
        $notification->update(['data' => ['instance_uuid' => WorkflowInstance::findOrFail($row->workflow_instance_id)->uuid]]);
        $base = "/api/v1/organizations/{$organization->uuid}/notifications";
        $this->getJson($base)->assertOk()->assertJsonCount(1, 'data.items');
        $this->postJson("{$base}/{$notification->id}/read")->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);
        $this->getJson("{$base}?status=read")->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson("{$base}?status=unread")->assertOk()->assertJsonCount(0, 'data.items');
        for ($index = 0; $index < 50; $index++) {
            $employee->notifications()->create([
                'id' => (string) Str::uuid(), 'type' => $notification->type,
                'data' => ['instance_uuid' => WorkflowInstance::findOrFail($row->workflow_instance_id)->uuid], 'read_at' => null,
            ]);
        }
        $this->getJson($base)->assertOk()->assertJsonCount(50, 'data.items')->assertJsonPath('data.total', 51);
        $this->getJson("{$base}?page=2")->assertOk()->assertJsonCount(1, 'data.items');

        $other = $this->signInMember(OrganizationRole::Admin);
        $this->getJson($base)->assertNotFound();
        $this->postJson("{$base}/{$notification->id}/read")->assertNotFound();
        $this->signIn($employee);
        $membership = OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $employee->id)->firstOrFail();
        OrganizationMembership::factory()->create(['organization_id' => $other->id, 'user_id' => $employee->id, 'role' => OrganizationRole::Employee]);
        $this->getJson("/api/v1/organizations/{$other->uuid}/notifications")->assertOk()->assertJsonCount(0, 'data.items');
        $this->postJson("/api/v1/organizations/{$other->uuid}/notifications/{$notification->id}/read")->assertNotFound();
        $membership->forceFill(['revoked_at' => now()])->save();
        $this->getJson($base)->assertNotFound();
        $employee->forceFill(['current_organization_id' => $organization->id])->save();
        $this->signIn($employee->fresh());
        $this->getJson('/api/v1/notifications')->assertNotFound();
    }

    public function test_expired_claim_recovers_without_duplicating_in_app_delivery_or_business_events(): void
    {
        Mail::fake();
        Queue::fake();
        $organization = $this->signInMember(OrganizationRole::Admin);
        $workflow = $this->publishWorkflow($organization, AutomationGraphs::notification());
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $this->signIn($employee);
        $this->startInstance($organization, $workflow)->assertCreated();
        $row = NotificationOutbox::query()->where('recipient_user_id', $employee->id)->where('channel', 'in_app')->firstOrFail();
        (new ProcessNotificationOutbox($row->workflow_instance_id, $row->node_id))->handle(app(WorkflowEventWriter::class));
        $this->assertSame(1, $employee->notifications()->count());
        $events = $row->instance->events()->count();
        $row->refresh()->forceFill(['status' => NotificationOutboxStatus::Processing, 'updated_at' => now()->subMinutes(6)])->save(['timestamps' => false]);
        $this->artisan('workflow:recover-notifications')->assertSuccessful();
        Queue::assertPushed(ProcessNotificationOutbox::class);
        (new ProcessNotificationOutbox($row->workflow_instance_id, $row->node_id))->handle(app(WorkflowEventWriter::class));
        $this->assertSame(NotificationOutboxStatus::Sent, $row->fresh()->status);
        $this->assertSame(1, $employee->notifications()->count());
        $this->assertSame($events + 1, $row->instance->events()->count());
        (new ProcessNotificationOutbox($row->workflow_instance_id, $row->node_id))->handle(app(WorkflowEventWriter::class));
        $this->assertSame($events + 1, $row->instance->events()->count());
    }

    public function test_delivery_is_refused_after_membership_revocation_and_exhausted_claims_fail(): void
    {
        Mail::fake();
        Queue::fake();
        $organization = $this->signInMember(OrganizationRole::Admin);
        $workflow = $this->publishWorkflow($organization, AutomationGraphs::notification());
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $this->signIn($employee);
        $this->startInstance($organization, $workflow)->assertCreated();
        OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $employee->id)->update(['revoked_at' => now()]);
        $row = NotificationOutbox::query()->where('recipient_user_id', $employee->id)->firstOrFail();
        (new ProcessNotificationOutbox($row->workflow_instance_id, $row->node_id))->handle(app(WorkflowEventWriter::class));
        $this->assertSame(NotificationOutboxStatus::Failed, $row->fresh()->status);
        $this->assertSame(0, $employee->notifications()->count());
        Mail::assertNothingSent();
        $row->forceFill(['status' => NotificationOutboxStatus::Processing, 'attempt_count' => $row->max_attempts, 'updated_at' => now()->subMinutes(6)])->save(['timestamps' => false]);
        $this->artisan('workflow:recover-notifications')->assertSuccessful();
        (new ProcessNotificationOutbox($row->workflow_instance_id, $row->node_id))->handle(app(WorkflowEventWriter::class));
        $this->assertSame(NotificationOutboxStatus::Failed, $row->fresh()->status);
    }
}
