<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_datasets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $t->string('recipe_version', 40)->unique();
            $t->string('state', 20);
            $t->json('manifest');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only demo ownership migration.');
    }
};
