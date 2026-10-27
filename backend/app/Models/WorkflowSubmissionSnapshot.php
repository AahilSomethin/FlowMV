<?php

namespace App\Models;

use App\Enums\SnapshotReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RuntimeException;

#[Fillable([
    'organization_id',
    'workflow_instance_id',
    'workflow_task_id',
    'reason',
    'submitted_data',
    'captured_at',
])]
#[Hidden(['submitted_data'])]
class WorkflowSubmissionSnapshot extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (WorkflowSubmissionSnapshot $snapshot): void {
            if (blank($snapshot->uuid)) {
                $snapshot->uuid = (string) Str::uuid();
            }

            $snapshot->captured_at ??= now();
        });

        static::updating(function (): void {
            throw new RuntimeException('Submission snapshots are append-only.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Submission snapshots are append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => SnapshotReason::class,
            'submitted_data' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    /**
     * Decision-time payload for a response that is already allowed to show the parent request.
     *
     * @return array{captured_at: mixed, submitted_data: array<string, mixed>}
     */
    public function decisionPayload(): array
    {
        return [
            'captured_at' => $this->captured_at,
            'submitted_data' => $this->submitted_data ?? [],
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(WorkflowTask::class, 'workflow_task_id');
    }
}
