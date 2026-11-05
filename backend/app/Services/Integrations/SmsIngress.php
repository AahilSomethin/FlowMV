<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SmsIngress
{
    public function __construct(private SmsAuthorization $authorization, private SmsApi $api, private CredentialVault $vault, private SmsDelivery $delivery, private ConnectionManager $audit) {}

    public function parameters(#[\SensitiveParameter] string $raw): array
    {
        abort_unless(strlen($raw) <= 32768, 413);
        $result = [];
        foreach (explode('&', $raw) as $part) {
            $pair = explode('=', $part, 2);
            $key = urldecode($pair[0]);
            $value = urldecode($pair[1] ?? '');
            abort_unless(preg_match('/^[A-Za-z0-9_]{1,80}$/D', $key) && ! array_key_exists($key, $result) && strlen($value) <= 4096 && count($result) < 64, 403);
            $result[$key] = $value;
        }

        return $result;
    }

    public function verify(string $url, #[\SensitiveParameter] array $parameters, string $signature, #[\SensitiveParameter] string $token): bool
    {
        if (! preg_match('/^[A-Za-z0-9+\/]{27}=$/D', $signature)) {
            return false;
        }
        ksort($parameters, SORT_STRING);
        $data = $url;
        foreach ($parameters as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                return false;
            }
            $data .= $key.$value;
        }

        return hash_equals(base64_encode(hash_hmac('sha1', $data, $token, true)), $signature);
    }

    public function receive(string $connection, string $id, #[\SensitiveParameter] string $raw, string $signature): void
    {
        abort_unless(Str::isUuid($connection) && Str::isUuid($id), 403);
        $params = $this->parameters($raw);
        DB::transaction(function () use ($connection, $id, $params, $signature) {
            $c = DB::table('connections')->where('id', $connection)->where('provider_id', 'twilio_sms')->where('status', 'enabled')->lockForUpdate()->first();
            abort_unless($c, 403);
            $this->authorization->approved($c->organization_id);
            $credentials = $this->authorization->credentials($connection);
            abort_unless($this->verify($this->api->callbackUrl($connection, $id), $params, $signature, $credentials['auth_token']) && ($params['AccountSid'] ?? '') === $credentials['account_sid'] && preg_match('/^SM[0-9a-f]{32}$/Di', $params['MessageSid'] ?? ''), 403);
            $row = DB::table('sms_action_outbox')->where('id', $id)->where('connection_id', $connection)->where('organization_id', $c->organization_id)->lockForUpdate()->first();
            abort_unless($row, 403);
            $payload = $this->vault->decrypt($row, 'sms-action:'.$id);
            foreach (['From' => 'sender_phone', 'To' => 'recipient_phone'] as $field => $stored) {
                if (isset($params[$field])) {
                    abort_unless(hash_equals($payload[$stored], $params[$field]), 403);
                }
            }
            if ($row->provider_message_id === null) {
                abort_unless(in_array($row->status, ['processing', 'uncertain'], true) && $row->budget_reserved_at && isset($params['From'], $params['To']), 403);
                DB::table('sms_action_outbox')->where('id', $id)->update(['provider_message_id' => $params['MessageSid']]);
                $this->audit->audit($row->organization_id, $connection, null, 'sms.delivery.reconciled_callback');
            } else {
                abort_unless(hash_equals($row->provider_message_id, $params['MessageSid']), 403);
            }
            $this->delivery->status($row, $params['MessageStatus'] ?? '');
        }, 3);
    }
}
