<?php

namespace App\Models;

use App\Enums\SnapshotReason;
use App\Enums\WorkflowTaskDecision;
use App\Enums\WorkflowTaskStatus;
use App\Enums\WorkflowTaskType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use RuntimeException;

#[Fillable([
    'organization_id',
    'workflow_instance_id',
    'node_id',
    'task_type',
    'assigned_role',
    'assigned_user_id',
    'status',
    'decision',
    'assigned_at',
    'completed_at',
    'completed_by',
])]
class WorkflowTask extends Model
{
    protected static function booted(): void
    {
        static::creating(function (WorkflowTask $task): void {
            if (blank($task->uuid)) {
                $task->uuid = (string) Str::uuid();
            }
        });

        static::updating(function (WorkflowTask $task): void {
            $original = $task->getOriginal('status');
            $originalValue = $original instanceof WorkflowTaskStatus ? $original->value : $original;

            if ($originalValue === WorkflowTaskStatus::Completed->value) {
                throw new RuntimeException('Completed workflow tasks cannot be changed.');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Workflow tasks cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task_type' => WorkflowTaskType::class,
            'status' => WorkflowTaskStatus::class,
            'decision' => WorkflowTaskDecision::class,
            'assigned_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function decisionSnapshot(): HasOne
    {
        return $this->hasOne(WorkflowSubmissionSnapshot::class, 'workflow_task_id')
            ->where('reason', SnapshotReason::ApprovalDecision->value);
    }
}
