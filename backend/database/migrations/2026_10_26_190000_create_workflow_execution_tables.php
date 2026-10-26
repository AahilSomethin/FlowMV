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

        Schema::create('workflow_instances', function (Blueprint $table) use ($pgsql): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32);
            $table->string('failure_code', 64)->nullable();
            $table->string('current_node_id', 64);
            $this->json($table, 'submitted_data', $pgsql);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'submitted_by']);
            $table->index('workflow_version_id');
        });

        Schema::create('workflow_tasks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_instance_id')->constrained()->cascadeOnDelete();
            $table->string('node_id', 64);
            $table->string('task_type', 32);
            $table->string('assigned_role', 32)->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32);
            $table->string('decision', 32)->nullable();
            $table->timestamp('assigned_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['workflow_instance_id', 'status']);
            $table->index(['assigned_user_id', 'status']);
        });

        Schema::create('workflow_events', function (Blueprint $table) use ($pgsql): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 64);
            $table->string('node_id', 64)->nullable();
            $this->json($table, 'event_payload', $pgsql);
            $table->timestamp('created_at');

            $table->index(['workflow_instance_id', 'id']);
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('workflow_revision_counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_instance_id')->constrained()->cascadeOnDelete();
            $table->string('revision_node_id', 64);
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamps();

            $table->unique(['workflow_instance_id', 'revision_node_id'], 'workflow_revision_counters_instance_node_unique');
        });

        Schema::create('idempotency_records', function (Blueprint $table) use ($pgsql): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('idempotency_key', 255);
            $table->char('request_fingerprint', 64);
            $table->unsignedSmallInteger('response_status');
            $this->json($table, 'response_body', $pgsql);
            $table->timestamps();

            $table->unique(
                ['organization_id', 'actor_id', 'idempotency_key'],
                'idempotency_records_actor_key_unique',
            );
        });

        DB::statement("create unique index workflow_tasks_one_open_per_instance on workflow_tasks (workflow_instance_id) where status = 'open'");

        if ($pgsql) {
            DB::statement("alter table workflow_instances add constraint workflow_instances_status_check check (status in ('running', 'awaiting_approval', 'awaiting_revision', 'completed', 'rejected', 'failed'))");
            DB::statement("alter table workflow_instances add constraint workflow_instances_failure_check check ((status = 'failed' and failure_code in ('no_eligible_approver', 'invalid_graph', 'unknown_operator', 'corrupt_state')) or (status <> 'failed' and failure_code is null))");
            DB::statement("alter table workflow_tasks add constraint workflow_tasks_type_check check (task_type in ('approval', 'revision'))");
            DB::statement("alter table workflow_tasks add constraint workflow_tasks_status_check check (status in ('open', 'completed'))");
            DB::statement("alter table workflow_tasks add constraint workflow_tasks_decision_check check ((status = 'open' and decision is null and completed_at is null and completed_by is null) or (status = 'completed' and completed_at is not null and completed_by is not null and ((task_type = 'approval' and decision in ('approved', 'rejected')) or (task_type = 'revision' and decision is null))))");
            DB::statement("alter table workflow_tasks add constraint workflow_tasks_assignment_check check ((task_type = 'approval' and assigned_user_id is null) or (task_type = 'revision' and assigned_user_id is not null and assigned_role is null))");
            DB::statement('alter table workflow_revision_counters add constraint workflow_revision_counters_attempts_check check (attempt_count between 0 and 10)');
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION workflow_events_reject_mutation()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION 'Workflow events are append-only';
                END;
                $$;

                CREATE TRIGGER workflow_events_reject_mutation
                BEFORE UPDATE OR DELETE ON workflow_events
                FOR EACH ROW
                EXECUTE PROCEDURE workflow_events_reject_mutation();
            SQL);
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS workflow_events_reject_mutation ON workflow_events');
            DB::unprepared('DROP FUNCTION IF EXISTS workflow_events_reject_mutation()');
        }

        Schema::dropIfExists('idempotency_records');
        Schema::dropIfExists('workflow_revision_counters');
        Schema::dropIfExists('workflow_events');
        Schema::dropIfExists('workflow_tasks');
        Schema::dropIfExists('workflow_instances');
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
