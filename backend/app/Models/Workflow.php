<?php

namespace App\Models;

use App\Enums\WorkflowStatus;
use Database\Factories\WorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['organization_id', 'name', 'description', 'status', 'created_by', 'current_published_version_id'])]
class Workflow extends Model
{
    /** @use HasFactory<WorkflowFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Workflow $workflow): void {
            if (blank($workflow->uuid)) {
                $workflow->uuid = (string) Str::uuid();
            }
        });

        static::deleting(function (Workflow $workflow): void {
            if ($workflow->current_published_version_id !== null) {
                $workflow->forceFill(['current_published_version_id' => null])->saveQuietly();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WorkflowStatus::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function draft(): HasOne
    {
        return $this->hasOne(WorkflowDraft::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class);
    }

    public function currentPublishedVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'current_published_version_id');
    }

    /**
     * Lifecycle status stays published while a newer draft exists.
     * Availability for a future execution engine follows status === published.
     */
    public function hasUnpublishedChanges(): bool
    {
        $draft = $this->draft;
        $published = $this->currentPublishedVersion;

        if ($draft === null) {
            return false;
        }

        if ($published === null) {
            return true;
        }

        return $draft->content_hash !== $published->content_hash;
    }
}
