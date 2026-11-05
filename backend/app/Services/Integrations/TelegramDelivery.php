<?php

namespace App\Services\Integrations;

use App\Enums\WorkflowEventType;
use App\Exceptions\TelegramApiFailure;
use App\Jobs\ActivateTelegramEvent;
use App\Jobs\ProcessTelegramDelivery;
use App\Models\WorkflowInstance;
use App\Models\WorkflowVersion;
use App\Services\Workflows\Execution\PublishedGraph;
use App\Services\Workflows\Execution\WorkflowEventWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class TelegramDelivery
{
    public function __construct(private TelegramAuthorization $authorization, private TelegramApi $api, private CredentialVault $vault, private TelegramWorkflow $workflow, private WorkflowEventWriter $events, private ConnectionManager $audit) {}

    public function process(string $id): void
    {
        $claim = DB::transaction(function () use ($id) {
            $row = DB::table('integration_action_outbox')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending' || $row->available_at > now()->toDateTimeString()) {
                return null;
            }
            if ($row->attempt_count >= 5) {
                DB::table('integration_action_outbox')->where('id', $id)->update(['status' => 'failed', 'error_code' => 'attempt_limit', 'updated_at' => now()]);

                return null;
            }
            $claim = (string) Str::uuid();
            DB::table('integration_action_outbox')->where('id', $id)->update(['status' => 'processing', 'claim_token' => $claim, 'lease_expires_at' => now()->addSeconds(120), 'attempt_count' => $row->attempt_count + 1, 'updated_at' => now()]);

            return $claim;
        });
        if (! $claim) {
            return;
        }
        $attempted = false;
        try {
            DB::transaction(function () use ($id, $claim, &$attempted) {
                $row = DB::table('integration_action_outbox')->where('id', $id)->where('status', 'processing')->where('claim_token', $claim)->lockForUpdate()->first();
                if (! $row) {
                    return;
                }
                $instance = WorkflowInstance::findOrFail($row->workflow_instance_id);
                $version = WorkflowVersion::findOrFail($row->workflow_version_id);
                abort_unless($instance->workflow_version_id === $version->id && $instance->organization_id === $row->organization_id && $version->workflow_id === $instance->workflow_id && in_array($version->node_registry_version, [3, 4], true) && $instance->status->value !== 'failed', 403);
                $node = PublishedGraph::fromVersion($version)->node($row->node_id);
                abort_unless($node && in_array($node['type'], ['telegram_send_message', 'telegram_notification'], true) && $this->workflow->operation($node['type']) === $row->operation && $node['data']['connection_id'] === $row->connection_id && $node['data']['destination'] === $row->destination, 403);
                $this->authorization->workflow($row->organization_id, $instance->workflow_id, $row->connection_id, $row->operation, $row->destination, $instance->submitted_by);
                $bot = DB::table('telegram_bots')->where('connection_id', $row->connection_id)->first();
                abort_unless((int) $bot->credential_generation === (int) $row->credential_generation, 403);
                $botKey = 'telegram:bot:'.$row->connection_id;
                $chatKey = 'telegram:chat:'.$row->connection_id.':'.$row->destination;
                if (RateLimiter::tooManyAttempts($botKey, 20) || RateLimiter::tooManyAttempts($chatKey, 10)) {
                    DB::table('integration_action_outbox')->where('id', $id)->update(['status' => 'pending', 'attempt_count' => $row->attempt_count - 1, 'available_at' => now()->addMinute(), 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);

                    return;
                }
                RateLimiter::hit($botKey, 60);
                RateLimiter::hit($chatKey, 60);
                $payload = $this->vault->decrypt($row, $row->connection_id.':action:'.$id);
                try {
                    $attempted = true;
                    $messageId = $this->api->send($this->authorization->credentials($row->connection_id), $row->destination, $payload['text']);
                    $status = 'sent';
                    $code = null;
                    $available = now();
                } catch (TelegramApiFailure $failure) {
                    $status = $failure->ambiguous ? 'uncertain' : ($failure->retryable && $row->attempt_count < 5 ? 'pending' : 'failed');
                    $code = $failure->reason;
                    $messageId = null;
                    $available = now()->addSeconds(max($failure->retryAfter, [10, 30, 60, 300, 300][min(4, $row->attempt_count - 1)]));
                }
                DB::table('integration_action_outbox')->where('id', $id)->where('claim_token', $claim)->update(['status' => $status, 'error_code' => $code, 'provider_message_id' => $messageId, 'available_at' => $available, 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
                if ($status === 'sent' || in_array($status, ['failed', 'uncertain'], true)) {
                    $this->events->record($instance, null, $status === 'sent' ? WorkflowEventType::TelegramSent : WorkflowEventType::TelegramDeliveryFailed, $row->node_id, ['delivery_uuid' => $id, 'status' => $status, 'error_code' => $code]);
                }
                $this->audit->audit($row->organization_id, $row->connection_id, null, 'telegram.delivery.'.$status);
            });
        } catch (\Throwable) {
            DB::transaction(function () use ($id, $claim, $attempted) {
                $row = DB::table('integration_action_outbox')->where('id', $id)->where('status', 'processing')->where('claim_token', $claim)->lockForUpdate()->first();
                if (! $row) {
                    return;
                }
                $status = $attempted ? 'uncertain' : 'cancelled';
                DB::table('integration_action_outbox')->where('id', $id)->update(['status' => $status, 'error_code' => $attempted ? 'interrupted_delivery' : 'authorization_unavailable', 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
                $this->audit->audit($row->organization_id, $row->connection_id, null, 'telegram.delivery.'.$status);
            });
        }
    }

    public function recover(): void
    {
        DB::table('integration_action_outbox')->where('status', 'processing')->where('lease_expires_at', '<=', now())->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row) {
                    $changed = DB::table('integration_action_outbox')->where('id', $row->id)->where('status', 'processing')->where('claim_token', $row->claim_token)->where('lease_expires_at', '<=', now())->update(['status' => 'uncertain', 'error_code' => 'interrupted_delivery', 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
                    if ($changed) {
                        $this->audit->audit($row->organization_id, $row->connection_id, null, 'telegram.delivery.uncertain');
                    }
                });
            }
        }, 'id');
        foreach (DB::table('integration_action_outbox')->where('status', 'pending')->where('available_at', '<=', now())->orderBy('available_at')->limit(200)->pluck('id') as $id) {
            ProcessTelegramDelivery::dispatch($id);
        }
        foreach (DB::table('integration_trigger_activations')->where('status', 'pending')->where('available_at', '<=', now())->orderBy('available_at')->limit(200)->pluck('id') as $id) {
            ActivateTelegramEvent::dispatch($id);
        }
    }
}
