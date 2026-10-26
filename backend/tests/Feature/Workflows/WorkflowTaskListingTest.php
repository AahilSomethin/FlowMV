<?php

namespace Tests\Feature\Workflows;

use App\Enums\OrganizationRole;
use App\Enums\WorkflowTaskStatus;
use App\Enums\WorkflowTaskType;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\WorkflowTask;
use App\Services\Workflows\Execution\WorkflowInstanceVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ExecutionGraphs;
use Tests\TestCase;

class WorkflowTaskListingTest extends TestCase
{
    use InteractsWithExecution, RefreshDatabase;

    public function test_sql_task_lists_match_permission_checks_for_roles_history_and_organizations(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $admin = $this->admin($organization);
        $manager = $this->addMember($organization, OrganizationRole::Manager);
        $otherManager = $this->addMember($organization, OrganizationRole::Manager);
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $bystander = $this->addMember($organization, OrganizationRole::Employee);
        $revoked = $this->addMember($organization, OrganizationRole::Manager);

        $single = $this->publishNamed($organization, 'Alpha purchase', ExecutionGraphs::approval(['manager']));
        $multiple = $this->publishNamed($organization, 'Beta leave', ExecutionGraphs::approval(['manager', 'admin']));

        $this->signIn($employee);
        $alpha = $this->startInstance($organization, $single)->assertCreated();
        $this->assertSame('Manager approval', $alpha->json('data.current_node_label'));
        $this->assertSame('Manager approval', $alpha->json('data.tasks.0.node_label'));

        $this->signIn($otherManager);
        $beta = $this->startInstance($organization, $multiple)->assertCreated();
        $this->assertNull($beta->json('data.tasks.0.assigned_role'));

        $this->assertSame(
            $this->permittedTaskUuids($organization, $manager, WorkflowTaskStatus::Open),
            $this->listedTaskUuids($organization, $manager, 'open'),
        );
        $this->assertSame(
            $this->permittedTaskUuids($organization, $admin, WorkflowTaskStatus::Open),
            $this->listedTaskUuids($organization, $admin, 'open'),
        );
        $this->assertSame(
            $this->permittedTaskUuids($organization, $employee, WorkflowTaskStatus::Open),
            $this->listedTaskUuids($organization, $employee, 'open'),
        );
        $this->assertSame(
            $this->permittedTaskUuids($organization, $otherManager, WorkflowTaskStatus::Open),
            $this->listedTaskUuids($organization, $otherManager, 'open'),
        );
        $this->assertNotContains($beta->json('data.tasks.0.uuid'), $this->permittedTaskUuids($organization, $otherManager, WorkflowTaskStatus::Open));
        $this->assertContains($alpha->json('data.tasks.0.uuid'), $this->permittedTaskUuids($organization, $manager, WorkflowTaskStatus::Open));
        $this->assertContains($beta->json('data.tasks.0.uuid'), $this->permittedTaskUuids($organization, $admin, WorkflowTaskStatus::Open));
        $this->assertNotContains($alpha->json('data.tasks.0.uuid'), $this->permittedTaskUuids($organization, $employee, WorkflowTaskStatus::Open));

        $this->signInFresh($manager);
        $this->getJson("/api/v1/organizations/{$organization->uuid}/tasks?search=Alpha")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.uuid', $alpha->json('data.tasks.0.uuid'));
        $this->getJson("/api/v1/organizations/{$organization->uuid}/tasks?search=%")->assertOk()->assertJsonPath('data.total', 0);

        $this->decide($organization, $alpha->json('data.tasks.0.uuid'), 'approved')->assertOk();

        $this->assertSame(
            $this->permittedTaskUuids($organization, $manager, WorkflowTaskStatus::Completed),
            $this->listedTaskUuids($organization, $manager, 'completed'),
        );
        $this->assertSame(
            $this->permittedTaskUuids($organization, $employee, WorkflowTaskStatus::Completed),
            $this->listedTaskUuids($organization, $employee, 'completed'),
        );
        $this->assertSame(
            $this->permittedTaskUuids($organization, $bystander, WorkflowTaskStatus::Completed),
            $this->listedTaskUuids($organization, $bystander, 'completed'),
        );
        $completedForEmployee = $this->listedTaskUuids($organization, $employee, 'completed');
        $this->assertContains($alpha->json('data.tasks.0.uuid'), $completedForEmployee);
        $this->assertNotContains($alpha->json('data.tasks.0.uuid'), $this->permittedTaskUuids($organization, $bystander, WorkflowTaskStatus::Completed));
        $this->assertNotContains($alpha->json('data.tasks.0.uuid'), $this->permittedTaskUuids($organization, $manager, WorkflowTaskStatus::Open));

        $this->signInFresh($employee);
        $this->getJson("/api/v1/organizations/{$organization->uuid}/instances?status=completed")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.uuid', $alpha->json('data.uuid'));
        $this->getJson("/api/v1/organizations/{$organization->uuid}/instances?status=awaiting_approval")
            ->assertOk()
            ->assertJsonPath('data.total', 0);
        $this->getJson("/api/v1/organizations/{$organization->uuid}/instances?search=Beta")
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        $this->signInFresh($admin);
        $this->getJson("/api/v1/organizations/{$organization->uuid}/instances?search=Alpha")
            ->assertOk()
            ->assertJsonPath('data.total', 1);
        $this->getJson("/api/v1/organizations/{$organization->uuid}/instances?status=awaiting_approval")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.workflow.name', 'Beta leave');

        OrganizationMembership::query()->where('user_id', $revoked->id)->update(['revoked_at' => now()]);
        $this->signInFresh($revoked);
        $this->getJson("/api/v1/organizations/{$organization->uuid}/tasks")->assertNotFound();
        $this->getJson("/api/v1/organizations/{$organization->uuid}/instances")->assertNotFound();

        $other = $this->signInMember(OrganizationRole::Admin);
        $this->getJson("/api/v1/organizations/{$organization->uuid}/tasks")->assertNotFound();
        $this->getJson("/api/v1/organizations/{$other->uuid}/tasks?search=Alpha")
            ->assertOk()
            ->assertJsonPath('data.total', 0);
        $this->getJson("/api/v1/organizations/{$other->uuid}/instances?search=Alpha")
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_open_tasks_are_paginated_in_the_database(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $manager = $this->addMember($organization, OrganizationRole::Manager);
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $workflow = $this->publishNamed($organization, 'Paged purchase', ExecutionGraphs::approval(['manager']));

        $this->signIn($employee);

        for ($index = 0; $index < 16; $index++) {
            $this->startInstance($organization, $workflow)->assertCreated();
        }

        $this->signIn($manager);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = $this->getJson("/api/v1/organizations/{$organization->uuid}/tasks?page=2")
            ->assertOk()
            ->assertJsonPath('data.total', 16)
            ->assertJsonPath('data.current_page', 2)
            ->assertJsonPath('data.last_page', 2)
            ->assertJsonCount(1, 'data.items');
        $limited = collect(DB::getQueryLog())
            ->pluck('query')
            ->contains(fn (string $sql) => str_contains(strtolower($sql), 'limit') && str_contains($sql, 'workflow_tasks'));
        $this->assertTrue($limited);
        $this->assertNotSame(
            $this->getJson("/api/v1/organizations/{$organization->uuid}/tasks?page=1")->json('data.items.0.uuid'),
            $page->json('data.items.0.uuid'),
        );
    }

    /**
     * @param  array{graph_definition: array<string, mixed>, form_definition: array<string, mixed>}  $definition
     */
    private function publishNamed(Organization $organization, string $name, array $definition): string
    {
        $uuid = $this->createWorkflow($organization, $name);
        $this->putJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/draft", [
            'revision' => 1,
            ...$definition,
        ])->assertOk();
        $this->postJson("/api/v1/organizations/{$organization->uuid}/workflows/{$uuid}/publish", [
            'revision' => 2,
        ])->assertOk();

        return $uuid;
    }

    /**
     * @return list<string>
     */
    private function permittedTaskUuids(Organization $organization, \App\Models\User $user, WorkflowTaskStatus $status): array
    {
        $visibility = app(WorkflowInstanceVisibility::class);

        return WorkflowTask::query()
            ->where('organization_id', $organization->id)
            ->where('status', $status)
            ->with(['instance.version', 'instance.submitter'])
            ->get()
            ->filter(function (WorkflowTask $task) use ($visibility, $user, $status) {
                if ($status === WorkflowTaskStatus::Open) {
                    return $task->task_type === WorkflowTaskType::Revision
                        ? $visibility->canRevise($user, $task)
                        : $visibility->canDecide($user, $task);
                }

                return $visibility->canViewTask($user, $task);
            })
            ->pluck('uuid')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function listedTaskUuids(Organization $organization, \App\Models\User $user, string $status): array
    {
        $this->signInFresh($user);
        $uuids = [];
        $page = 1;

        do {
            $response = $this->getJson("/api/v1/organizations/{$organization->uuid}/tasks?status={$status}&page={$page}")
                ->assertOk();
            $uuids = [...$uuids, ...array_column($response->json('data.items'), 'uuid')];
            $last = (int) $response->json('data.last_page');
            $page++;
        } while ($page <= $last);

        sort($uuids);

        return $uuids;
    }

    private function signInFresh(\App\Models\User $user): void
    {
        Cache::flush();
        $this->signIn($user);
    }

    private function admin(Organization $organization): \App\Models\User
    {
        return \App\Models\User::query()->findOrFail($organization->created_by);
    }
}
