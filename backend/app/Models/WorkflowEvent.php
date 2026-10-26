<?php

namespace App\Models;

use App\Enums\WorkflowEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RuntimeException;

#[Fillable([
    'organization_id',
    'workflow_instance_id',
    'actor_id',
    'event_type',
    'node_id',
    'event_payload',
    'created_at',
])]
class WorkflowEvent extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (WorkflowEvent $event): void {
            if (blank($event->uuid)) {
                $event->uuid = (string) Str::uuid();
            }

            $event->created_at ??= now();
        });

        static::updating(function (): void {
            throw new RuntimeException('Workflow events are append-only.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Workflow events are append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => WorkflowEventType::class,
            'event_payload' => 'array',
            'created_at' => 'datetime',
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
