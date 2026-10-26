<?php

namespace App\Models;

use Database\Factories\WorkflowDraftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['workflow_id', 'revision', 'graph_definition', 'form_definition', 'content_hash', 'updated_by'])]
class WorkflowDraft extends Model
{
    /** @use HasFactory<WorkflowDraftFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (WorkflowDraft $draft): void {
            if (blank($draft->uuid)) {
                $draft->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'graph_definition' => 'array',
            'form_definition' => 'array',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
