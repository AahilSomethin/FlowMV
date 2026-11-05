<?php

namespace App\Services\Integrations;

use App\Actions\WorkflowInstances\DecideWorkflowTask;
use App\Enums\WorkflowEventType;
use App\Exceptions\TelegramApiFailure;
use App\Jobs\ProcessTelegramApproval;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Models\WorkflowVersion;
use App\Services\Workflows\Execution\PublishedGraph;
use App\Services\Workflows\Execution\WorkflowEventWriter;
use App\Services\Workflows\Execution\WorkflowInstanceVisibility;
use App\Support\DemoEnvironment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class TelegramDecisions
{
    public function __construct(private TelegramAuthorization $authorization, private CredentialVault $vault, private TelegramApi $api, private ConnectionManager $audit) {}

    public function publication(int $org, int $workflow, array $node): void
    {
        $d = $node['data']['telegram_approval'];
        $c = $this->authorization->workflow($org, $workflow, $d['connection_id'], 'telegram.approval', $d['destination']);
        $link = $this->recipient($c, $d['destination']);
        abort_unless(in_array(app(ConnectionAccess::class)->role($link->user_id, $org), $node['data']['assignee_roles'], true), 403);
    }

    private function recipient(object $c, string $destination): object
    {
        $d = $this->authorization->destination($c, $destination);
        abort_unless($d->chat_type === 'private' && preg_match('/^[0-9]{1,20}$/D', $destination), 403);
        $link = DB::table('telegram_identity_links')->where('connection_id', $c->id)->where('telegram_user_id', $destination)->where('confirmed', true)->first();
        abort_unless($link, 403);
        app(ConnectionAccess::class)->role($link->user_id, $c->organization_id);

        return $link;
    }

    public function enqueue(WorkflowInstance $instance, array $node): void
    {
        $version = WorkflowVersion::findOrFail($instance->workflow_version_id);
        abort_unless(in_array($version->node_registry_version, [3, 4], true) && PublishedGraph::fromVersion($version)->node($node['id']) === $node, 403);
        $d = $node['data']['telegram_approval'];
        $c = $this->authorization->workflow($instance->organization_id, $instance->workflow_id, $d['connection_id'], 'telegram.approval', $d['destination'], $instance->submitted_by);
        $link = $this->recipient($c, $d['destination']);
        $task = $instance->tasks()->where('node_id', $node['id'])->where('status', 'open')->lockForUpdate()->firstOrFail();
        abort_unless(app(WorkflowInstanceVisibility::class)->canDecide(User::findOrFail($link->user_id), $task), 403);
        if (DB::table('telegram_approval_deliveries')->where('workflow_task_id', $task->id)->where('connection_id', $c->id)->where('destination', $d['destination'])->exists()) {
            return;
        }
        $id = (string) Str::uuid();
        $bot = DB::table('telegram_bots')->where('connection_id', $c->id)->first();
        $buttons = [];
        $caps = [];
        foreach (['approved' => 'Approve', 'rejected' => 'Reject'] as $decision => $label) {
            $capability = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
            $caps[] = ['capability_hash' => hash('sha256', $capability), 'delivery_id' => $id, 'decision' => $decision, 'expires_at' => now()->addMinutes(10)];
            $buttons[] = ['text' => $label, 'callback_data' => 'fmv:'.$capability];
        }
        $origin = rtrim((string) config('flowmv.frontend_url'), '/');
        if (app()->environment('demo')) {
            app(DemoEnvironment::class)->assertOwned();
        }
        abort_unless(str_starts_with($origin, 'https://') || app()->environment(['testing', 'phase9b', 'phase9d', 'demo']), 503);
        $keyboard = [$buttons, [['text' => 'Open full request in FlowMV', 'url' => $origin.'/requests/'.$instance->uuid]]];
        $rejected = PublishedGraph::fromVersion($version)->successor($node['id'], 'rejected');
        if (($rejected && PublishedGraph::fromVersion($version)->node($rejected)['type'] === 'revision')) {
            $keyboard[] = [['text' => 'Request revision in FlowMV', 'url' => $origin.'/tasks']];
        }
        DB::table('telegram_approval_deliveries')->insert(['id' => $id, 'organization_id' => $instance->organization_id, 'connection_id' => $c->id, 'workflow_task_id' => $task->id, 'workflow_version_id' => $version->id, 'recipient_user_id' => $link->user_id, 'destination' => $d['destination'], 'credential_generation' => $bot->credential_generation, ...$this->vault->encrypt(['text' => 'A FlowMV approval needs your decision. Review the full request before deciding. These buttons expire in ten minutes.', 'keyboard' => $keyboard], 'telegram-approval:'.$id), 'status' => 'pending', 'attempt_count' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('telegram_decision_capabilities')->insert($caps);
        $this->audit->audit($instance->organization_id, $c->id, $instance->submitted_by, 'telegram.approval.queued');
        app(WorkflowEventWriter::class)->record($instance, User::find($instance->submitted_by), WorkflowEventType::TelegramApprovalQueued, $task->node_id, ['delivery_uuid' => $id, 'task_uuid' => $task->uuid]);
        ProcessTelegramApproval::dispatch($id)->afterCommit();
    }

    private function guard(object $delivery): array
    {
        $task = WorkflowTask::findOrFail($delivery->workflow_task_id);
        $instance = WorkflowInstance::whereKey($task->workflow_instance_id)->lockForUpdate()->firstOrFail();
        $task = WorkflowTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
        abort_unless($instance->organization_id === $delivery->organization_id && $task->organization_id === $delivery->organization_id && $instance->workflow_version_id === $delivery->workflow_version_id && $instance->current_node_id === $task->node_id && $task->status->value === 'open' && $task->task_type->value === 'approval' && $instance->status->value === 'awaiting_approval', 403);
        $version = WorkflowVersion::findOrFail($delivery->workflow_version_id);
        $node = PublishedGraph::fromVersion($version)->node($task->node_id);
        abort_unless(in_array($version->node_registry_version, [3, 4], true) && $version->workflow_id === $instance->workflow_id && ($node['type'] ?? '') === 'approval' && ($node['data']['telegram_approval']['connection_id'] ?? '') === $delivery->connection_id && ($node['data']['telegram_approval']['destination'] ?? '') === $delivery->destination, 403);
        $c = $this->authorization->workflow($instance->organization_id, $instance->workflow_id, $delivery->connection_id, 'telegram.approval', $delivery->destination, $instance->submitted_by);
        $bot = DB::table('telegram_bots')->where('connection_id', $c->id)->first();
        abort_unless((int) $bot->credential_generation === (int) $delivery->credential_generation, 403);
        $link = $this->recipient($c, $delivery->destination);
        abort_unless((int) $link->user_id === (int) $delivery->recipient_user_id, 403);
        $user = User::findOrFail($link->user_id);
        abort_unless(app(WorkflowInstanceVisibility::class)->canDecide($user, $task), 403);

        return [$user, $task, $instance, $link];
    }

    public function deliver(string $id): void
    {
        $claim = DB::transaction(function () use ($id) {
            $row = DB::table('telegram_approval_deliveries')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending' || Carbon::parse($row->available_at)->isFuture() || $row->attempt_count >= 5) {
                return null;
            }
            $claim = (string) Str::uuid();
            DB::table('telegram_approval_deliveries')->where('id', $id)->update(['status' => 'processing', 'claim_token' => $claim, 'lease_expires_at' => now()->addSeconds(120), 'attempt_count' => $row->attempt_count + 1, 'updated_at' => now()]);

            return $claim;
        });
        if (! $claim) {
            return;
        }
        $attempted = false;
        try {
            DB::transaction(function () use ($id, $claim, &$attempted) {
                $row = DB::table('telegram_approval_deliveries')->where('id', $id)->where('claim_token', $claim)->where('status', 'processing')->lockForUpdate()->first();
                if (! $row) {
                    return;
                }
                [, $task, $instance] = $this->guard($row);
                abort_unless(DB::table('telegram_decision_capabilities')->where('delivery_id', $id)->whereNull('consumed_at')->where('expires_at', '>', now())->count() === 2, 403);
                $rate = 'telegram:bot:'.$row->connection_id;
                $chatRate = 'telegram:chat:'.$row->connection_id.':'.$row->destination;
                if (RateLimiter::tooManyAttempts($rate, 20) || RateLimiter::tooManyAttempts($chatRate, 10)) {
                    DB::table('telegram_approval_deliveries')->where('id', $id)->update(['status' => 'pending', 'attempt_count' => $row->attempt_count - 1, 'available_at' => now()->addMinute(), 'claim_token' => null, 'lease_expires_at' => null]);

                    return;
                }
                RateLimiter::hit($rate, 60);
                RateLimiter::hit($chatRate, 60);
                $payload = $this->vault->decrypt($row, 'telegram-approval:'.$id);
                try {
                    $attempted = true;
                    $result = $this->api->call($this->authorization->credentials($row->connection_id)['bot_token'], 'sendMessage', ['chat_id' => $row->destination, 'text' => $payload['text'], 'protect_content' => true, 'link_preview_options' => ['is_disabled' => true], 'reply_markup' => ['inline_keyboard' => $payload['keyboard']]]);
                    if (! is_array($result) || ! isset($result['message_id'])) {
                        throw new TelegramApiFailure('invalid_response', ambiguous: true);
                    }
                    $status = 'accepted';
                    $code = null;
                    $message = (string) $result['message_id'];
                    $available = now();
                } catch (TelegramApiFailure $failure) {
                    $status = ($failure->ambiguous || $failure->reason === 'provider_unavailable') ? 'uncertain' : ($failure->retryable && $row->attempt_count < 5 ? 'pending' : 'failed');
                    $code = $failure->reason;
                    $message = null;
                    $available = now()->addSeconds(max(10, $failure->retryAfter, $row->attempt_count * 30));
                }
                DB::table('telegram_approval_deliveries')->where('id', $id)->update(['status' => $status, 'error_code' => $code, 'provider_message_id' => $message, 'available_at' => $available, 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
                $this->audit->audit($row->organization_id, $row->connection_id, null, 'telegram.approval.'.$status);
                if ($status === 'accepted') {
                    app(WorkflowEventWriter::class)->record($instance, null, WorkflowEventType::TelegramApprovalAccepted, $task->node_id, ['delivery_uuid' => $id, 'task_uuid' => $task->uuid]);
                }
            });
        } catch (\Throwable) {
            $changed = DB::table('telegram_approval_deliveries')->where('id', $id)->where('claim_token', $claim)->where('status', 'processing')->update(['status' => $attempted ? 'uncertain' : 'cancelled', 'error_code' => $attempted ? 'interrupted_delivery' : 'authorization_or_expiry', 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
            if ($changed) {
                $row = DB::table('telegram_approval_deliveries')->where('id', $id)->first();
                $this->audit->audit($row->organization_id, $row->connection_id, null, 'telegram.approval.'.($attempted ? 'uncertain' : 'cancelled'));
            }
        }
    }

    public function receive(object $connection, array $update): array
    {
        $q = $update['callback_query'] ?? [];
        $updateId = $update['update_id'] ?? null;
        if (! is_int($updateId) || $updateId < 0 || ! is_array($q) || ! is_string($q['id'] ?? null) || $q['id'] === '' || strlen($q['id']) > 256 || ! is_string($q['data'] ?? null) || ! preg_match('/^fmv:[A-Za-z0-9_-]{32}$/D', $q['data']) || ! is_int($q['from']['id'] ?? null) || ($q['from']['is_bot'] ?? true) !== false || ! is_int($q['message']['chat']['id'] ?? null) || ! is_int($q['message']['message_id'] ?? null)) {
            return ['accepted' => false];
        }
        $bot = DB::table('telegram_bots')->where('connection_id', $connection->id)->first();
        if (($q['message']['from']['is_bot'] ?? false) !== true || (string) ($q['message']['from']['id'] ?? '') !== $bot->bot_id) {
            return ['accepted' => false];
        }
        if (DB::table('telegram_decision_callbacks')->where('connection_id', $connection->id)->where(fn ($query) => $query->where('update_id', $updateId)->orWhere('query_hash', hash('sha256', $q['id'])))->exists()) {
            return ['accepted' => true, 'duplicate' => true];
        }
        $id = (string) Str::uuid();
        $payload = ['query_id' => $q['id'], 'capability' => substr($q['data'], 4), 'sender_id' => (string) $q['from']['id'], 'destination' => (string) $q['message']['chat']['id'], 'message_id' => (string) $q['message']['message_id']];
        DB::table('telegram_decision_callbacks')->insert(['id' => $id, 'connection_id' => $connection->id, 'update_id' => $updateId, 'query_hash' => hash('sha256', $q['id']), ...$this->vault->encrypt($payload, 'telegram-callback:'.$id), 'status' => 'pending', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        ProcessTelegramApproval::dispatch($id, true)->afterCommit();

        return ['accepted' => true];
    }

    public function process(string $id): void
    {
        try {
            DB::transaction(function () use ($id) {
                $receipt = DB::table('telegram_decision_callbacks')->where('id', $id)->lockForUpdate()->first();
                if (! $receipt || $receipt->status !== 'pending') {
                    return;
                }
                $payload = $this->vault->decrypt($receipt, 'telegram-callback:'.$id);
                $cap = DB::table('telegram_decision_capabilities')->where('capability_hash', hash('sha256', $payload['capability']))->lockForUpdate()->first();
                abort_unless($cap && $cap->consumed_at === null && Carbon::parse($cap->expires_at)->isFuture(), 403);
                $delivery = DB::table('telegram_approval_deliveries')->where('id', $cap->delivery_id)->lockForUpdate()->first();
                abort_unless($delivery && $delivery->connection_id === $receipt->connection_id && $delivery->status === 'accepted' && $delivery->destination === $payload['destination'] && $delivery->provider_message_id === $payload['message_id'], 403);
                [$actor, $task, $instance, $link] = $this->guard($delivery);
                abort_unless($link->telegram_user_id === $payload['sender_id'] && $payload['sender_id'] === $delivery->destination, 403);
                $decided = app(DecideWorkflowTask::class)($actor, $task, $cap->decision);
                app(WorkflowEventWriter::class)->record($decided, $actor, WorkflowEventType::TelegramDecisionRecorded, $task->node_id, ['task_uuid' => $task->uuid, 'callback_uuid' => $receipt->id]);
                $deliveries = DB::table('telegram_approval_deliveries')->where('workflow_task_id', $task->id)->pluck('id');
                DB::table('telegram_decision_capabilities')->whereIn('delivery_id', $deliveries)->whereNull('consumed_at')->update(['consumed_at' => now()]);
                DB::table('telegram_decision_callbacks')->where('id', $id)->update(['status' => 'done', 'attempt_count' => $receipt->attempt_count + 1, 'updated_at' => now()]);
                $this->audit->audit($instance->organization_id, $receipt->connection_id, $actor->id, 'telegram.decision.accepted');
            }, 3);
        } catch (\Throwable) {
            $changed = DB::table('telegram_decision_callbacks')->where('id', $id)->where('status', 'pending')->update(['status' => 'rejected', 'error_code' => 'decision_unavailable', 'updated_at' => now()]);
            if ($changed) {
                $receipt = DB::table('telegram_decision_callbacks')->where('id', $id)->first();
                $connection = DB::table('connections')->where('id', $receipt->connection_id)->first();
                $this->audit->audit($connection->organization_id, $connection->id, null, 'telegram.decision.rejected');
            }
        }
        $receipt = DB::table('telegram_decision_callbacks')->where('id', $id)->first();
        if ($receipt) {
            try {
                $c = DB::table('connections')->where('id', $receipt->connection_id)->first();
                $this->authorization->connection($c->organization_id, $c->id);
                $payload = $this->vault->decrypt($receipt, 'telegram-callback:'.$id);
                $this->api->call($this->authorization->credentials($c->id)['bot_token'], 'answerCallbackQuery', ['callback_query_id' => $payload['query_id'], 'text' => $receipt->status === 'done' ? 'Decision recorded in FlowMV.' : 'This decision is unavailable. Open FlowMV to review the task.', 'show_alert' => true, 'cache_time' => 0]);
            } catch (\Throwable) {
                // A confirmation transport failure never repeats the internal decision.
            }
        }
    }

    public function recover(): void
    {
        foreach (DB::table('telegram_approval_deliveries')->where('status', 'processing')->where('lease_expires_at', '<=', now())->limit(100)->get() as $row) {
            DB::transaction(function () use ($row) {
                $changed = DB::table('telegram_approval_deliveries')->where('id', $row->id)->where('status', 'processing')->where('claim_token', $row->claim_token)->where('lease_expires_at', '<=', now())->update(['status' => 'uncertain', 'error_code' => 'interrupted_delivery', 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
                if ($changed) {
                    $this->audit->audit($row->organization_id, $row->connection_id, null, 'telegram.approval.uncertain');
                }
            });
        }
        foreach (DB::table('telegram_approval_deliveries')->where('status', 'pending')->where('available_at', '<=', now())->where('attempt_count', '<', 5)->limit(100)->pluck('id') as $id) {
            ProcessTelegramApproval::dispatch($id);
        }
        foreach (DB::table('telegram_decision_callbacks')->where('status', 'pending')->where('available_at', '<=', now())->limit(100)->pluck('id') as $id) {
            ProcessTelegramApproval::dispatch($id, true);
        }
    }
}
