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

        // Constant DEFAULT avoids rewriting immutable workflow_versions rows / firing the reject-update trigger.
        Schema::table('workflow_versions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('node_registry_version')->default(1)->after('content_hash');
        });

        Schema::table('workflow_instances', function (Blueprint $table): void {
            $table->timestamp('resume_at')->nullable()->after('completed_at');
        });

        Schema::create('workflow_delays', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_instance_id')->constrained()->cascadeOnDelete();
            $table->string('node_id', 64);
            $table->timestamp('resume_at');
            $table->string('status', 32);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'resume_at']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('notification_outbox', function (Blueprint $table) use ($pgsql): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_instance_id')->constrained()->cascadeOnDelete();
            $table->string('node_id', 64);
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('delivery_key', 128);
            $table->string('idempotency_key', 191);
            $table->string('status', 32);
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(8);
            $table->timestamp('available_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            $this->json($table, 'payload', $pgsql);
            $table->timestamps();

            $table->unique(['workflow_instance_id', 'node_id', 'delivery_key'], 'notification_outbox_delivery_unique');
            $table->unique('idempotency_key');
            $table->index(['status', 'available_at']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        if ($pgsql) {
            DB::statement('alter table workflow_instances drop constraint if exists workflow_instances_status_check');
            DB::statement("alter table workflow_instances add constraint workflow_instances_status_check check (status in ('running', 'awaiting_approval', 'awaiting_revision', 'awaiting_assignment', 'awaiting_delay', 'completed', 'rejected', 'failed'))");

            DB::statement('alter table workflow_tasks drop constraint if exists workflow_tasks_type_check');
            DB::statement("alter table workflow_tasks add constraint workflow_tasks_type_check check (task_type in ('approval', 'revision', 'assignment'))");

            DB::statement('alter table workflow_tasks drop constraint if exists workflow_tasks_decision_check');
            DB::statement("alter table workflow_tasks add constraint workflow_tasks_decision_check check ((status = 'open' and decision is null and completed_at is null and completed_by is null) or (status = 'completed' and completed_at is not null and completed_by is not null and ((task_type = 'approval' and decision in ('approved', 'rejected')) or (task_type = 'revision' and decision is null) or (task_type = 'assignment' and decision = 'completed'))))");

            DB::statement('alter table workflow_tasks drop constraint if exists workflow_tasks_assignment_check');
            DB::statement("alter table workflow_tasks add constraint workflow_tasks_assignment_check check ((task_type = 'approval' and assigned_user_id is null) or (task_type = 'revision' and assigned_user_id is not null and assigned_role is null) or (task_type = 'assignment' and ((assigned_user_id is not null and assigned_role is null) or (assigned_user_id is null and assigned_role is not null))))");

            DB::statement("create unique index workflow_delays_one_pending_per_instance on workflow_delays (workflow_instance_id) where status = 'pending'");
            DB::statement("alter table workflow_delays add constraint workflow_delays_status_check check (status in ('pending', 'consumed', 'cancelled'))");
            DB::statement("alter table notification_outbox add constraint notification_outbox_status_check check (status in ('pending', 'processing', 'sent', 'failed'))");
            DB::statement("alter table notification_outbox add constraint notification_outbox_channel_check check (channel in ('in_app', 'email'))");
        }
    }

    public function down(): void
    {
        $pgsql = Schema::getConnection()->getDriverName() === 'pgsql';

        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_outbox');
        Schema::dropIfExists('workflow_delays');

        Schema::table('workflow_instances', function (Blueprint $table): void {
            $table->dropColumn('resume_at');
        });

        Schema::table('workflow_versions', function (Blueprint $table): void {
            $table->dropColumn('node_registry_version');
        });

        if ($pgsql) {
            DB::statement('alter table workflow_instances drop constraint if exists workflow_instances_status_check');
            DB::statement("alter table workflow_instances add constraint workflow_instances_status_check check (status in ('running', 'awaiting_approval', 'awaiting_revision', 'completed', 'rejected', 'failed'))");

            DB::statement('alter table workflow_tasks drop constraint if exists workflow_tasks_type_check');
            DB::statement("alter table workflow_tasks add constraint workflow_tasks_type_check check (task_type in ('approval', 'revision'))");

            DB::statement('alter table workflow_tasks drop constraint if exists workflow_tasks_decision_check');
            DB::statement("alter table workflow_tasks add constraint workflow_tasks_decision_check check ((status = 'open' and decision is null and completed_at is null and completed_by is null) or (status = 'completed' and completed_at is not null and completed_by is not null and ((task_type = 'approval' and decision in ('approved', 'rejected')) or (task_type = 'revision' and decision is null))))");

            DB::statement('alter table workflow_tasks drop constraint if exists workflow_tasks_assignment_check');
            DB::statement("alter table workflow_tasks add constraint workflow_tasks_assignment_check check ((task_type = 'approval' and assigned_user_id is null) or (task_type = 'revision' and assigned_user_id is not null and assigned_role is null))");
        }
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
