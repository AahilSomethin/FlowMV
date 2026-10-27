<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationOutboxStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'organization_id',
    'workflow_instance_id',
    'node_id',
    'recipient_user_id',
    'channel',
    'delivery_key',
    'idempotency_key',
    'status',
    'attempt_count',
    'max_attempts',
    'available_at',
    'sent_at',
    'failed_at',
    'last_error',
    'payload',
])]
class NotificationOutbox extends Model
{
    protected $table = 'notification_outbox';

    protected static function booted(): void
    {
        static::creating(function (NotificationOutbox $row): void {
            if (blank($row->uuid)) {
                $row->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => NotificationOutboxStatus::class,
            'attempt_count' => 'integer',
            'max_attempts' => 'integer',
            'available_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'payload' => 'array',
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

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
