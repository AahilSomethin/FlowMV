<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_providers', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedInteger('contract_version');
            $table->boolean('enabled')->default(false);
        });
        DB::table('integration_providers')->insert(['id' => 'local_mock', 'contract_version' => 3, 'enabled' => true]);
        Schema::create('integration_policies', function (Blueprint $table) {
            $table->foreignId('organization_id')->primary()->constrained()->restrictOnDelete();
            $table->boolean('personal_connections_allowed')->default(false);
        });
        Schema::create('integration_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('provider_id');
            $table->foreign('provider_id')->references('id')->on('integration_providers')->restrictOnDelete();
            $table->boolean('approved')->default(false);
            $table->unique(['organization_id', 'provider_id']);
        });
        Schema::create('connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('provider_id');
            $table->foreign('provider_id')->references('id')->on('integration_providers')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('credential_type', 24);
            $table->string('status', 16)->default('enabled');
            $table->timestamps();
            $table->unique(['id', 'organization_id']);
        });
        Schema::create('credential_secrets', function (Blueprint $table) {
            $table->uuid('connection_id')->primary();
            $table->foreign('connection_id')->references('id')->on('connections')->restrictOnDelete();
            $table->string('key_version', 40);
            $table->text('ciphertext');
            $table->timestamps();
        });
        Schema::create('workflow_connection_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('connection_id');
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreign(['connection_id', 'organization_id'])->references(['id', 'organization_id'])->on('connections')->restrictOnDelete();
            $table->foreignId('workflow_id')->constrained()->restrictOnDelete();
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $table->string('operation', 80);
            $table->unique(['connection_id', 'workflow_id', 'operation'], 'connection_workflow_operation_unique');
        });
        Schema::create('authorized_destinations', function (Blueprint $table) {
            $table->id();
            $table->uuid('connection_id');
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreign(['connection_id', 'organization_id'])->references(['id', 'organization_id'])->on('connections')->restrictOnDelete();
            $table->string('destination', 80);
            $table->unique(['connection_id', 'destination']);
        });
        Schema::create('integration_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('connection_id')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event', 80);
            $table->timestamp('created_at');
            $table->index(['organization_id', 'id']);
        });
        Schema::create('integration_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('connection_id');
            $table->foreign('connection_id')->references('id')->on('connections')->restrictOnDelete();
            $table->string('deduplication_key', 64);
            $table->string('kind', 16);
            $table->timestamp('created_at');
            $table->unique(['connection_id', 'kind', 'deduplication_key'], 'integration_receipt_unique');
        });
        Schema::create('integration_oauth_requests', function (Blueprint $table) {
            $table->string('state_hash', 64)->primary();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('session_hash', 64);
            $table->string('code_hash', 64)->nullable();
            $table->text('encrypted_context');
            $table->string('key_version', 40);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION reject_integration_audit_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Integration audit events are append-only'; END; $$");
            DB::unprepared('CREATE TRIGGER integration_audit_immutable BEFORE UPDATE OR DELETE ON integration_audit_events FOR EACH ROW EXECUTE FUNCTION reject_integration_audit_mutation()');
        } else {
            DB::unprepared("CREATE TRIGGER integration_audit_no_update BEFORE UPDATE ON integration_audit_events BEGIN SELECT RAISE(ABORT, 'Integration audit events are append-only'); END");
            DB::unprepared("CREATE TRIGGER integration_audit_no_delete BEFORE DELETE ON integration_audit_events BEGIN SELECT RAISE(ABORT, 'Integration audit events are append-only'); END");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only integration migration. Ship a corrective migration.');
    }
};
