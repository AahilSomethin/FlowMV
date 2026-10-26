<?php

namespace Tests\Feature\Workflows;

use App\Models\Organization;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves PostgreSQL row locks and the single-open-task index with two sessions.
 * The default suite uses SQLite and skips this test. Run it only with
 * phpunit.pgsql.xml, which forces a local disposable database.
 */
class WorkflowConcurrencyTest extends TestCase
{
    use RefreshDatabase;

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
