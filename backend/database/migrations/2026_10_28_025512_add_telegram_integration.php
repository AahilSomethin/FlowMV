<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('integration_providers')->insert(['id' => 'telegram', 'contract_version' => 3, 'enabled' => true]);
        Schema::create('telegram_bots', function (Blueprint $t) {
            $t->uuid('connection_id')->primary();
            $t->foreign('connection_id')->references('id')->on('connections')->restrictOnDelete();
            $t->string('bot_id', 24);
            $t->string('active_bot_id', 24)->nullable()->unique();
            $t->string('username', 64);
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('webhook_secret_hash', 64)->nullable();
            $t->boolean('webhook_configured')->default(false);
            $t->unsignedInteger('credential_generation')->default(1);
        });
        Schema::create('telegram_destinations', function (Blueprint $t) {
            $t->id();
            $t->uuid('connection_id');
            $t->foreign('connection_id')->references('id')->on('connections')->restrictOnDelete();
            $t->string('chat_id', 24);
            $t->string('chat_type', 16);
            $t->string('label', 100);
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unique(['connection_id', 'chat_id']);
        });
        Schema::create('telegram_identity_links', function (Blueprint $t) {
            $t->id();
            $t->uuid('connection_id');
            $t->foreign('connection_id')->references('id')->on('connections')->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('challenge_hash', 64)->nullable()->unique();
            $t->timestamp('expires_at')->nullable();
            $t->string('telegram_user_id', 24)->nullable();
            $t->boolean('confirmed')->default(false);
            $t->unique(['connection_id', 'user_id']);
            $t->unique(['connection_id', 'telegram_user_id']);
        });
        Schema::create('integration_trigger_subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->uuid('connection_id');
            $t->foreign(['connection_id', 'organization_id'])->references(['id', 'organization_id'])->on('connections')->restrictOnDelete();
            $t->foreignId('workflow_id')->constrained()->restrictOnDelete();
            $t->foreignId('workflow_version_id')->constrained()->restrictOnDelete();
            $t->uuid('node_id');
            $t->string('destination', 24);
            $t->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->unique(['workflow_version_id', 'node_id'], 'trigger_version_node_unique');
        });
        Schema::create('telegram_inbound_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('connection_id');
            $t->foreign('connection_id')->references('id')->on('connections')->restrictOnDelete();
            $t->unsignedBigInteger('update_id');
            $t->string('key_version', 40);
            $t->text('ciphertext');
            $t->timestamp('received_at');
            $t->unique(['connection_id', 'update_id']);
        });
        Schema::create('integration_trigger_activations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('event_id');
            $t->foreign('event_id')->references('id')->on('telegram_inbound_events')->restrictOnDelete();
            $t->uuid('subscription_id');
            $t->foreign('subscription_id')->references('id')->on('integration_trigger_subscriptions')->restrictOnDelete();
            $t->foreignId('workflow_instance_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('status', 16)->default('pending');
            $t->unsignedInteger('attempt_count')->default(0);
            $t->timestamp('available_at');
            $t->string('error_code', 40)->nullable();
            $t->timestamps();
            $t->unique(['event_id', 'subscription_id']);
            $t->index(['status', 'available_at']);
        });
        Schema::create('integration_action_outbox', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->uuid('connection_id');
            $t->foreign(['connection_id', 'organization_id'])->references(['id', 'organization_id'])->on('connections')->restrictOnDelete();
            $t->foreignId('workflow_instance_id')->constrained()->restrictOnDelete();
            $t->foreignId('workflow_version_id')->constrained()->restrictOnDelete();
            $t->uuid('node_id');
            $t->string('operation', 48);
            $t->string('destination', 24);
            $t->unsignedInteger('credential_generation');
            $t->string('key_version', 40);
            $t->text('ciphertext');
            $t->string('status', 16)->default('pending');
            $t->unsignedInteger('attempt_count')->default(0);
            $t->timestamp('available_at');
            $t->uuid('claim_token')->nullable();
            $t->timestamp('lease_expires_at')->nullable();
            $t->string('error_code', 40)->nullable();
            $t->string('provider_message_id', 32)->nullable();
            $t->timestamps();
            $t->unique(['workflow_instance_id', 'node_id'], 'outbox_instance_node_unique');
            $t->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only Telegram migration. Ship a corrective migration.');
    }
};
