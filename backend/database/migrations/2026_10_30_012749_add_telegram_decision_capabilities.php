<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('telegram_approval_deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->uuid('connection_id');
            $t->foreign(['connection_id', 'organization_id'])->references(['id', 'organization_id'])->on('connections')->restrictOnDelete();
            $t->foreignId('workflow_task_id')->constrained()->restrictOnDelete();
            $t->foreignId('workflow_version_id')->constrained()->restrictOnDelete();
            $t->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $t->string('destination', 24);
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
            $t->unique(['workflow_task_id', 'connection_id', 'destination'], 'telegram_approval_delivery_unique');
        });
        Schema::create('telegram_decision_capabilities', function (Blueprint $t) {
            $t->string('capability_hash', 64)->primary();
            $t->uuid('delivery_id');
            $t->foreign('delivery_id')->references('id')->on('telegram_approval_deliveries')->restrictOnDelete();
            $t->string('decision', 16);
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
        });
        Schema::create('telegram_decision_callbacks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('connection_id');
            $t->foreign('connection_id')->references('id')->on('connections')->restrictOnDelete();
            $t->unsignedBigInteger('update_id');
            $t->string('query_hash', 64);
            $t->string('key_version', 40);
            $t->text('ciphertext');
            $t->string('status', 16)->default('pending');
            $t->unsignedInteger('attempt_count')->default(0);
            $t->timestamp('available_at');
            $t->string('error_code', 40)->nullable();
            $t->timestamps();
            $t->unique(['connection_id', 'update_id']);
            $t->unique(['connection_id', 'query_hash']);
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
