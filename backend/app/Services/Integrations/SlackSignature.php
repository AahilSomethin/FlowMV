<?php

namespace App\Services\Integrations;

class SlackSignature
{
    public function verify(#[\SensitiveParameter] string $body, string $timestamp, string $signature): bool
    {
        $secret = config('slack.signing_secret');
        if (! is_string($secret) || strlen($secret) < 16 || ! preg_match('/^[0-9]{10}$/D', $timestamp) || abs(now()->timestamp - (int) $timestamp) > 300 || ! preg_match('/^v0=[a-f0-9]{64}$/D', $signature) || strlen($body) > 65536) {
            return false;
        }

        return hash_equals('v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$body, $secret), $signature);
    }
}
