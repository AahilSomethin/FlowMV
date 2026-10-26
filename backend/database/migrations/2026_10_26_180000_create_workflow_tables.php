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

        Schema::create('workflows', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'updated_at']);
        });

        Schema::create('workflow_versions', function (Blueprint $table) use ($pgsql): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $this->json($table, 'graph_definition', $pgsql);
            $this->json($table, 'form_definition', $pgsql);
            $table->char('content_hash', 64);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['workflow_id', 'version_number']);
            $table->index(['workflow_id', 'published_at']);
        });

        Schema::create('workflow_drafts', function (Blueprint $table) use ($pgsql): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workflow_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $this->json($table, 'graph_definition', $pgsql);
            $this->json($table, 'form_definition', $pgsql);
            $table->char('content_hash', 64);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('workflows', function (Blueprint $table): void {
            $table->foreignId('current_published_version_id')
                ->nullable()
                ->constrained('workflow_versions')
                ->nullOnDelete();
        });

        if ($pgsql) {
            DB::statement("alter table workflows add constraint workflows_status_check check (status in ('draft', 'published', 'archived'))");
            DB::statement('alter table workflow_versions add constraint workflow_versions_number_check check (version_number >= 1)');
            DB::statement('alter table workflow_drafts add constraint workflow_drafts_revision_check check (revision >= 1)');
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION workflow_versions_reject_update()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION 'Published workflow versions are immutable';
                END;
                $$;

                CREATE TRIGGER workflow_versions_reject_update
                BEFORE UPDATE ON workflow_versions
                FOR EACH ROW
                EXECUTE PROCEDURE workflow_versions_reject_update();
            SQL);
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS workflow_versions_reject_update ON workflow_versions');
            DB::unprepared('DROP FUNCTION IF EXISTS workflow_versions_reject_update()');
        }

        Schema::table('workflows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_published_version_id');
        });

        Schema::dropIfExists('workflow_drafts');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflows');
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
