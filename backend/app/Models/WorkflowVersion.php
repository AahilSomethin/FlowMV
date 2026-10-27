<?php

namespace App\Models;

use Database\Factories\WorkflowVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Immutable published snapshot.
 *
 * Updates through Eloquent are rejected by WorkflowVersionBuilder on every
 * driver. PostgreSQL additionally rejects UPDATE statements with a trigger.
 * The sqlite test database does not install that trigger.
 */
#[Fillable([
    'workflow_id',
    'version_number',
    'graph_definition',
    'form_definition',
    'content_hash',
    'node_registry_version',
    'published_by',
    'published_at',
])]
class WorkflowVersion extends Model
{
    /** @use HasFactory<WorkflowVersionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (WorkflowVersion $version): void {
            if (blank($version->uuid)) {
                $version->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return WorkflowVersionBuilder
     */
    public function newEloquentBuilder($query): Builder
    {
        return new WorkflowVersionBuilder($query);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'node_registry_version' => 'integer',
            'graph_definition' => 'array',
            'form_definition' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
