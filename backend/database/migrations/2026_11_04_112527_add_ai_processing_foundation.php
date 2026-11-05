<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table workflow_instances drop constraint if exists workflow_instances_status_check');
            DB::statement("alter table workflow_instances add constraint workflow_instances_status_check check (status in ('running', 'awaiting_approval', 'awaiting_revision', 'awaiting_assignment', 'awaiting_delay', 'awaiting_ai', 'completed', 'rejected', 'failed'))");
        }
        foreach (['ai_openai', 'ai_gemini'] as $provider) {
            DB::table('integration_providers')->insert(['id' => $provider, 'contract_version' => 3, 'enabled' => true]);
        }
        Schema::create('ai_policies', function (Blueprint $t) {
            $t->foreignId('organization_id')->primary()->constrained()->restrictOnDelete();
            $t->boolean('enabled')->default(false);
            $t->json('providers');
            $t->json('data_categories');
            $t->unsignedInteger('max_input_bytes')->default(20000);
            $t->unsignedInteger('max_output_tokens')->default(1024);
            $t->unsignedInteger('daily_organization_units')->default(200000);
            $t->unsignedInteger('daily_user_units')->default(50000);
            $t->unsignedInteger('daily_workflow_units')->default(50000);
            $t->unsignedInteger('max_corrections')->default(2);
            $t->unsignedInteger('retention_days')->default(7);
            $t->unsignedInteger('generation')->default(1);
            $t->timestamps();
        });
        Schema::create('ai_connections', function (Blueprint $t) {
            $t->uuid('connection_id')->primary();
            $t->foreign('connection_id')->references('id')->on('connections')->restrictOnDelete();
            $t->string('model', 80);
            $t->json('operations');
            $t->unsignedInteger('max_input_bytes');
            $t->unsignedInteger('max_output_tokens');
            $t->unsignedInteger('daily_units');
            $t->unsignedInteger('generation')->default(1);
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->boolean('approval_requested')->default(false);
        });
        Schema::create('ai_workflow_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->uuid('connection_id');
            $t->foreign(['connection_id', 'organization_id'])->references(['id', 'organization_id'])->on('connections')->restrictOnDelete();
            $t->foreignId('workflow_id')->constrained()->restrictOnDelete();
            $t->string('operation', 40);
            $t->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $t->unique(['connection_id', 'workflow_id', 'operation'], 'ai_grant_unique');
        });
        Schema::create('ai_processing_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('connection_id');
            $t->foreign(['connection_id', 'organization_id'])->references(['id', 'organization_id'])->on('connections')->restrictOnDelete();
            $t->foreignId('workflow_id')->constrained()->restrictOnDelete();
            $t->foreignId('workflow_instance_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('node_id', 80);
            $t->string('operation', 40);
            $t->string('source_kind', 24);
            $t->string('source_id', 80);
            $t->string('input_fingerprint', 64);
            $t->json('configuration');
            $t->string('deduplication_key', 64)->unique();
            $t->unsignedInteger('credential_generation');
            $t->unsignedInteger('policy_generation');
            $t->string('status', 24)->default('pending');
            $t->string('provenance', 24)->nullable();
            $t->string('requested_model', 80);
            $t->string('resolved_model', 80)->nullable();
            $t->string('prompt_version', 40)->default('workplace-v1');
            $t->unsignedInteger('attempt_count')->default(0);
            $t->string('error_code', 50)->nullable();
            $t->string('key_version', 40)->nullable();
            $t->text('ciphertext')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('processing_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'status']);
        });
        Schema::create('ai_usage_reservations', function (Blueprint $t) {
            $t->uuid('run_id')->primary();
            $t->foreign('run_id')->references('id')->on('ai_processing_runs')->restrictOnDelete();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('workflow_id')->constrained()->restrictOnDelete();
            $t->uuid('connection_id');
            $t->foreign('connection_id')->references('id')->on('connections')->restrictOnDelete();
            $t->date('utc_day');
            $t->unsignedInteger('reserved_units');
            $t->unsignedInteger('reported_input_tokens')->nullable();
            $t->unsignedInteger('reported_output_tokens')->nullable();
            $t->string('state', 24)->default('reserved');
            $t->timestamps();
            $t->index(['organization_id', 'utc_day']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('AI foundation migrations are forward-only.');
    }
};
