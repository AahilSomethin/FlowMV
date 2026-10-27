<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $pgsql = Schema::getConnection()->getDriverName() === 'pgsql';

        Schema::create('workflow_submission_snapshots', function (Blueprint $table) use ($pgsql): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_task_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('reason', 32);
            $this->json($table, 'submitted_data', $pgsql);
            $table->timestamp('captured_at');

            $table->index(['organization_id', 'workflow_instance_id'], 'workflow_snapshots_org_instance_idx');
            $table->index('workflow_task_id', 'workflow_snapshots_task_idx');
        });

        DB::statement("create unique index workflow_snapshots_task_reason on workflow_submission_snapshots (workflow_task_id, reason) where workflow_task_id is not null");
        DB::statement("create unique index workflow_snapshots_one_initial on workflow_submission_snapshots (workflow_instance_id) where reason = 'initial'");

        if ($pgsql) {
            DB::statement("alter table workflow_submission_snapshots add constraint workflow_snapshots_reason_check check (reason in ('initial', 'approval_decision', 'revision_submitted'))");
            DB::statement("alter table workflow_submission_snapshots add constraint workflow_snapshots_task_check check ((reason = 'initial' and workflow_task_id is null) or (reason in ('approval_decision', 'revision_submitted') and workflow_task_id is not null))");
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION workflow_submission_snapshots_reject_update()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION 'Submission snapshots are append-only';
                END;
                $$;

                CREATE TRIGGER workflow_submission_snapshots_reject_update
                BEFORE UPDATE ON workflow_submission_snapshots
                FOR EACH ROW
                EXECUTE PROCEDURE workflow_submission_snapshots_reject_update();
            SQL);
        }

        Schema::table('workflow_instances', function (Blueprint $table): void {
            $table->index(['organization_id', 'started_at'], 'workflow_instances_org_started_idx');
            $table->index(['organization_id', 'workflow_id', 'status'], 'workflow_instances_org_workflow_status_idx');
        });

        Schema::table('workflow_tasks', function (Blueprint $table): void {
            $table->index(['organization_id', 'task_type', 'status', 'assigned_at'], 'workflow_tasks_org_type_assigned_idx');
            $table->index(['organization_id', 'task_type', 'completed_at'], 'workflow_tasks_org_type_completed_idx');
        });

        Schema::table('workflow_events', function (Blueprint $table): void {
            $table->index(['organization_id', 'event_type', 'created_at'], 'workflow_events_org_type_created_idx');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS workflow_submission_snapshots_reject_update ON workflow_submission_snapshots');
            DB::unprepared('DROP FUNCTION IF EXISTS workflow_submission_snapshots_reject_update()');
        }

        Schema::table('workflow_events', function (Blueprint $table): void {
            $table->dropIndex('workflow_events_org_type_created_idx');
        });
        Schema::table('workflow_tasks', function (Blueprint $table): void {
            $table->dropIndex('workflow_tasks_org_type_completed_idx');
            $table->dropIndex('workflow_tasks_org_type_assigned_idx');
        });
        Schema::table('workflow_instances', function (Blueprint $table): void {
            $table->dropIndex('workflow_instances_org_workflow_status_idx');
            $table->dropIndex('workflow_instances_org_started_idx');
        });

        Schema::dropIfExists('workflow_submission_snapshots');
    }

    private function json(Blueprint $table, string $column, bool $pgsql): void
    {
        if ($pgsql) {
            $table->jsonb($column);

            return;
        }

        $table->json($column);
    }
};
