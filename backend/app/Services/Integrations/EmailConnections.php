<?php

namespace App\Services\Integrations;

use App\Exceptions\EmailProviderFailure;
use App\Jobs\ProcessEmailDelivery;
use App\Jobs\SyncEmailMailbox;
use App\Models\WorkflowInstance;
use Illuminate\Support\Facades\DB;

class EmailConnections
{
    public function __construct(private EmailAuthorization $authorization, private ConnectionAccess $access, private EmailTokens $tokens, private EmailProviders $providers, private EmailHttp $http, private ConnectionManager $audit) {}

    public function action(int $user, int $org, string $id, string $action, array $data): void
    {
        $result = DB::transaction(function () use ($user, $org, $id, $action, $data) {
            $c = DB::table('connections')->where('id', $id)->where('organization_id', $org)->whereIn('provider_id', EmailProviders::IDS)->lockForUpdate()->first();
            abort_unless($c, 404);
            $admin = $this->access->role($user, $org) === 'admin';
            if (in_array($action, ['approve', 'approve-destination', 'remove-destination', 'pause', 'resume', 'retry-delivery'], true)) {
                abort_unless($admin, 403);
            } else {
                $this->authorization->manage($user, $org, $id);
            }
            abort_if($c->status === 'revoked', 409);
            $m = DB::table('email_mailboxes')->where('connection_id', $id)->first();
            if ($action === 'approve') {
                $this->authorization->connection($org, $id, null, false);
                DB::table('email_mailboxes')->where('connection_id', $id)->update(['approved_by' => $user, 'health' => 'ready']);
                if (in_array('read', json_decode($m->capabilities, true), true)) {
                    SyncEmailMailbox::dispatch($id)->afterCommit();
                }
            } elseif (in_array($action, ['disable', 'enable', 'revoke'], true)) {
                $status = ['disable' => 'disabled', 'enable' => 'enabled', 'revoke' => 'revoked'][$action];
                DB::table('connections')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
                if ($action !== 'enable') {
                    $this->tokens->pause($id);
                }
                if ($action === 'revoke') {
                    DB::table('credential_secrets')->where('connection_id', $id)->delete();
                    DB::table('email_workflow_grants')->where('connection_id', $id)->delete();
                    DB::table('email_mailboxes')->where('connection_id', $id)->update(['active_identity' => null, 'approved_by' => null, 'health' => 'revoked', 'credential_generation' => $m->credential_generation + 1]);
                    DB::table('email_sync_state')->where('connection_id', $id)->update(['requested' => false, 'subscription_secret_hash' => null, 'updated_at' => now()]);
                }
            } elseif ($action === 'downgrade') {
                $caps = $this->providers->capabilities($data['capabilities']);
                abort_unless(array_diff($caps, json_decode($m->capabilities, true)) === [], 422);
                $this->tokens->pause($id);
                DB::table('email_workflow_grants')->where('connection_id', $id)->delete();
                DB::table('email_mailboxes')->where('connection_id', $id)->update(['capabilities' => json_encode($caps), 'credential_generation' => $m->credential_generation + 1]);
            } elseif ($action === 'test') {
                $this->authorization->connection($org, $id);
                try {
                    $identity = $this->http->identity($c->provider_id, $this->tokens->get($org, $id));
                } catch (EmailProviderFailure $failure) {
                    $this->audit->audit($org, $id, $user, 'email.test.failed');

                    return $failure;
                }
                abort_unless($identity['id'] === $m->account_id, 403);
            } elseif (in_array($action, ['approve-destination', 'remove-destination'], true)) {
                $this->authorization->connection($org, $id, 'send');
                $address = $this->providers->address($data['address']);
                if ($action === 'approve-destination') {
                    DB::table('email_destinations')->updateOrInsert(['connection_id' => $id, 'address' => $address], ['approved_by' => $user]);
                } else {
                    DB::table('email_destinations')->where('connection_id', $id)->where('address', $address)->delete();
                    DB::table('email_workflow_grants')->where('connection_id', $id)->where('destination', $address)->delete();
                }
            } elseif (in_array($action, ['grant', 'ungrant'], true)) {
                $operation = $data['operation'];
                abort_unless(in_array($operation, ['email.receive', 'email.send', 'email.reply'], true), 422);
                $this->authorization->connection($org, $id, $operation === 'email.receive' ? 'read' : 'send');
                $workflow = DB::table('workflows')->where('organization_id', $org)->where('uuid', $data['workflow_id'])->first();
                abort_unless($workflow, 404);
                $destination = $operation === 'email.receive' ? '' : $this->providers->address($data['address']);
                if ($destination !== '') {
                    $d = DB::table('email_destinations')->where('connection_id', $id)->where('address', $destination)->first();
                    abort_unless($d && $this->access->role($d->approved_by, $org) === 'admin', 403);
                }
                $key = ['organization_id' => $org, 'connection_id' => $id, 'workflow_id' => $workflow->id, 'operation' => $operation, 'destination' => $destination];
                if ($action === 'grant') {
                    DB::table('email_workflow_grants')->updateOrInsert($key, ['authorized_by' => $user]);
                } else {
                    DB::table('email_workflow_grants')->where($key)->delete();
                    $this->tokens->pause($id);
                }
            } elseif (in_array($action, ['pause', 'resume'], true)) {
                $binding = DB::table('email_trigger_bindings')->where('connection_id', $id)->where('organization_id', $org)->where('id', $data['binding_id'])->first();
                abort_unless($binding, 404);
                if ($action === 'resume') {
                    $this->authorization->workflow($org, $binding->workflow_id, $id, 'email.receive');
                }
                DB::table('email_trigger_bindings')->where('id', $binding->id)->update(['status' => $action === 'pause' ? 'paused' : 'active', 'updated_at' => now()]);
            } elseif ($action === 'retry-delivery') {
                $row = DB::table('email_action_outbox')->where('id', $data['delivery_id'])->where('organization_id', $org)->where('connection_id', $id)->lockForUpdate()->first();
                abort_unless($row && in_array($row->status, ['failed', 'uncertain'], true) && $row->attempt_count < 5, 409);
                abort_unless($row->status !== 'uncertain' || ($data['acknowledge_duplicate_risk'] ?? false) === true, 422);
                $instance = WorkflowInstance::findOrFail($row->workflow_instance_id);
                $this->authorization->workflow($org, $row->workflow_id, $id, $row->operation, $row->destination, $instance->submitted_by);
                abort_unless((int) $m->credential_generation === (int) $row->credential_generation, 403);
                DB::table('email_action_outbox')->where('id', $row->id)->update(['status' => 'pending', 'available_at' => now(), 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
                ProcessEmailDelivery::dispatch($row->id)->afterCommit();
            } else {
                abort(422);
            }
            $this->audit->audit($org, $id, $user, 'email.'.$action);
        });
        if ($result instanceof EmailProviderFailure) {
            throw $result;
        }
    }
}
