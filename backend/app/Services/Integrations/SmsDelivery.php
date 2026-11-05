<?php

namespace App\Services\Integrations;

use App\Enums\WorkflowEventType;
use App\Exceptions\SmsApiFailure;
use App\Jobs\ProcessSmsDelivery;
use App\Models\WorkflowInstance;
use App\Models\WorkflowVersion;
use App\Services\Workflows\Execution\PublishedGraph;
use App\Services\Workflows\Execution\WorkflowEventWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SmsDelivery
{
    public function __construct(private SmsAuthorization $authorization, private SmsApi $api, private CredentialVault $vault, private SmsWorkflow $workflow, private ConnectionManager $audit, private WorkflowEventWriter $events) {}

    private function guard(object $row): array
    {
        $instance = WorkflowInstance::findOrFail($row->workflow_instance_id);
        $version = WorkflowVersion::findOrFail($row->workflow_version_id);
        abort_unless($instance->organization_id === $row->organization_id && $instance->workflow_version_id === $version->id && $instance->workflow_id === $version->workflow_id && in_array($version->node_registry_version, [3, 4], true) && $instance->status->value !== 'failed', 403);
        $node = PublishedGraph::fromVersion($version)->node($row->node_id);
        abort_unless($node && in_array($node['type'], SmsNodes::TYPES, true) && $this->workflow->operation($node['type']) === $row->operation, 403);
        foreach (['connection_id', 'sender_id', 'recipient_id', 'template_id'] as $field) {
            abort_unless(($node['data'][$field] ?? '') === $row->$field, 403);
        }
        $resources = $this->authorization->workflow($row->organization_id, $instance->workflow_id, $row->connection_id, $row->operation, (array) $row, $instance->submitted_by);
        $account = DB::table('sms_accounts')->where('connection_id', $row->connection_id)->first();
        abort_unless((int) $account->credential_generation === (int) $row->credential_generation && hash_equals($resources['recipient']->fingerprint, $row->recipient_fingerprint), 403);

        return [$instance, $resources];
    }

    private function record(object $row, string $status, ?string $code = null): void
    {
        $this->audit->audit($row->organization_id, $row->connection_id, null, 'sms.delivery.'.$status);
        if (in_array($status, ['accepted', 'failed', 'uncertain', 'cancelled', 'blocked'], true)) {
            $instance = WorkflowInstance::findOrFail($row->workflow_instance_id);
            $this->events->record($instance, null, $status === 'accepted' ? WorkflowEventType::SmsAccepted : WorkflowEventType::SmsDeliveryFailed, $row->node_id, ['delivery_uuid' => $row->id, 'status' => $status, 'error_code' => $code]);
        }
    }

    public function process(string $id): void
    {
        try {
            $claim = DB::transaction(function () use ($id) {
                $row = DB::table('sms_action_outbox')->where('id', $id)->lockForUpdate()->first();
                if (! $row || $row->status !== 'pending' || Carbon::parse($row->available_at)->isFuture()) {
                    return null;
                }
                [, $resources] = $this->guard($row);
                $policy = $resources['policy'];
                $code = null;
                if (Carbon::parse($row->expires_at)->isPast() || $row->attempt_count >= 5) {
                    $code = 'expiry_or_attempt_limit';
                } elseif ($row->budget_reserved_at && ! Carbon::parse($row->budget_reserved_at)->isSameDay(now())) {
                    $code = 'quota_window_changed';
                }
                if ($code) {
                    DB::table('sms_action_outbox')->where('id', $id)->update(['status' => 'failed', 'error_code' => $code, 'updated_at' => now()]);
                    $this->record($row, 'failed', $code);

                    return null;
                }
                if ($row->budget_reserved_at === null) {
                    $budget = DB::table('sms_action_outbox')->where('organization_id', $row->organization_id)->whereNotNull('budget_reserved_at');
                    if ((clone $budget)->where('budget_reserved_at', '>=', now()->startOfMonth())->count() >= $policy->monthly_limit) {
                        $code = 'monthly_limit';
                    } elseif ((clone $budget)->where('budget_reserved_at', '>=', now()->startOfDay())->count() >= $policy->daily_limit) {
                        $code = 'daily_limit';
                    } elseif ((clone $budget)->where('recipient_fingerprint', $row->recipient_fingerprint)->where('budget_reserved_at', '>=', now()->startOfDay())->count() >= $policy->recipient_daily_limit) {
                        $code = 'recipient_daily_limit';
                    }
                    if ($code) {
                        DB::table('sms_action_outbox')->where('id', $id)->update(['status' => 'blocked', 'error_code' => $code, 'updated_at' => now()]);
                        $this->record($row, 'blocked', $code);

                        return null;
                    }
                    if ((clone $budget)->where('budget_reserved_at', '>', now()->subMinute())->count() >= $policy->per_minute) {
                        DB::table('sms_action_outbox')->where('id', $id)->update(['available_at' => now()->addMinute(), 'error_code' => 'organization_rate_limit', 'updated_at' => now()]);

                        return null;
                    }
                }
                $claim = (string) Str::uuid();
                DB::table('sms_action_outbox')->where('id', $id)->update(['status' => 'processing', 'claim_token' => $claim, 'lease_expires_at' => now()->addSeconds(120), 'attempt_count' => $row->attempt_count + 1, 'budget_reserved_at' => $row->budget_reserved_at ?? now(), 'updated_at' => now()]);

                return $claim;
            }, 3);
        } catch (\Throwable) {
            DB::transaction(function () use ($id) {
                $row = DB::table('sms_action_outbox')->where('id', $id)->where('status', 'pending')->lockForUpdate()->first();
                if ($row) {
                    DB::table('sms_action_outbox')->where('id', $id)->update(['status' => 'cancelled', 'error_code' => 'authorization_unavailable', 'updated_at' => now()]);
                    $this->record($row, 'cancelled', 'authorization_unavailable');
                }
            });

            return;
        }
        if (! $claim) {
            return;
        }
        $attempted = false;
        try {
            DB::transaction(function () use ($id, $claim, &$attempted) {
                $row = DB::table('sms_action_outbox')->where('id', $id)->where('status', 'processing')->where('claim_token', $claim)->lockForUpdate()->first();
                if (! $row) {
                    return;
                }
                $this->guard($row);
                abort_unless(Carbon::parse($row->expires_at)->isFuture(), 403);
                $payload = $this->vault->decrypt($row, 'sms-action:'.$id);
                try {
                    $attempted = true;
                    $sid = $this->api->send($this->authorization->credentials($row->connection_id), $payload, $row->connection_id, $id);
                    $status = 'accepted';
                    $code = null;
                    $available = now();
                } catch (SmsApiFailure $failure) {
                    $status = $failure->ambiguous ? 'uncertain' : ($failure->retryable && $row->attempt_count < 5 ? 'pending' : 'failed');
                    $code = $failure->reason;
                    $sid = null;
                    $available = now()->addSeconds(max(30, $failure->retryAfter, $row->attempt_count * 30));
                    if ($code === 'credentials_rejected') {
                        DB::table('sms_accounts')->where('connection_id', $row->connection_id)->update(['approved_by' => null, 'health' => 'reauthorization_required']);
                    }
                }
                DB::table('sms_action_outbox')->where('id', $id)->where('claim_token', $claim)->update(['status' => $status, 'error_code' => $code, 'provider_message_id' => $sid, 'available_at' => $available, 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
                $this->record($row, $status, $code);
            });
        } catch (\Throwable) {
            DB::transaction(function () use ($id, $claim, $attempted) {
                $row = DB::table('sms_action_outbox')->where('id', $id)->where('status', 'processing')->where('claim_token', $claim)->lockForUpdate()->first();
                if ($row) {
                    $status = $attempted ? 'uncertain' : 'cancelled';
                    $code = $attempted ? 'interrupted_delivery' : 'authorization_unavailable';
                    DB::table('sms_action_outbox')->where('id', $id)->update(['status' => $status, 'error_code' => $code, 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
                    $this->record($row, $status, $code);
                }
            });
        }
    }

    public function status(object $row, string $providerStatus): void
    {
        $map = ['accepted' => 'accepted', 'queued' => 'accepted', 'sending' => 'accepted', 'sent' => 'sent', 'delivered' => 'delivered', 'undelivered' => 'undelivered', 'failed' => 'failed', 'canceled' => 'cancelled'];
        abort_unless(isset($map[$providerStatus]), 422);
        $inserted = DB::table('sms_status_receipts')->insertOrIgnore(['delivery_id' => $row->id, 'provider_status' => $providerStatus, 'received_at' => now()]);
        if (! $inserted) {
            return;
        }
        $status = $map[$providerStatus];
        if (in_array($row->status, ['delivered', 'undelivered', 'failed', 'cancelled', 'blocked'], true) || ($row->status === 'sent' && $status === 'accepted')) {
            $this->audit->audit($row->organization_id, $row->connection_id, null, 'sms.status.ignored');

            return;
        }
        DB::table('sms_action_outbox')->where('id', $row->id)->update(['status' => $status, 'error_code' => in_array($status, ['failed', 'undelivered'], true) ? 'provider_reported_failure' : null, 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
        $this->audit->audit($row->organization_id, $row->connection_id, null, 'sms.status.'.$providerStatus);
        if ($status !== $row->status) {
            $this->events->record(WorkflowInstance::findOrFail($row->workflow_instance_id), null, WorkflowEventType::SmsStatusUpdated, $row->node_id, ['delivery_uuid' => $row->id, 'status' => $status]);
        }
    }

    public function reconcile(string $id): void
    {
        DB::transaction(function () use ($id) {
            $row = DB::table('sms_action_outbox')->where('id', $id)->lockForUpdate()->first();
            if (! $row || ! in_array($row->status, ['accepted', 'sent'], true) || ! $row->provider_message_id || $row->reconcile_attempts >= 20 || ($row->reconciled_at && Carbon::parse($row->reconciled_at)->isAfter(now()->subMinutes(5)))) {
                return;
            }
            DB::table('sms_action_outbox')->where('id', $id)->update(['reconciled_at' => now(), 'reconcile_attempts' => $row->reconcile_attempts + 1]);
            try {
                $this->authorization->connection($row->organization_id, $row->connection_id);
                $status = $this->api->messageStatus($this->authorization->credentials($row->connection_id), $row->provider_message_id);
                $this->status($row, $status);
            } catch (\Throwable) {
                DB::table('sms_action_outbox')->where('id', $id)->update(['error_code' => 'status_unavailable']);
            }
        });
    }

    public function recover(): void
    {
        foreach (DB::table('sms_action_outbox')->where('status', 'processing')->where('lease_expires_at', '<=', now())->limit(100)->get() as $row) {
            DB::transaction(function () use ($row) {
                $changed = DB::table('sms_action_outbox')->where('id', $row->id)->where('status', 'processing')->where('claim_token', $row->claim_token)->where('lease_expires_at', '<=', now())->update(['status' => 'uncertain', 'error_code' => 'interrupted_delivery', 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
                if ($changed) {
                    $this->record($row, 'uncertain', 'interrupted_delivery');
                }
            });
        }
        foreach (DB::table('sms_action_outbox')->where('status', 'pending')->where('available_at', '<=', now())->limit(100)->pluck('id') as $id) {
            ProcessSmsDelivery::dispatch($id);
        }
        foreach (DB::table('sms_action_outbox')->whereIn('status', ['accepted', 'sent'])->where('created_at', '<=', now()->subMinutes(5))->where('reconcile_attempts', '<', 20)->where(fn ($q) => $q->whereNull('reconciled_at')->orWhere('reconciled_at', '<=', now()->subMinutes(5)))->limit(100)->pluck('id') as $id) {
            ProcessSmsDelivery::dispatch($id, true);
        }
    }
}
