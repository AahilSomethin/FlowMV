<?php

namespace App\Services\Integrations;

use App\Jobs\SyncEmailMailbox;
use Illuminate\Support\Facades\DB;

class EmailIngress
{
    public function __construct(private GooglePushVerifier $google, private EmailAuthorization $authorization, private ConnectionManager $audit) {}

    public function gmail(#[\SensitiveParameter] string $bearer, #[\SensitiveParameter] array $notification): void
    {
        $this->google->verify($bearer);
        abort_unless(($notification['subscription'] ?? null) === config('email_integrations.gmail.subscription') && is_string($notification['message']['messageId'] ?? null) && strlen($notification['message']['messageId']) <= 255 && is_string($notification['message']['data'] ?? null) && strlen($notification['message']['data']) < 10000, 403);
        $data = json_decode(base64_decode($notification['message']['data'], true) ?: '', true);
        abort_unless(is_array($data) && is_string($data['emailAddress'] ?? null) && preg_match('/^[0-9]{1,30}$/D', (string) ($data['historyId'] ?? '')), 403);
        $mailbox = DB::table('email_mailboxes as m')->join('connections as c', 'c.id', '=', 'm.connection_id')->where('m.address', strtolower($data['emailAddress']))->where('c.provider_id', 'gmail')->whereNotNull('m.active_identity')->select('m.connection_id', 'c.organization_id')->first();
        abort_unless($mailbox, 403);
        $this->signal($mailbox->organization_id, $mailbox->connection_id, 'gmail:'.$notification['message']['messageId']);
    }

    public function outlook(#[\SensitiveParameter] array $notification): void
    {
        abort_unless(is_array($notification['value'] ?? null) && count($notification['value']) <= 100, 422);
        foreach ($notification['value'] as $value) {
            if (! is_array($value) || ! is_string($value['subscriptionId'] ?? null) || ! is_string($value['clientState'] ?? null) || strlen($value['clientState']) > 255) {
                abort(403);
            }
            $row = DB::table('email_sync_state as s')->join('connections as c', 'c.id', '=', 's.connection_id')->where('s.subscription_id', $value['subscriptionId'])->where('c.provider_id', 'outlook')->select('s.connection_id', 's.subscription_secret_hash', 'c.organization_id')->first();
            abort_unless($row && is_string($row->subscription_secret_hash) && hash_equals($row->subscription_secret_hash, hash('sha256', $value['clientState'])), 403);
            $life = $value['lifecycleEvent'] ?? null;
            abort_unless($life === null || in_array($life, ['missed', 'subscriptionRemoved', 'reauthorizationRequired'], true), 422);
            $this->signal($row->organization_id, $row->connection_id, 'outlook:'.hash('sha256', json_encode([$value['subscriptionId'], $life, $value['changeType'] ?? null, $value['resourceData']['id'] ?? null, $value['subscriptionExpirationDateTime'] ?? null])), $life === 'subscriptionRemoved');
        }
    }

    private function signal(int $org, string $id, string $dedupe, bool $renew = false): void
    {
        DB::transaction(function () use ($org, $id, $dedupe, $renew) {
            $this->authorization->connection($org, $id, 'read');
            $hash = hash('sha256', $dedupe);
            if (DB::table('email_notification_receipts')->where('connection_id', $id)->where('dedupe_hash', $hash)->exists()) {
                return;
            }
            DB::table('email_notification_receipts')->insert(['connection_id' => $id, 'dedupe_hash' => $hash, 'received_at' => now()]);
            $patch = ['requested' => true, 'available_at' => now(), 'updated_at' => now()];
            if ($renew) {
                $patch['watch_expires_at'] = now()->subMinute();
                $patch['subscription_id'] = null;
            }
            DB::table('email_sync_state')->where('connection_id', $id)->update($patch);
            $this->audit->audit($org, $id, null, 'email.sync.requested');
            SyncEmailMailbox::dispatch($id)->afterCommit();
        });
    }
}
