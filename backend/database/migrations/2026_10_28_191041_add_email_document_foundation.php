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
        DB::table('integration_providers')->insert([
            ['id' => 'gmail', 'contract_version' => 3, 'enabled' => true],
            ['id' => 'outlook', 'contract_version' => 3, 'enabled' => true],
        ]);
        Schema::create('email_mailboxes', function (Blueprint $t) {
            $t->uuid('connection_id')->primary();
            $t->foreign('connection_id')->references('id')->on('connections')->restrictOnDelete();
            $t->string('address', 254);
            $t->string('account_id', 255);
            $t->string('active_identity', 64)->nullable()->unique();
            $t->json('capabilities');
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('credential_generation')->default(1);
            $t->timestamp('token_expires_at');
            $t->string('health', 40)->default('needs_approval');
            $t->string('error_code', 60)->nullable();
            $t->timestamp('connected_at');
        });
        Schema::create('email_oauth_attempts', function (Blueprint $t) {
            $t->string('state_hash', 64)->primary();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('session_hash', 64);
            $t->string('provider_id', 20);
            $t->string('key_version', 40);
            $t->text('ciphertext');
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
        });
        Schema::create('email_sync_state', function (Blueprint $t) {
            $t->uuid('connection_id')->primary();
            $t->foreign('connection_id')->references('id')->on('connections')->restrictOnDelete();
            $t->string('key_version', 40);
            $t->text('ciphertext');
            $t->string('subscription_id', 255)->nullable();
            $t->string('subscription_secret_hash', 64)->nullable();
            $t->timestamp('watch_expires_at')->nullable();
            $t->timestamp('available_at')->index();
            $t->unsignedInteger('attempt_count')->default(0);
            $t->string('error_code', 60)->nullable();
            $t->boolean('requested')->default(true);
            $t->timestamps();
        });
        Schema::create('email_notification_receipts', function (Blueprint $t) {
            $t->id();
            $t->uuid('connection_id');
            $t->foreign('connection_id')->references('id')->on('connections')->restrictOnDelete();
            $t->string('dedupe_hash', 64);
            $t->timestamp('received_at');
            $t->unique(['connection_id', 'dedupe_hash']);
        });
        Schema::create('email_destinations', function (Blueprint $t) {
            $t->id();
            $t->uuid('connection_id');
            $t->foreign('connection_id')->references('id')->on('connections')->restrictOnDelete();
            $t->string('address', 254);
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->unique(['connection_id', 'address']);
        });
        Schema::create('email_workflow_grants', function (Blueprint $t) {
            $t->id();
            $t->uuid('connection_id');
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreign(['connection_id', 'organization_id'])->references(['id', 'organization_id'])->on('connections')->restrictOnDelete();
            $t->foreignId('workflow_id')->constrained()->restrictOnDelete();
            $t->string('operation', 40);
            $t->string('destination', 254)->default('');
            $t->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $t->unique(['connection_id', 'workflow_id', 'operation', 'destination'], 'email_scoped_grant_unique');
        });
        Schema::create('email_fetch_queue', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('connection_id');
            $t->foreign('connection_id')->references('id')->on('connections')->restrictOnDelete();
            $t->string('provider_id_hash', 64);
            $t->string('key_version', 40);
            $t->text('ciphertext');
            $t->string('status', 24)->default('pending');
            $t->unsignedInteger('attempt_count')->default(0);
            $t->timestamp('available_at')->index();
            $t->timestamp('lease_expires_at')->nullable();
            $t->string('error_code', 60)->nullable();
            $t->timestamps();
            $t->unique(['connection_id', 'provider_id_hash']);
        });
        Schema::create('email_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('connection_id');
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreign(['connection_id', 'organization_id'])->references(['id', 'organization_id'])->on('connections')->restrictOnDelete();
            $t->string('provider_id_hash', 64);
            $t->string('key_version', 40);
            $t->text('ciphertext');
            $t->foreignId('sender_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('status', 40)->default('pending');
            $t->timestamp('received_at');
            $t->timestamp('expires_at');
            $t->unique(['connection_id', 'provider_id_hash']);
        });
        Schema::create('document_templates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('name', 100);
            $t->json('fields');
            $t->timestamp('created_at');
        });
        Schema::create('email_attachment_fetches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('message_id');
            $t->foreign('message_id')->references('id')->on('email_messages')->restrictOnDelete();
            $t->string('key_version', 40);
            $t->text('ciphertext');
            $t->string('status', 24)->default('pending');
            $t->unsignedInteger('attempt_count')->default(0);
            $t->timestamp('available_at')->index();
            $t->timestamp('lease_expires_at')->nullable();
            $t->string('error_code', 60)->nullable();
            $t->timestamps();
        });
        Schema::create('private_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->uuid('message_id')->nullable();
            $t->foreign('message_id')->references('id')->on('email_messages')->restrictOnDelete();
            $t->foreignId('workflow_instance_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('name', 120);
            $t->string('mime', 100);
            $t->unsignedInteger('size');
            $t->string('content_hash', 64);
            $t->string('storage_key', 255)->unique();
            $t->string('key_version', 40);
            $t->string('status', 32)->default('quarantined');
            $t->string('error_code', 60)->nullable();
            $t->text('extracted_ciphertext')->nullable();
            $t->string('extracted_key_version', 40)->nullable();
            $t->timestamp('expires_at')->index();
            $t->timestamps();
        });
        Schema::create('email_trigger_bindings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->uuid('connection_id');
            $t->foreign(['connection_id', 'organization_id'])->references(['id', 'organization_id'])->on('connections')->restrictOnDelete();
            $t->foreignId('workflow_id')->constrained()->restrictOnDelete();
            $t->foreignId('workflow_version_id')->constrained()->restrictOnDelete();
            $t->uuid('node_id');
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->unique(['workflow_version_id', 'node_id']);
        });
        Schema::create('workflow_document_evidence', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('workflow_instance_id')->constrained()->restrictOnDelete();
            $t->uuid('document_id');
            $t->foreign('document_id')->references('id')->on('private_documents')->restrictOnDelete();
            $t->unique(['workflow_instance_id', 'document_id']);
        });
        Schema::create('email_intakes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('message_id');
            $t->foreign('message_id')->references('id')->on('email_messages')->restrictOnDelete();
            $t->uuid('binding_id');
            $t->foreign('binding_id')->references('id')->on('email_trigger_bindings')->restrictOnDelete();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('workflow_instance_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('status', 40)->default('awaiting_confirmation');
            $t->unsignedInteger('revision_count')->default(0);
            $t->string('key_version', 40);
            $t->text('ciphertext');
            $t->timestamps();
            $t->unique(['message_id', 'binding_id']);
        });
        Schema::create('email_action_outbox', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('connection_id');
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreign(['connection_id', 'organization_id'])->references(['id', 'organization_id'])->on('connections')->restrictOnDelete();
            $t->foreignId('workflow_id')->constrained()->restrictOnDelete();
            $t->foreignId('workflow_version_id')->constrained()->restrictOnDelete();
            $t->foreignId('workflow_instance_id')->nullable()->constrained()->restrictOnDelete();
            $t->uuid('intake_id')->nullable();
            $t->foreign('intake_id')->references('id')->on('email_intakes')->restrictOnDelete();
            $t->uuid('node_id');
            $t->string('operation', 40);
            $t->string('destination', 254);
            $t->unsignedInteger('credential_generation');
            $t->string('dedupe_hash', 64)->unique();
            $t->string('key_version', 40);
            $t->text('ciphertext');
            $t->string('status', 24)->default('pending');
            $t->unsignedInteger('attempt_count')->default(0);
            $t->uuid('claim_token')->nullable();
            $t->timestamp('lease_expires_at')->nullable();
            $t->timestamp('available_at')->index();
            $t->string('error_code', 60)->nullable();
            $t->timestamps();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER document_template_immutable BEFORE UPDATE OR DELETE ON document_templates FOR EACH ROW EXECUTE FUNCTION reject_integration_audit_mutation()');
        } else {
            DB::unprepared("CREATE TRIGGER document_template_no_update BEFORE UPDATE ON document_templates BEGIN SELECT RAISE(ABORT, 'Document templates are immutable'); END");
            DB::unprepared("CREATE TRIGGER document_template_no_delete BEFORE DELETE ON document_templates BEGIN SELECT RAISE(ABORT, 'Document templates are immutable'); END");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Forward-only email/document migration. Ship a corrective migration.');
    }
};
