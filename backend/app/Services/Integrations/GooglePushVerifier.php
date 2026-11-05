<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Cache;

class GooglePushVerifier
{
    public function __construct(private EmailHttp $http) {}

    public function verify(#[\SensitiveParameter] string $jwt): void
    {
        abort_unless(strlen($jwt) < 16000 && substr_count($jwt, '.') === 2, 403);
        [$header,$claims,$signature] = explode('.', $jwt);
        $h = json_decode($this->decode($header), true);
        $c = json_decode($this->decode($claims), true);
        abort_unless(is_array($h) && is_array($c) && ($h['alg'] ?? '') === 'RS256' && is_string($h['kid'] ?? null) && strlen($h['kid']) <= 100, 403);
        $audience = config('email_integrations.gmail.push_audience');
        $email = config('email_integrations.gmail.push_service_account');
        abort_unless(is_string($audience) && $audience !== '' && is_string($email) && $email !== '' && ($c['aud'] ?? '') === $audience && ($c['email'] ?? '') === $email && ($c['email_verified'] ?? false) === true && in_array($c['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true) && is_int($c['exp'] ?? null) && is_int($c['iat'] ?? null) && $c['exp'] > now()->timestamp && $c['iat'] <= now()->addMinute()->timestamp && $c['exp'] - $c['iat'] <= 3660 && $c['iat'] > now()->subHours(2)->timestamp, 403);
        $certificates = Cache::remember('email:google-push-certificates', 600, fn () => $this->http->googleCertificates());
        if (! isset($certificates[$h['kid']])) {
            Cache::forget('email:google-push-certificates');
            $certificates = $this->http->googleCertificates();
        }
        $certificate = $certificates[$h['kid']] ?? null;
        abort_unless(is_string($certificate) && strlen($certificate) < 16000 && openssl_verify($header.'.'.$claims, $this->decode($signature), $certificate, OPENSSL_ALGO_SHA256) === 1, 403);
    }

    private function decode(string $value): string
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]+$/D', $value), 403);
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        abort_unless(is_string($decoded), 403);

        return $decoded;
    }
}
