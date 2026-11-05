<?php

namespace App\Services\Integrations;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SmsAuthorization
{
    public function __construct(private ConnectionAccess $access, private CredentialVault $vault) {}

    public function admin(int $user, int $org): void
    {
        abort_unless($this->access->role($user, $org) === 'admin', 403);
    }

    public function approved(int $org): void
    {
        abort_unless(config('sms.enabled') && DB::table('integration_providers')->where('id', 'twilio_sms')->value('enabled') && DB::table('integration_approvals')->where('organization_id', $org)->where('provider_id', 'twilio_sms')->value('approved'), 403);
    }

    public function policy(int $org): object
    {
        $this->approved($org);
        $p = DB::table('sms_policies')->where('organization_id', $org)->lockForUpdate()->first();
        abort_unless($p && $p->enabled && $this->access->role($p->approved_by, $org) === 'admin', 403);

        return $p;
    }

    public function manage(int $user, int $org, string $id): object
    {
        $this->admin($user, $org);
        $c = DB::table('connections')->where('id', $id)->where('organization_id', $org)->where('provider_id', 'twilio_sms')->whereNull('owner_user_id')->lockForUpdate()->first();
        abort_unless($c, 404);

        return $c;
    }

    public function connection(int $org, string $id, bool $approval = true): object
    {
        $c = DB::table('connections')->where('id', $id)->where('organization_id', $org)->where('provider_id', 'twilio_sms')->whereNull('owner_user_id')->lockForUpdate()->first();
        abort_unless($c && $c->status === 'enabled', 403);
        $this->policy($org);
        $a = DB::table('sms_accounts')->where('connection_id', $id)->first();
        abort_unless($a && $a->active_account_sid && $a->health !== 'reauthorization_required', 403);
        if ($approval) {
            abort_unless($a->approved_by && $this->access->role($a->approved_by, $org) === 'admin', 403);
        }

        return $c;
    }

    public function credentials(string $id): array
    {
        $row = DB::table('credential_secrets')->where('connection_id', $id)->first();
        abort_unless($row, 403);

        return $this->vault->decrypt($row, $id);
    }

    public function fingerprint(int $org, #[\SensitiveParameter] string $phone): string
    {
        $policy = $this->policy($org);
        $secret = $this->vault->decrypt($policy, 'sms-policy:'.$org);
        $key = base64_decode($secret['fingerprint_key'] ?? '', true);
        abort_unless(is_string($key) && strlen($key) === 32, 503);

        return hash_hmac('sha256', $phone, $key);
    }

    public function resources(int $org, string $connection, array $data): array
    {
        $c = $this->connection($org, $connection);
        $policy = $this->policy($org);
        $s = DB::table('sms_senders')->where('id', $data['sender_id'])->where('connection_id', $connection)->where('organization_id', $org)->where('enabled', true)->first();
        $r = DB::table('sms_recipients')->where('id', $data['recipient_id'])->where('connection_id', $connection)->where('organization_id', $org)->where('enabled', true)->first();
        $t = DB::table('sms_templates')->where('id', $data['template_id'])->where('organization_id', $org)->where('enabled', true)->first();
        abort_unless($s && $r && $t && $r->consent_at && $r->expires_at && Carbon::parse($r->expires_at)->isFuture() && in_array($r->country, json_decode($policy->allowed_countries, true), true), 403);
        foreach ([$s, $r, $t] as $approved) {
            abort_unless($this->access->role($approved->approved_by, $org) === 'admin', 403);
        }
        app(SmsNodes::class)->template($t->body);

        return ['connection' => $c, 'policy' => $policy, 'sender' => $s, 'recipient' => $r, 'template' => $t];
    }

    public function workflow(int $org, int $workflow, string $connection, string $operation, array $data, ?int $actor = null): array
    {
        abort_unless(in_array($operation, ['sms.notification', 'sms.send_message'], true), 403);
        $resources = $this->resources($org, $connection, $data);
        abort_unless(DB::table('workflows')->where('id', $workflow)->where('organization_id', $org)->where('status', '!=', 'archived')->exists(), 403);
        $g = DB::table('sms_workflow_grants')->where('organization_id', $org)->where('workflow_id', $workflow)->where('connection_id', $connection)->where('operation', $operation)->where('sender_id', $data['sender_id'])->where('recipient_id', $data['recipient_id'])->where('template_id', $data['template_id'])->first();
        abort_unless($g && $this->access->role($g->authorized_by, $org) === 'admin', 403);
        if ($actor !== null) {
            $this->access->role($actor, $org);
        }

        return $resources;
    }
}
