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
        DB::table('integration_providers')->insert(['id' => 'twilio_sms', 'contract_version' => 3, 'enabled' => true]);
        Schema::create('sms_accounts', function (Blueprint $t) {
            $t->uuid('connection_id')->primary();
            $t->foreign('connection_id')->references('id')->on('connections')->restrictOnDelete();
            $t->string('account_sid', 34);
            $t->string('active_account_sid', 34)->nullable()->unique();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('credential_generation')->default(1);
            $t->string('health', 40)->default('needs_approval');
        });
        Schema::create('sms_policies', function (Blueprint $t) {
            $t->foreignId('organization_id')->primary()->constrained()->restrictOnDelete();
            $t->boolean('enabled')->default(false);
            $t->text('allowed_countries');
            $t->unsignedInteger('per_minute')->default(3);
            $t->unsignedInteger('daily_limit')->default(10);
            $t->unsignedInteger('monthly_limit')->default(100);
            $t->unsignedInteger('recipient_daily_limit')->default(3);
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->string('key_version', 40);
            $t->text('ciphertext');
        });
        foreach (['sms_senders', 'sms_recipients'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->foreignId('organization_id')->constrained()->restrictOnDelete();
                $t->uuid('connection_id');
                $t->foreign(['connection_id', 'organization_id'])->references(['id', 'organization_id'])->on('connections')->restrictOnDelete();
                $t->string('label', 100);
                $t->string('country', 2)->nullable();
                $t->string('fingerprint', 64);
                $t->string('masked_phone', 20);
                $t->string('provider_sid', 34)->nullable();
                $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
                $t->boolean('enabled')->default(true);
                $t->timestamp('consent_at')->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->string('key_version', 40);
                $t->text('ciphertext');
                $t->unique(['id', 'connection_id']);
                $t->unique(['connection_id', 'fingerprint']);
            });
        }
        Schema::create('sms_templates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->string('name', 100);
            $t->text('body');
            $t->boolean('enabled')->default(true);
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->unique(['id', 'organization_id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION flowmv_sms_template_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'SMS template snapshots are immutable'; END IF; IF NEW.body IS DISTINCT FROM OLD.body OR NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id THEN RAISE EXCEPTION 'SMS template snapshots are immutable'; END IF; RETURN NEW; END $$; CREATE TRIGGER sms_template_immutable BEFORE UPDATE OR DELETE ON sms_templates FOR EACH ROW EXECUTE FUNCTION flowmv_sms_template_immutable();");
        } else {
            DB::unprepared("CREATE TRIGGER sms_template_immutable_update BEFORE UPDATE OF body,id,organization_id ON sms_templates BEGIN SELECT RAISE(ABORT, 'SMS template snapshots are immutable'); END");
            DB::unprepared("CREATE TRIGGER sms_template_immutable_delete BEFORE DELETE ON sms_templates BEGIN SELECT RAISE(ABORT, 'SMS template snapshots are immutable'); END");
        }
        Schema::create('sms_workflow_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->uuid('connection_id');
            $t->foreign(['connection_id', 'organization_id'])->references(['id', 'organization_id'])->on('connections')->restrictOnDelete();
            $t->foreignId('workflow_id')->constrained()->restrictOnDelete();
            $t->string('operation', 32);
            $t->uuid('sender_id');
            $t->foreign(['sender_id', 'connection_id'])->references(['id', 'connection_id'])->on('sms_senders')->restrictOnDelete();
            $t->uuid('recipient_id');
            $t->foreign(['recipient_id', 'connection_id'])->references(['id', 'connection_id'])->on('sms_recipients')->restrictOnDelete();
            $t->uuid('template_id');
            $t->foreign(['template_id', 'organization_id'])->references(['id', 'organization_id'])->on('sms_templates')->restrictOnDelete();
            $t->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $t->unique(['connection_id', 'workflow_id', 'operation', 'sender_id', 'recipient_id', 'template_id'], 'sms_grant_unique');
        });
        Schema::create('sms_action_outbox', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->uuid('connection_id');
            $t->foreign(['connection_id', 'organization_id'])->references(['id', 'organization_id'])->on('connections')->restrictOnDelete();
            $t->foreignId('workflow_instance_id')->constrained()->restrictOnDelete();
            $t->foreignId('workflow_version_id')->constrained()->restrictOnDelete();
            $t->uuid('node_id');
            $t->string('operation', 32);
            $t->uuid('sender_id');
            $t->foreign(['sender_id', 'connection_id'])->references(['id', 'connection_id'])->on('sms_senders')->restrictOnDelete();
            $t->uuid('recipient_id');
            $t->foreign(['recipient_id', 'connection_id'])->references(['id', 'connection_id'])->on('sms_recipients')->restrictOnDelete();
            $t->uuid('template_id');
            $t->foreign(['template_id', 'organization_id'])->references(['id', 'organization_id'])->on('sms_templates')->restrictOnDelete();
            $t->string('recipient_fingerprint', 64);
            $t->unsignedInteger('credential_generation');
            $t->string('key_version', 40);
            $t->text('ciphertext');
            $t->string('status', 20)->default('pending');
            $t->unsignedInteger('attempt_count')->default(0);
            $t->timestamp('available_at');
            $t->timestamp('expires_at');
            $t->timestamp('budget_reserved_at')->nullable()->index();
            $t->uuid('claim_token')->nullable();
            $t->timestamp('lease_expires_at')->nullable();
            $t->string('provider_message_id', 34)->nullable()->unique();
            $t->string('error_code', 40)->nullable();
            $t->unsignedInteger('reconcile_attempts')->default(0);
            $t->timestamp('reconciled_at')->nullable();
            $t->timestamps();
            $t->unique(['workflow_instance_id', 'node_id']);
        });
        Schema::create('sms_status_receipts', function (Blueprint $t) {
            $t->id();
            $t->uuid('delivery_id');
            $t->foreign('delivery_id')->references('id')->on('sms_action_outbox')->restrictOnDelete();
            $t->string('provider_status', 24);
            $t->timestamp('received_at');
            $t->unique(['delivery_id', 'provider_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Forward-only integration migration.');
    }
};
