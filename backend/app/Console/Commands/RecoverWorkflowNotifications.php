<?php

namespace App\Console\Commands;

use App\Enums\NotificationOutboxStatus;
use App\Jobs\ProcessNotificationOutbox;
use App\Models\NotificationOutbox;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('workflow:recover-notifications')]
#[Description('Recover expired delivery claims and enqueue due notification outbox groups')]
class RecoverWorkflowNotifications extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $recovered = NotificationOutbox::query()
            ->where('status', NotificationOutboxStatus::Processing)
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->update(['status' => NotificationOutboxStatus::Pending->value, 'available_at' => now(), 'updated_at' => now()]);

        $groups = NotificationOutbox::query()
            ->where('status', NotificationOutboxStatus::Pending)
            ->where('available_at', '<=', now())
            ->select('workflow_instance_id', 'node_id')->distinct()->limit(100)->get();

        foreach ($groups as $group) {
            ProcessNotificationOutbox::dispatch($group->workflow_instance_id, $group->node_id);
        }

        $this->info("Recovered {$recovered} expired claims; queued {$groups->count()} due groups.");

        return self::SUCCESS;
    }
}
