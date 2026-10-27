<?php

namespace App\Jobs;

use App\Enums\NotificationChannel;
use App\Enums\NotificationOutboxStatus;
use App\Enums\WorkflowEventType;
use App\Mail\WorkflowNotificationMail;
use App\Models\NotificationOutbox;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Notifications\WorkflowInAppNotification;
use App\Services\Workflows\Execution\WorkflowEventWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ProcessNotificationOutbox implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly int $instanceId,
        public readonly string $nodeId,
    ) {
        $this->afterCommit = true;
    }

    public function handle(WorkflowEventWriter $events): void
    {
        $rows = NotificationOutbox::query()
            ->where('workflow_instance_id', $this->instanceId)
            ->where('node_id', $this->nodeId)
            ->where('status', NotificationOutboxStatus::Pending)
            ->where('available_at', '<=', now())
            ->orderBy('id')
            ->limit(100)
            ->get();

        foreach ($rows as $row) {
            $this->deliver($row, $events);
        }
    }

    private function deliver(NotificationOutbox $row, WorkflowEventWriter $events): void
    {
        $claimed = DB::transaction(function () use ($row) {
            $locked = NotificationOutbox::query()->whereKey($row->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== NotificationOutboxStatus::Pending) {
                return null;
            }

            if ($locked->available_at !== null && $locked->available_at->isFuture()) {
                return null;
            }

            $locked->status = NotificationOutboxStatus::Processing;
            $locked->attempt_count = $locked->attempt_count + 1;
            $locked->save();

            return $locked;
        });

        if ($claimed === null) {
            return;
        }

        try {
            if ($claimed->attempt_count > $claimed->max_attempts) {
                $this->failDelivery($claimed, $events, 'Delivery attempt limit exhausted after an interrupted worker.');

                return;
            }
            $recipient = User::query()->findOrFail($claimed->recipient_user_id);
            if (! OrganizationMembership::query()->where('organization_id', $claimed->organization_id)
                ->where('user_id', $recipient->id)->whereNull('revoked_at')->exists()) {
                $this->failDelivery($claimed, $events, 'Recipient no longer has an active workspace membership.', true);

                return;
            }
            $payload = is_array($claimed->payload) ? $claimed->payload : [];

            if ($claimed->channel === NotificationChannel::InApp) {
                $hash = hash('sha256', $claimed->idempotency_key);
                $notificationId = substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-'.substr($hash, 12, 4).'-'.substr($hash, 16, 4).'-'.substr($hash, 20, 12);
                $recipient->notifications()->firstOrCreate(['id' => $notificationId], [
                    'type' => WorkflowInAppNotification::class,
                    'data' => (new WorkflowInAppNotification($payload, $claimed->idempotency_key))->toArray($recipient),
                    'read_at' => null,
                ]);
            } else {
                Mail::to($recipient->email)->send(new WorkflowNotificationMail($payload, $claimed->idempotency_key));
            }

            DB::transaction(function () use ($claimed, $events): void {
                $owned = NotificationOutbox::query()->whereKey($claimed->id)
                    ->where('status', NotificationOutboxStatus::Processing)
                    ->where('attempt_count', $claimed->attempt_count)->lockForUpdate()->first();
                if ($owned === null) {
                    return;
                }
                $owned->forceFill(['status' => NotificationOutboxStatus::Sent, 'sent_at' => now(), 'last_error' => null])->save();
                $this->audit($owned, $events, WorkflowEventType::NotificationSent, [
                    'outbox_uuid' => $owned->uuid,
                    'channel' => $owned->channel->value,
                    'recipient_user_id' => $owned->recipient_user_id,
                ]);
            });
        } catch (Throwable $exception) {
            $this->failDelivery($claimed, $events, $exception->getMessage());
        }
    }

    private function failDelivery(NotificationOutbox $row, WorkflowEventWriter $events, string $message, bool $forcePermanent = false): void
    {
        DB::transaction(function () use ($row, $events, $message, $forcePermanent): void {
            $owned = NotificationOutbox::query()->whereKey($row->id)
                ->where('status', NotificationOutboxStatus::Processing)
                ->where('attempt_count', $row->attempt_count)->lockForUpdate()->first();
            if ($owned === null) {
                return;
            }
            $row = $owned;
            $permanent = $forcePermanent || $row->attempt_count >= $row->max_attempts;
            $row->last_error = mb_substr($message, 0, 1000);

            if ($permanent) {
                $row->status = NotificationOutboxStatus::Failed;
                $row->failed_at = now();
                $row->save();
                $this->audit($row, $events, WorkflowEventType::NotificationFailed, [
                    'outbox_uuid' => $row->uuid,
                    'channel' => $row->channel->value,
                    'recipient_user_id' => $row->recipient_user_id,
                    'permanent' => true,
                ]);

                return;
            }

            $delay = min(3600, 2 ** max(0, $row->attempt_count - 1));
            $row->status = NotificationOutboxStatus::Pending;
            $row->available_at = now()->addSeconds($delay);
            $row->save();

            ProcessNotificationOutbox::dispatch($row->workflow_instance_id, $row->node_id)
                ->delay($row->available_at);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audit(NotificationOutbox $row, WorkflowEventWriter $events, WorkflowEventType $type, array $payload): void
    {
        $instance = WorkflowInstance::query()->find($row->workflow_instance_id);

        if ($instance === null) {
            return;
        }

        $actor = User::query()->find($instance->submitted_by) ?? User::query()->find($row->recipient_user_id);

        if ($actor === null) {
            return;
        }

        $events->record($instance, $actor, $type, $row->node_id, $payload);
    }
}
