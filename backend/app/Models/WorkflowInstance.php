<?php

namespace App\Models;

use App\Enums\WorkflowFailureCode;
use App\Enums\WorkflowInstanceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use RuntimeException;

#[Fillable([
    'organization_id',
    'workflow_id',
    'workflow_version_id',
    'submitted_by',
    'status',
    'failure_code',
    'current_node_id',
    'submitted_data',
    'started_at',
    'completed_at',
    'resume_at',
])]
class WorkflowInstance extends Model
{
    protected static function booted(): void
    {
        static::creating(function (WorkflowInstance $instance): void {
            if (blank($instance->uuid)) {
                $instance->uuid = (string) Str::uuid();
            }
        });

        static::updating(function (WorkflowInstance $instance): void {
            foreach (['uuid', 'organization_id', 'workflow_id', 'workflow_version_id', 'submitted_by', 'started_at'] as $column) {
                if ($instance->isDirty($column)) {
                    throw new RuntimeException('Workflow instance identity cannot change.');
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WorkflowInstanceStatus::class,
            'failure_code' => WorkflowFailureCode::class,
            'submitted_data' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'resume_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(WorkflowTask::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(WorkflowEvent::class);
    }

    public function revisionCounters(): HasMany
    {
        return $this->hasMany(WorkflowRevisionCounter::class);
    }
}
