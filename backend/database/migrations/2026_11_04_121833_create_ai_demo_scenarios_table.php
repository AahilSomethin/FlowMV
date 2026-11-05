<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_demo_scenarios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->string('recipe', 40);
            $t->string('scenario', 80);
            $t->json('references');
            $t->timestamp('created_at');
            $t->unique(['organization_id', 'recipe', 'scenario']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('AI demo extension migration is forward-only.');
    }
};
