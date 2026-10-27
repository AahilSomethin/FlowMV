<?php

namespace Tests\Feature\Workflows;

use App\Actions\WorkflowInstances\DecideWorkflowTask;
use App\Actions\WorkflowInstances\StartWorkflowInstance;
use App\Enums\OrganizationRole;
use App\Exceptions\WorkflowConflictException;
use App\Jobs\ProcessNotificationOutbox;
use App\Models\NotificationOutbox;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Models\WorkflowVersion;
use App\Services\Workflows\Execution\IdempotentAction;
use App\Services\Workflows\Execution\WorkflowEventWriter;
use App\Support\OrganizationAdminGuard;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\AutomationGraphs;
use Tests\Support\ExecutionGraphs;
use Tests\Support\WorkflowGraphs;
use Tests\TestCase;

/**
 * Proves PostgreSQL row locks and the single-open-task index with two sessions.
 * The default suite uses SQLite and skips this test. Run it only with
 * phpunit.pgsql.xml, which forces a local disposable database.
 */
class WorkflowConcurrencyTest extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithExecution;

    public function test_postgresql_notification_text_payloads_are_scoped_and_can_be_marked_read(): void
    {
        Mail::fake();
        Queue::fake();
        $organization = $this->signInMember(OrganizationRole::Admin);
        $workflow = $this->publishWorkflow($organization, AutomationGraphs::notification());
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $this->addMember($organization, OrganizationRole::Manager);
        $this->signIn($employee);
        $this->startInstance($organization, $workflow)->assertCreated();
        $row = NotificationOutbox::where('recipient_user_id', $employee->id)->where('channel', 'in_app')->firstOrFail();
        (new ProcessNotificationOutbox($row->workflow_instance_id, $row->node_id))->handle(app(WorkflowEventWriter::class));
        $notification = $employee->notifications()->firstOrFail();
        $notification->update(['data' => ['instance_uuid' => $row->instance->uuid]]);
        $base = "/api/v1/organizations/{$organization->uuid}/notifications";
        $this->getJson($base)->assertOk()->assertJsonCount(1, 'data.items');
        $read = $this->postJson("{$base}/{$notification->id}/read")->assertOk()->json('data.read_at');
        $this->postJson("{$base}/{$notification->id}/read")->assertOk()->assertJsonPath('data.read_at', $read);
        $this->assertNotNull($notification->fresh()->read_at);
        $this->getJson("{$base}?status=read")->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson("{$base}?status=unread")->assertOk()->assertJsonCount(0, 'data.items');
        $employee->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => $notification->type,
            'data' => ['instance_uuid' => 'not-a-uuid', 'organization_uuid' => $organization->uuid], 'read_at' => null,
        ]);
        for ($index = 0; $index < 50; $index++) {
            $employee->notifications()->create([
                'id' => (string) Str::uuid(), 'type' => $notification->type,
                'data' => ['instance_uuid' => $row->instance->uuid], 'read_at' => null,
            ]);
        }
        $this->getJson($base)->assertOk()->assertJsonCount(50, 'data.items')
            ->assertJsonPath('data.total', 51)->assertJsonPath('data.last_page', 2);
        $this->getJson("{$base}?page=2")->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson("{$base}?status=unread")->assertOk()->assertJsonPath('data.total', 50);
        $this->getJson("{$base}?page=0")->assertUnprocessable();
        $this->getJson("{$base}?status=invalid")->assertUnprocessable();
        $other = Organization::factory()->create();
        OrganizationMembership::factory()->create(['organization_id' => $other->id, 'user_id' => $employee->id, 'role' => OrganizationRole::Employee]);
        $this->getJson("/api/v1/organizations/{$other->uuid}/notifications")->assertOk()->assertJsonCount(0, 'data.items');
        $this->postJson("/api/v1/organizations/{$other->uuid}/notifications/{$notification->id}/read")->assertNotFound();
        $this->signIn(User::findOrFail($organization->created_by));
        $this->getJson($base)->assertOk()->assertJsonCount(0, 'data.items');
        $this->postJson("{$base}/{$notification->id}/read")->assertNotFound();
        $this->signIn($employee);
        OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $employee->id)->update(['revoked_at' => now()]);
        $this->getJson($base)->assertNotFound();
        $this->postJson("{$base}/{$notification->id}/read")->assertNotFound();
    }

    public function test_local_endpoint_profile_records_query_counts_without_submission_values(): void
    {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        $organization = $this->signInMember(OrganizationRole::Admin);
        $actor = User::findOrFail($organization->created_by);
        $workflow = $this->publishWorkflow($organization, ExecutionGraphs::approval());
        $this->addMember($organization, OrganizationRole::Manager);
        $this->postJson('/api/v1/auth/logout')->assertSuccessful();
        $counts = ['count' => 0, 'ms' => 0.0];
        DB::listen(static function ($query) use (&$counts): void {
            $counts['count']++;
            $counts['ms'] += $query->time;
        });
        $profile = [];
        $measure = function (string $label, callable $request, int $status) use (&$counts, &$profile): void {
            $this->refreshGuards();
            $counts = ['count' => 0, 'ms' => 0.0];
            $start = hrtime(true);
            $response = $request();
            $elapsed = (hrtime(true) - $start) / 1e6;
            $response->assertStatus($status);
            $profile[$label] = ['kernel_ms' => round($elapsed, 2), 'sql_count' => $counts['count'], 'sql_ms' => round($counts['ms'], 2)];
        };
        $base = "/api/v1/organizations/{$organization->uuid}";
        $measure('csrf', fn () => $this->getJson('/sanctum/csrf-cookie'), 204);
        $measure('login', fn () => $this->postJson('/api/v1/auth/login', ['email' => $actor->email, 'password' => 'password']), 200);
        foreach (['first', 'warm'] as $wave) {
            $measure("{$wave}.me", fn () => $this->getJson('/api/v1/me'), 200);
            $measure("{$wave}.workflow_list", fn () => $this->getJson("{$base}/workflows"), 200);
            $measure("{$wave}.workflow_detail", fn () => $this->getJson("{$base}/workflows/{$workflow}"), 200);
            $measure("{$wave}.submission", fn () => $this->startInstance($organization, $workflow), 201);
            $measure("{$wave}.tasks", fn () => $this->getJson("{$base}/tasks"), 200);
            $range = '?from='.now()->subDays(1)->toDateString().'&to='.now()->toDateString();
            $measure("{$wave}.analytics", fn () => $this->getJson("{$base}/analytics{$range}"), 200);
            $measure("{$wave}.audit", fn () => $this->getJson("{$base}/audit{$range}"), 200);
        }
        $directory = storage_path('app/phase85-qa');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory.'/endpoint-profile.json', json_encode($profile, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public function test_runtime_permissions_allow_execution_but_refuse_immutable_writes_and_ddl(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $workflowUuid = $this->publishWorkflow($organization, ExecutionGraphs::approval());
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $manager = $this->addMember($organization, OrganizationRole::Manager);
        $role = 'flowmv_acceptance_'.strtolower(Str::random(12));
        DB::statement("CREATE ROLE {$role} NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS");
        try {
            DB::unprepared(str_replace(':runtime_role', $role, file_get_contents(database_path('runtime-permissions.sql'))));
            DB::statement("SET ROLE {$role}");
            $instance = app(StartWorkflowInstance::class)($employee, $organization, Workflow::where('uuid', $workflowUuid)->firstOrFail(), []);
            $task = $instance->tasks()->where('status', 'open')->firstOrFail();
            app(DecideWorkflowTask::class)($manager, $task, 'approved');
            $this->assertSame('completed', $instance->fresh()->status->value);
            $this->assertSame(2, DB::table('workflow_submission_snapshots')->where('workflow_instance_id', $instance->id)->count());
            foreach ([
                'UPDATE workflow_submission_snapshots SET submitted_data = submitted_data',
                'DELETE FROM workflow_submission_snapshots',
                'UPDATE workflow_versions SET version_number = version_number',
                'DELETE FROM workflow_events',
                'CREATE TABLE runtime_must_not_create (id int)',
            ] as $sql) {
                try {
                    DB::statement($sql);
                    $this->fail('Runtime permissions allowed a protected operation.');
                } catch (QueryException $exception) {
                    $this->assertSame('42501', $exception->errorInfo[0]);
                }
            }
            try {
                DB::table('organizations')->where('id', $organization->id)->delete();
                $this->fail('The existing audit immutability trigger allowed deletion of event history.');
            } catch (QueryException $exception) {
                $this->assertSame('P0001', $exception->errorInfo[0]);
            }
            $bare = WorkflowInstance::query()->create([
                'organization_id' => $organization->id,
                'workflow_id' => $instance->workflow_id,
                'workflow_version_id' => $instance->workflow_version_id,
                'status' => 'running', 'current_node_id' => $instance->current_node_id, 'submitted_data' => [], 'started_at' => now(),
            ]);
            DB::table('workflow_submission_snapshots')->insert([
                'uuid' => (string) Str::uuid(), 'organization_id' => $organization->id,
                'workflow_instance_id' => $bare->id, 'reason' => 'initial',
                'submitted_data' => '{}', 'captured_at' => now(),
            ]);
            DB::table('workflow_instances')->where('id', $bare->id)->delete();
            $this->assertSame(0, DB::table('workflow_submission_snapshots')->where('workflow_instance_id', $bare->id)->count());
        } finally {
            DB::statement('RESET ROLE');
            DB::statement("DROP OWNED BY {$role}");
            DB::statement("DROP ROLE {$role}");
        }
    }

    public function test_simultaneous_approvals_record_one_decision_and_one_successor(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $definition = ExecutionGraphs::approval();
        $graph = &$definition['graph_definition'];
        $approval = collect($graph['nodes'])->firstWhere('type', 'approval');
        $next = $approval;
        $next['id'] = (string) Str::uuid();
        $graph['nodes'][] = $next;
        foreach ($graph['edges'] as &$edge) {
            if ($edge['source'] === $approval['id'] && $edge['source_handle'] === 'approved') {
                $end = $edge['target'];
                $edge['target'] = $next['id'];
            }
        }
        unset($edge);
        $graph['edges'][] = WorkflowGraphs::edge($next['id'], $end, 'approved');
        $revision = collect($graph['nodes'])->firstWhere('type', 'revision');
        $graph['edges'][] = WorkflowGraphs::edge($next['id'], $revision['id'], 'rejected');
        $workflow = $this->publishWorkflow($organization, $definition);
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $manager = $this->addMember($organization, OrganizationRole::Manager);
        $this->signIn($employee);
        $started = $this->startInstance($organization, $workflow)->assertCreated();
        $taskId = WorkflowTask::where('uuid', $started->json('data.tasks.0.uuid'))->value('id');
        $managerId = $manager->id;
        $connection = config('database.connections.pgsql');
        $at = microtime(true) + 1;
        $work = static function () use ($connection, $taskId, $managerId, $at): string {
            config(['database.default' => 'pgsql', 'database.connections.pgsql' => $connection]);
            DB::purge('pgsql');
            while (microtime(true) < $at) {
                usleep(1000);
            }
            try {
                app(DecideWorkflowTask::class)(User::findOrFail($managerId), WorkflowTask::findOrFail($taskId), 'approved');

                return 'approved';
            } catch (WorkflowConflictException) {
                return 'conflict';
            }
        };
        $results = Concurrency::driver('process')->run([$work, $work]);
        sort($results);
        $this->assertSame(['approved', 'conflict'], $results);
        $instance = WorkflowInstance::where('uuid', $started->json('data.uuid'))->firstOrFail();
        $this->assertSame('awaiting_approval', $instance->status->value);
        $this->assertSame(1, $instance->tasks()->where('status', 'open')->count());
        $this->assertSame(1, $instance->tasks()->where('status', 'completed')->count());
        $this->assertSame(1, $instance->events()->where('event_type', 'task_approved')->count());
    }

    public function test_simultaneous_duplicate_keys_create_one_instance_and_replay_the_same_result(): void
    {
        $organization = $this->signInMember(OrganizationRole::Admin);
        $workflowUuid = $this->publishWorkflow($organization, ExecutionGraphs::approval());
        $employee = $this->addMember($organization, OrganizationRole::Employee);
        $this->addMember($organization, OrganizationRole::Manager);
        $actorId = $employee->id;
        $organizationId = $organization->id;
        $connection = config('database.connections.pgsql');
        $key = (string) Str::uuid();
        $at = microtime(true) + 1;
        $work = static function () use ($connection, $actorId, $organizationId, $workflowUuid, $key, $at): string {
            config(['database.default' => 'pgsql', 'database.connections.pgsql' => $connection]);
            DB::purge('pgsql');
            $actor = User::findOrFail($actorId);
            $organization = Organization::findOrFail($organizationId);
            $workflow = Workflow::where('uuid', $workflowUuid)->firstOrFail();
            $request = Request::create('/concurrent-submission', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key], '{"data":{}}');
            while (microtime(true) < $at) {
                usleep(1000);
            }
            $response = app(IdempotentAction::class)->execute($actor, $organization, $request, static function () use ($actor, $organization, $workflow): array {
                $instance = app(StartWorkflowInstance::class)($actor, $organization, $workflow, []);

                return ['status' => 201, 'body' => ['uuid' => $instance->uuid]];
            });

            return $response->getData(true)['uuid'];
        };
        $results = Concurrency::driver('process')->run([$work, $work]);
        $this->assertSame($results[0], $results[1]);
        $this->assertSame(1, WorkflowInstance::query()->count());
        $instance = WorkflowInstance::query()->firstOrFail();
        $this->assertSame(1, $instance->tasks()->where('status', 'open')->count());
        $this->assertSame(1, $instance->events()->where('event_type', 'instance_started')->count());
    }

    public function test_concurrent_admin_releases_preserve_one_admin(): void
    {
        $organization = Organization::factory()->create(['admin_count' => 2]);
        $organizationId = $organization->id;
        $connection = config('database.connections.pgsql');
        $at = microtime(true) + 1;
        $work = static function () use ($connection, $organizationId, $at): string {
            config(['database.default' => 'pgsql', 'database.connections.pgsql' => $connection]);
            DB::purge('pgsql');
            while (microtime(true) < $at) {
                usleep(1000);
            }
            try {
                app(OrganizationAdminGuard::class)->release(Organization::findOrFail($organizationId));

                return 'released';
            } catch (ValidationException) {
                return 'refused';
            }
        };
        $results = Concurrency::driver('process')->run([$work, $work]);
        sort($results);
        $this->assertSame(['refused', 'released'], $results);
        $this->assertSame(1, $organization->fresh()->admin_count);
    }

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency coverage requires a disposable PostgreSQL target.');
        }

        $host = (string) config('database.connections.pgsql.host');
        $database = (string) config('database.connections.pgsql.database');
        $target = (string) getenv('FLOWMV_PGSQL_TEST_DATABASE');

        if (! in_array($host, ['127.0.0.1', '::1', 'localhost'], true)
            || $target === '' || ! str_starts_with($target, 'flowmv_')
            || ! str_ends_with($target, '_test') || $database !== $target
            || (string) config('database.connections.pgsql.url') !== '') {
            $this->markTestSkipped('Refusing destructive setup outside the explicit disposable loopback database.');
        }

        $actual = DB::selectOne('select current_database() as name, inet_server_addr()::text as address');
        if ($actual->name !== $target || ! in_array($actual->address, ['127.0.0.1/32', '127.0.0.1', '::1/128', '::1'], true)) {
            $this->markTestSkipped('The connected PostgreSQL server is not the verified local test target.');
        }
    }

    public function test_a_second_session_cannot_lock_or_open_the_same_step(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency coverage runs only in the disposable pgsql suite.');
        }

        $host = (string) config('database.connections.pgsql.host');
        $database = (string) config('database.connections.pgsql.database');

        if (str_contains($host, 'supabase.com') || $database !== (string) getenv('FLOWMV_PGSQL_TEST_DATABASE')) {
            $this->markTestSkipped('Refusing to run concurrency tests against a database that is not the explicit disposable target.');
        }

        $organization = Organization::factory()->create();
        $workflow = Workflow::factory()->create(['organization_id' => $organization->id]);
        $version = WorkflowVersion::factory()->create(['workflow_id' => $workflow->id]);
        $instance = WorkflowInstance::query()->create([
            'organization_id' => $organization->id,
            'workflow_id' => $workflow->id,
            'workflow_version_id' => $version->id,
            'status' => 'running',
            'current_node_id' => '11111111-1111-1111-1111-111111111111',
            'submitted_data' => [],
            'started_at' => now(),
        ]);

        $first = DB::connection('pgsql');
        config(['database.connections.pgsql_race' => array_merge(
            config('database.connections.pgsql'),
            ['name' => 'pgsql_race'],
        )]);
        DB::purge('pgsql_race');
        $second = DB::connection('pgsql_race');

        $first->beginTransaction();
        $first->select('select id from workflow_instances where id = ? for update', [$instance->id]);
        $second->statement("set lock_timeout = '500ms'");

        try {
            $second->select('select id from workflow_instances where id = ? for update', [$instance->id]);
            $this->fail('The second session acquired the instance lock.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $first->table('workflow_tasks')->insert([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'workflow_instance_id' => $instance->id,
            'node_id' => $instance->current_node_id,
            'task_type' => 'approval',
            'status' => 'open',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $second->statement("set lock_timeout = '500ms'");

        try {
            $second->table('workflow_tasks')->insert([
                'uuid' => (string) Str::uuid(),
                'organization_id' => $organization->id,
                'workflow_instance_id' => $instance->id,
                'node_id' => $instance->current_node_id,
                'task_type' => 'approval',
                'status' => 'open',
                'assigned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('The second session inserted another open task.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $first->commit();
        $this->assertSame(1, $second->table('workflow_tasks')->where('workflow_instance_id', $instance->id)->where('status', 'open')->count());
        $second->disconnect();
    }
}
