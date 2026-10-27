<?php

namespace App\Models;

use App\Enums\WorkflowDelayStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'organization_id',
    'workflow_instance_id',
    'node_id',
    'resume_at',
    'status',
    'consumed_at',
])]
class WorkflowDelay extends Model
{
    protected static function booted(): void
    {
        static::creating(function (WorkflowDelay $delay): void {
            if (blank($delay->uuid)) {
                $delay->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WorkflowDelayStatus::class,
            'resume_at' => 'datetime',
            'consumed_at' => 'datetime',
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
}
