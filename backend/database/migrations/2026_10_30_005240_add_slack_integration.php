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
        DB::table('integration_providers')->insert(['id' => 'slack', 'contract_version' => 3, 'enabled' => true]);
        Schema::create('slack_installations', function (Blueprint $t) {
            $t->uuid('connection_id')->primary();
            $t->foreign('connection_id')->references('id')->on('connections')->restrictOnDelete();
            $t->string('workspace_id', 32);
            $t->string('active_workspace_id', 32)->nullable()->unique();
            $t->string('bot_user_id', 32);
            $t->text('scopes');
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('credential_generation')->default(1);
            $t->timestamp('token_expires_at')->nullable();
            $t->string('health', 40)->default('needs_approval');
        });
        Schema::create('slack_oauth_attempts', function (Blueprint $t) {
            $t->string('state_hash', 64)->primary();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('session_hash', 64);
            $t->string('key_version', 40);
            $t->text('ciphertext');
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
        });
        Schema::create('slack_channels', function (Blueprint $t) {
            $t->id();
            $t->uuid('connection_id');
            $t->foreign('connection_id')->references('connection_id')->on('slack_installations')->restrictOnDelete();
            $t->string('channel_id', 32);
            $t->string('label', 100);
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->unique(['connection_id', 'channel_id']);
        });
        Schema::create('slack_workflow_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->uuid('connection_id');
            $t->foreign(['connection_id', 'organization_id'])->references(['id', 'organization_id'])->on('connections')->restrictOnDelete();
            $t->foreignId('workflow_id')->constrained()->restrictOnDelete();
            $t->string('operation', 40);
            $t->string('destination', 32);
            $t->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $t->unique(['connection_id', 'workflow_id', 'operation', 'destination'], 'slack_grant_unique');
        });
        Schema::create('slack_identity_links', function (Blueprint $t) {
            $t->id();
            $t->uuid('connection_id');
            $t->foreign('connection_id')->references('connection_id')->on('slack_installations')->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('slack_user_id', 32)->nullable();
            $t->string('challenge_hash', 64)->nullable()->unique();
            $t->timestamp('expires_at')->nullable();
            $t->boolean('confirmed')->default(false);
            $t->unique(['connection_id', 'user_id']);
            $t->unique(['connection_id', 'slack_user_id']);
        });
        Schema::create('slack_trigger_subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->uuid('connection_id');
            $t->foreign(['connection_id', 'organization_id'])->references(['id', 'organization_id'])->on('connections')->restrictOnDelete();
            $t->foreignId('workflow_id')->constrained()->restrictOnDelete();
            $t->foreignId('workflow_version_id')->constrained()->restrictOnDelete();
            $t->uuid('node_id');
            $t->string('destination', 32);
            $t->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->unique(['workflow_version_id', 'node_id']);
        });
        Schema::create('slack_inbound_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('connection_id');
            $t->foreign('connection_id')->references('connection_id')->on('slack_installations')->restrictOnDelete();
            $t->string('event_key', 64);
            $t->string('key_version', 40);
            $t->text('ciphertext');
            $t->timestamp('received_at');
            $t->unique(['connection_id', 'event_key']);
        });
        Schema::create('slack_trigger_activations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('event_id');
            $t->foreign('event_id')->references('id')->on('slack_inbound_events')->restrictOnDelete();
            $t->uuid('subscription_id');
            $t->foreign('subscription_id')->references('id')->on('slack_trigger_subscriptions')->restrictOnDelete();
            $t->foreignId('workflow_instance_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('status', 16)->default('pending');
            $t->unsignedInteger('attempt_count')->default(0);
            $t->timestamp('available_at');
            $t->string('error_code', 40)->nullable();
            $t->timestamps();
            $t->unique(['event_id', 'subscription_id']);
        });
        Schema::create('slack_action_outbox', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->uuid('connection_id');
            $t->foreign(['connection_id', 'organization_id'])->references(['id', 'organization_id'])->on('connections')->restrictOnDelete();
            $t->foreignId('workflow_instance_id')->constrained()->restrictOnDelete();
            $t->foreignId('workflow_version_id')->constrained()->restrictOnDelete();
            $t->uuid('node_id');
            $t->string('operation', 40);
            $t->string('destination', 32);
            $t->unsignedInteger('credential_generation');
            $t->string('key_version', 40);
            $t->text('ciphertext');
            $t->string('status', 16)->default('pending');
            $t->unsignedInteger('attempt_count')->default(0);
            $t->timestamp('available_at');
            $t->uuid('claim_token')->nullable();
            $t->timestamp('lease_expires_at')->nullable();
            $t->string('provider_message_id', 64)->nullable();
            $t->string('error_code', 40)->nullable();
            $t->timestamps();
            $t->unique(['workflow_instance_id', 'node_id']);
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
