<?php

namespace App\Services\Integrations;

use App\Exceptions\SmsApiFailure;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Str;

class SmsApi
{
    public function __construct(public readonly Factory $http) {}

    public function credentials(#[\SensitiveParameter] array $data): array
    {
        abort_unless(count($data) === 2 && array_diff(array_keys($data), ['account_sid', 'auth_token']) === [] && is_string($data['account_sid'] ?? null) && preg_match('/^AC[0-9a-f]{32}$/Di', $data['account_sid']) && is_string($data['auth_token'] ?? null) && preg_match('/^[0-9a-f]{32}$/Di', $data['auth_token']), 422);

        return ['account_sid' => 'AC'.strtolower(substr($data['account_sid'], 2)), 'auth_token' => $data['auth_token']];
    }

    public function phone(#[\SensitiveParameter] string $phone): string
    {
        abort_unless(preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone), 422);

        return $phone;
    }

    public function test(#[\SensitiveParameter] array $credentials): void
    {
        $c = $this->credentials($credentials);
        $account = $this->request('GET', 'https://api.twilio.com/2010-04-01/Accounts/'.$c['account_sid'].'.json', $c);
        abort_unless(($account['sid'] ?? '') === $c['account_sid'] && ($account['status'] ?? '') === 'active', 422);
    }

    public function sender(#[\SensitiveParameter] array $credentials, #[\SensitiveParameter] string $phone): string
    {
        $c = $this->credentials($credentials);
        $data = $this->request('GET', 'https://api.twilio.com/2010-04-01/Accounts/'.$c['account_sid'].'/IncomingPhoneNumbers.json', $c, ['PhoneNumber' => $this->phone($phone), 'PageSize' => 1]);
        $number = $data['incoming_phone_numbers'][0] ?? [];
        abort_unless(($number['account_sid'] ?? '') === $c['account_sid'] && ($number['phone_number'] ?? '') === $phone && ($number['capabilities']['sms'] ?? false) === true && preg_match('/^PN[0-9a-f]{32}$/Di', $number['sid'] ?? ''), 422);

        return $number['sid'];
    }

    public function country(#[\SensitiveParameter] array $credentials, #[\SensitiveParameter] string $phone): string
    {
        $c = $this->credentials($credentials);
        $data = $this->request('GET', 'https://lookups.twilio.com/v2/PhoneNumbers/'.rawurlencode($this->phone($phone)), $c);
        abort_unless(($data['valid'] ?? false) === true && ($data['phone_number'] ?? '') === $phone && preg_match('/^[A-Z]{2}$/D', $data['country_code'] ?? ''), 422);

        return $data['country_code'];
    }

    public function callbackUrl(string $connection, string $delivery): string
    {
        $origin = config('sms.webhook_origin');
        $p = is_string($origin) ? parse_url($origin) : false;
        abort_unless(Str::isUuid($connection) && Str::isUuid($delivery) && $p && ($p['scheme'] ?? '') === 'https' && isset($p['host']) && ! isset($p['user']) && ! isset($p['pass']) && ! isset($p['query']) && ! isset($p['fragment']) && in_array($p['path'] ?? '', ['', '/'], true), 503);

        return rtrim($origin, '/').'/api/v1/integration-webhooks/sms/'.$connection.'/'.$delivery;
    }

    public function send(#[\SensitiveParameter] array $credentials, #[\SensitiveParameter] array $payload, string $connection, string $delivery): string
    {
        $c = $this->credentials($credentials);
        app(SmsNodes::class)->message($payload['text']);
        $data = $this->request('POST', 'https://api.twilio.com/2010-04-01/Accounts/'.$c['account_sid'].'/Messages.json', $c, ['From' => $this->phone($payload['sender_phone']), 'To' => $this->phone($payload['recipient_phone']), 'Body' => $payload['text'], 'StatusCallback' => $this->callbackUrl($connection, $delivery)], true);
        if (($data['account_sid'] ?? '') !== $c['account_sid'] || ! preg_match('/^SM[0-9a-f]{32}$/Di', $data['sid'] ?? '')) {
            throw new SmsApiFailure('invalid_response', ambiguous: true);
        }

        return $data['sid'];
    }

    public function messageStatus(#[\SensitiveParameter] array $credentials, string $sid): string
    {
        $c = $this->credentials($credentials);
        abort_unless(preg_match('/^SM[0-9a-f]{32}$/Di', $sid), 422);
        $data = $this->request('GET', 'https://api.twilio.com/2010-04-01/Accounts/'.$c['account_sid'].'/Messages/'.$sid.'.json', $c);
        abort_unless(($data['sid'] ?? '') === $sid && ($data['account_sid'] ?? '') === $c['account_sid'] && is_string($data['status'] ?? null), 422);

        return $data['status'];
    }

    private function request(string $method, string $url, #[\SensitiveParameter] array $c, #[\SensitiveParameter] array $data = [], bool $delivery = false): array
    {
        try {
            $r = $this->http->withBasicAuth($c['account_sid'], $c['auth_token'])->acceptJson()->connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false, 'verify' => true, 'debug' => false, 'progress' => function ($total, $downloaded) {
                if ($downloaded > 1048576) {
                    throw new \RuntimeException('Response limit.');
                }
            }]);
            $response = $method === 'GET' ? $r->get($url, $data) : $r->asForm()->post($url, $data);
        } catch (\Throwable) {
            throw new SmsApiFailure('transport_timeout', ! $delivery, 30, $delivery);
        }
        if ($response->status() === 429) {
            throw new SmsApiFailure('rate_limited', true, max(1, min(86400, (int) ($response->header('Retry-After') ?: 30))));
        }
        if ($response->serverError()) {
            throw new SmsApiFailure('provider_unavailable', ! $delivery, 30, $delivery);
        }
        if (! $response->successful()) {
            throw new SmsApiFailure($response->status() === 401 ? 'credentials_rejected' : 'provider_rejected');
        }
        $body = $response->json();
        if (! is_array($body) || ($delivery && $response->status() !== 201)) {
            throw new SmsApiFailure('invalid_response', ambiguous: $delivery);
        }

        return $body;
    }
}
