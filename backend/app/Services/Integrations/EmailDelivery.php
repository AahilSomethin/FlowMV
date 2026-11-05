<?php

namespace App\Services\Integrations;

use App\Enums\WorkflowEventType;
use App\Exceptions\EmailProviderFailure;
use App\Models\WorkflowInstance;
use App\Models\WorkflowVersion;
use App\Services\Workflows\Execution\PublishedGraph;
use App\Services\Workflows\Execution\WorkflowEventWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

class EmailDelivery
{
    public function __construct(private EmailAuthorization $authorization, private EmailTokens $tokens, private EmailHttp $http, private CredentialVault $vault, private ConnectionManager $audit, private WorkflowEventWriter $events) {}

    public function process(string $id): void
    {
        $claim = DB::transaction(function () use ($id) {
            $row = DB::table('email_action_outbox')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending' || Carbon::parse($row->available_at)->isFuture()) {
                return null;
            }
            if ($row->attempt_count >= 5) {
                DB::table('email_action_outbox')->where('id', $id)->update(['status' => 'failed', 'error_code' => 'attempt_limit', 'updated_at' => now()]);

                return null;
            }
            $claim = (string) Str::uuid();
            DB::table('email_action_outbox')->where('id', $id)->update(['status' => 'processing', 'claim_token' => $claim, 'lease_expires_at' => now()->addSeconds(120), 'attempt_count' => $row->attempt_count + 1, 'updated_at' => now()]);

            return $claim;
        });
        if (! $claim) {
            return;
        }$attempted = false;
        try {
            DB::transaction(function () use ($id, $claim, &$attempted) {
                $row = DB::table('email_action_outbox')->where('id', $id)->where('status', 'processing')->where('claim_token', $claim)->lockForUpdate()->first();
                if (! $row) {
                    return;
                }
                $instance = WorkflowInstance::findOrFail($row->workflow_instance_id);
                $version = WorkflowVersion::findOrFail($row->workflow_version_id);
                $node = PublishedGraph::fromVersion($version)->node($row->node_id);
                abort_unless(in_array($version->node_registry_version, [3, 4], true) && $instance->workflow_version_id === $version->id && $instance->workflow_id === $row->workflow_id && $instance->organization_id === $row->organization_id && $instance->status->value !== 'failed' && $node && in_array($node['type'], ['email_send', 'email_reply'], true) && $node['data']['connection_id'] === $row->connection_id && $node['data']['destination'] === $row->destination && ($node['type'] === 'email_reply' ? 'email.reply' : 'email.send') === $row->operation, 403);
                $c = $this->authorization->workflow($row->organization_id, $row->workflow_id, $row->connection_id, $row->operation, $row->destination, $instance->submitted_by);
                $mailbox = DB::table('email_mailboxes')->where('connection_id', $c->id)->first();
                abort_unless((int) $mailbox->credential_generation === (int) $row->credential_generation, 403);
                $keys = ['email:mailbox:'.$c->id => 10, 'email:recipient:'.$c->id.':'.hash('sha256', $row->destination) => 6, 'email:org:'.$c->organization_id => 100];
                foreach ($keys as $key => $limit) {
                    if (RateLimiter::tooManyAttempts($key, $limit)) {
                        DB::table('email_action_outbox')->where('id', $id)->update(['status' => 'pending', 'attempt_count' => $row->attempt_count - 1, 'available_at' => now()->addMinute(), 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);

                        return;
                    }
                }
                foreach ($keys as $key => $limit) {
                    RateLimiter::hit($key, $limit === 100 ? 3600 : 60);
                }
                $body = $this->vault->decrypt($row, 'email-action:'.$id);
                $mail = (new Email)->from($mailbox->address)->to($row->destination)->subject($body['subject'])->text($body['text']);
                $mail->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
                $mail->getHeaders()->addTextHeader('X-FlowMV-Automation', 'v3');
                $mail->getHeaders()->addIdHeader('Message-ID', 'flowmv.'.$id.'@flowmv.invalid');
                if ($row->operation === 'email.reply') {
                    [,,$original,$payload] = app(EmailWorkflow::class)->context($instance);
                    abort_unless($payload['sender'] === $row->destination && $payload['reply_to'] === $row->destination && is_string($body['reply_message_id']) && strlen($body['reply_message_id']) < 500 && ! preg_match('/[\r\n]/', $body['reply_message_id']), 403);
                    $mail->getHeaders()->addIdHeader('In-Reply-To', trim($body['reply_message_id'], '<>'));
                    $mail->getHeaders()->addIdHeader('References', trim($body['reply_message_id'], '<>'));
                }
                try {
                    $token = $this->tokens->get($row->organization_id, $c->id);
                    $attempted = true;
                    $this->http->sendMime($c->provider_id, $token, $mail->toString(), $row->operation === 'email.reply' ? $body['thread_id'] : null);
                    $status = 'accepted';
                    $error = null;
                    $available = now();
                } catch (EmailProviderFailure $e) {
                    $uncertain = $attempted && $e->status === 0;
                    $retry = ! $uncertain && in_array($e->status, [0, 429, 500, 502, 503, 504], true) && $row->attempt_count < 5;
                    $status = $uncertain ? 'uncertain' : ($retry ? 'pending' : 'failed');
                    $error = $e->reason;
                    $available = now()->addSeconds(max($e->retryAfter, [10, 30, 60, 300, 600][min(4, $row->attempt_count - 1)]));
                }
                DB::table('email_action_outbox')->where('id', $id)->where('claim_token', $claim)->update(['status' => $status, 'error_code' => $error, 'available_at' => $available, 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
                $this->audit->audit($row->organization_id, $c->id, null, 'email.delivery.'.$status);
                if ($status === 'accepted' || in_array($status, ['failed', 'uncertain'], true)) {
                    $this->events->record($instance, null, $status === 'accepted' ? WorkflowEventType::EmailAccepted : WorkflowEventType::EmailDeliveryFailed, $row->node_id, ['delivery_uuid' => $id, 'status' => $status, 'error_code' => $error]);
                }
            });
        } catch (\Throwable) {
            DB::transaction(function () use ($id, $claim, $attempted) {
                $row = DB::table('email_action_outbox')->where('id', $id)->where('status', 'processing')->where('claim_token', $claim)->lockForUpdate()->first();
                if (! $row) {
                    return;
                }
                $status = $attempted ? 'uncertain' : 'cancelled';
                DB::table('email_action_outbox')->where('id', $id)->update(['status' => $status, 'error_code' => $attempted ? 'interrupted_delivery' : 'authorization_unavailable', 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
                $this->audit->audit($row->organization_id, $row->connection_id, null, 'email.delivery.'.$status);
            });
        }
    }

    public function recover(): void
    {
        DB::table('email_action_outbox')->where('status', 'processing')->where('lease_expires_at', '<=', now())->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row) {
                    $changed = DB::table('email_action_outbox')->where('id', $row->id)->where('status', 'processing')->where('claim_token', $row->claim_token)->where('lease_expires_at', '<=', now())->update(['status' => 'uncertain', 'error_code' => 'interrupted_delivery', 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
                    if ($changed) {
                        $this->audit->audit($row->organization_id, $row->connection_id, null, 'email.delivery.uncertain');
                    }
                });
            }
        }, 'id');
    }
}
