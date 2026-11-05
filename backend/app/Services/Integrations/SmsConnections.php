<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SmsConnections
{
    public function __construct(private SmsAuthorization $authorization, private SmsApi $api, private CredentialVault $vault, private ConnectionManager $audit) {}

    public function policy(int $user, int $org, array $data): void
    {
        DB::transaction(function () use ($user, $org, $data) {
            $this->authorization->admin($user, $org);
            $existing = DB::table('sms_policies')->where('organization_id', $org)->lockForUpdate()->first();
            DB::table('integration_approvals')->updateOrInsert(['organization_id' => $org, 'provider_id' => 'twilio_sms'], ['approved' => $data['approved']]);
            $secret = $existing ? [] : $this->vault->encrypt(['fingerprint_key' => base64_encode(random_bytes(32))], 'sms-policy:'.$org);
            DB::table('sms_policies')->updateOrInsert(['organization_id' => $org], ['enabled' => $data['enabled'], 'allowed_countries' => json_encode(array_values(array_unique($data['allowed_countries']))), 'per_minute' => $data['per_minute'], 'daily_limit' => $data['daily_limit'], 'monthly_limit' => $data['monthly_limit'], 'recipient_daily_limit' => $data['recipient_daily_limit'], 'approved_by' => $user, ...$secret]);
            $this->audit->audit($org, null, $user, 'sms.policy.updated');
        }, 3);
    }

    public function create(int $user, int $org, string $name, #[\SensitiveParameter] array $credentials): string
    {
        $this->authorization->admin($user, $org);
        $this->authorization->policy($org);
        $clean = $this->api->credentials($credentials);
        $this->api->test($clean);

        return DB::transaction(function () use ($user, $org, $name, $clean) {
            $this->authorization->admin($user, $org);
            $this->authorization->policy($org);
            abort_if(DB::table('sms_accounts')->where('active_account_sid', $clean['account_sid'])->exists(), 409);
            $id = (string) Str::uuid();
            DB::table('connections')->insert(['id' => $id, 'organization_id' => $org, 'provider_id' => 'twilio_sms', 'owner_user_id' => null, 'name' => $name, 'credential_type' => 'api_key', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('credential_secrets')->insert(['connection_id' => $id, ...$this->vault->encrypt($clean, $id), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('sms_accounts')->insert(['connection_id' => $id, 'account_sid' => $clean['account_sid'], 'active_account_sid' => $clean['account_sid']]);
            $this->audit->audit($org, $id, $user, 'sms.connection.created');

            return $id;
        }, 3);
    }

    public function template(int $user, int $org, string $name, #[\SensitiveParameter] string $text): string
    {
        return DB::transaction(function () use ($user, $org, $name, $text) {
            $this->authorization->admin($user, $org);
            $this->authorization->policy($org);
            app(SmsWorkflow::class)->render($text, '00000000-0000-4000-8000-000000000000', 'awaiting_assignment', 'approved');
            abort_unless(DB::table('sms_templates')->where('organization_id', $org)->count() < 100, 429);
            $id = (string) Str::uuid();
            DB::table('sms_templates')->insert(['id' => $id, 'organization_id' => $org, 'name' => $name, 'body' => $text, 'approved_by' => $user]);
            $this->audit->audit($org, null, $user, 'sms.template.approved');

            return $id;
        });
    }

    public function action(int $user, int $org, string $id, string $action, array $data, #[\SensitiveParameter] array $credentials = [], #[\SensitiveParameter] string $phone = ''): ?string
    {
        return DB::transaction(function () use ($user, $org, $id, $action, $data, $credentials, $phone) {
            $c = $this->authorization->manage($user, $org, $id);
            abort_unless($c->status !== 'revoked', 409);
            $account = DB::table('sms_accounts')->where('connection_id', $id)->first();
            $created = null;
            if (in_array($action, ['test', 'approve'], true)) {
                $this->authorization->connection($org, $id, false);
                $this->api->test($this->authorization->credentials($id));
                if ($action === 'approve') {
                    DB::table('sms_accounts')->where('connection_id', $id)->update(['approved_by' => $user, 'health' => 'healthy']);
                }
            } elseif (in_array($action, ['disable', 'enable', 'revoke'], true)) {
                if ($action === 'enable') {
                    $this->authorization->policy($org);
                }
                DB::table('connections')->where('id', $id)->update(['status' => ['disable' => 'disabled', 'enable' => 'enabled', 'revoke' => 'revoked'][$action], 'updated_at' => now()]);
                if ($action !== 'enable') {
                    DB::table('sms_action_outbox')->where('connection_id', $id)->where('status', 'pending')->update(['status' => 'cancelled', 'error_code' => 'connection_disabled', 'updated_at' => now()]);
                }
                if ($action === 'revoke') {
                    DB::table('credential_secrets')->where('connection_id', $id)->delete();
                    DB::table('sms_workflow_grants')->where('connection_id', $id)->delete();
                    DB::table('sms_accounts')->where('connection_id', $id)->update(['active_account_sid' => null, 'approved_by' => null, 'health' => 'revoked']);
                }
            } elseif ($action === 'rotate') {
                $clean = $this->api->credentials($credentials);
                abort_unless($clean['account_sid'] === $account->account_sid, 422);
                $this->api->test($clean);
                DB::table('credential_secrets')->where('connection_id', $id)->update([...$this->vault->encrypt($clean, $id), 'updated_at' => now()]);
                DB::table('sms_accounts')->where('connection_id', $id)->update(['credential_generation' => $account->credential_generation + 1, 'approved_by' => null, 'health' => 'needs_approval']);
                DB::table('sms_workflow_grants')->where('connection_id', $id)->delete();
                DB::table('sms_action_outbox')->where('connection_id', $id)->where('status', 'pending')->update(['status' => 'cancelled', 'error_code' => 'credential_rotated', 'updated_at' => now()]);
            } elseif (in_array($action, ['approve-sender', 'approve-recipient'], true)) {
                $this->authorization->connection($org, $id);
                $phone = $this->api->phone($phone);
                $sender = $action === 'approve-sender';
                $country = $sender ? null : $this->api->country($this->authorization->credentials($id), $phone);
                $policy = $this->authorization->policy($org);
                abort_unless($sender || (($data['consent'] ?? false) && in_array($country, json_decode($policy->allowed_countries, true), true)), 403);
                $provider = $sender ? $this->api->sender($this->authorization->credentials($id), $phone) : null;
                $fingerprint = $this->authorization->fingerprint($org, $phone);
                $table = $sender ? 'sms_senders' : 'sms_recipients';
                $existing = DB::table($table)->where('connection_id', $id)->where('fingerprint', $fingerprint)->first();
                abort_unless($existing || DB::table($table)->where('connection_id', $id)->count() < 50, 429);
                $created = $existing->id ?? (string) Str::uuid();
                DB::table($table)->updateOrInsert(['id' => $created], ['organization_id' => $org, 'connection_id' => $id, 'label' => $data['label'], 'country' => $country, 'fingerprint' => $fingerprint, 'masked_phone' => '...'.substr($phone, -4), 'provider_sid' => $provider, 'approved_by' => $user, 'enabled' => true, 'consent_at' => $sender ? null : now(), 'expires_at' => $sender ? null : now()->addDays(30), ...$this->vault->encrypt(['phone' => $phone], ($sender ? 'sms-sender:' : 'sms-recipient:').$created)]);
            } elseif (in_array($action, ['disable-sender', 'disable-recipient'], true)) {
                $table = $action === 'disable-sender' ? 'sms_senders' : 'sms_recipients';
                abort_unless(DB::table($table)->where('id', $data['resource_id'])->where('connection_id', $id)->where('organization_id', $org)->update(['enabled' => false]), 404);
            } elseif (in_array($action, ['disable-template', 'enable-template'], true)) {
                abort_unless(DB::table('sms_templates')->where('id', $data['resource_id'])->where('organization_id', $org)->update(['enabled' => $action === 'enable-template', 'approved_by' => $user]), 404);
            } elseif (in_array($action, ['grant', 'ungrant'], true)) {
                $w = DB::table('workflows')->where('uuid', $data['workflow_id'])->where('organization_id', $org)->where('status', '!=', 'archived')->first();
                abort_unless($w, 404);
                $key = ['organization_id' => $org, 'connection_id' => $id, 'workflow_id' => $w->id, 'operation' => $data['operation'], 'sender_id' => $data['sender_id'], 'recipient_id' => $data['recipient_id'], 'template_id' => $data['template_id']];
                if ($action === 'grant') {
                    $this->authorization->resources($org, $id, $data);
                    DB::table('sms_workflow_grants')->updateOrInsert($key, ['authorized_by' => $user]);
                } else {
                    DB::table('sms_workflow_grants')->where($key)->delete();
                }
            } elseif ($action === 'rename') {
                DB::table('connections')->where('id', $id)->update(['name' => $data['name'], 'updated_at' => now()]);
            } else {
                abort(422);
            }
            $this->audit->audit($org, $id, $user, 'sms.connection.'.$action);

            return $created;
        }, 3);
    }
}
