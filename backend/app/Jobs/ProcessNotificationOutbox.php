<?php

namespace App\Jobs;

use App\Enums\NotificationChannel;
use App\Enums\NotificationOutboxStatus;
use App\Enums\WorkflowEventType;
use App\Mail\WorkflowNotificationMail;
use App\Models\NotificationOutbox;
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
            $recipient = User::query()->findOrFail($claimed->recipient_user_id);
            $payload = is_array($claimed->payload) ? $claimed->payload : [];

            if ($claimed->channel === NotificationChannel::InApp) {
                $recipient->notify(new WorkflowInAppNotification($payload, $claimed->idempotency_key));
            } else {
                Mail::to($recipient->email)->send(new WorkflowNotificationMail($payload, $claimed->idempotency_key));
            }

            $claimed->status = NotificationOutboxStatus::Sent;
            $claimed->sent_at = now();
            $claimed->last_error = null;
            $claimed->save();

            $this->audit($claimed, $events, WorkflowEventType::NotificationSent, [
                'outbox_uuid' => $claimed->uuid,
                'channel' => $claimed->channel->value,
                'recipient_user_id' => $claimed->recipient_user_id,
            ]);
        } catch (Throwable $exception) {
            $this->failDelivery($claimed, $events, $exception->getMessage());
        }
    }

    private function failDelivery(NotificationOutbox $row, WorkflowEventWriter $events, string $message): void
    {
        $permanent = $row->attempt_count >= $row->max_attempts;
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
