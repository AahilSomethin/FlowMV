<?php

namespace App\Support;

use RuntimeException;

class EnvironmentChecker
{
    public static function assertValid(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        if (config('database.default') !== 'pgsql') {
            throw new RuntimeException('DB_CONNECTION must be pgsql outside the test suite.');
        }

        $frontendUrl = config('flowmv.frontend_url');

        if (! is_string($frontendUrl) || filter_var($frontendUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('FRONTEND_URL must be a valid URL.');
        }

        $host = parse_url($frontendUrl, PHP_URL_HOST);
        $port = parse_url($frontendUrl, PHP_URL_PORT);
        $frontendOrigin = $host.($port ? ':'.$port : '');

        $domains = config('sanctum.stateful');

        if (! is_array($domains)) {
            $domains = array_map('trim', explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', '')));
        }

        if (! in_array($frontendOrigin, $domains, true)) {
            throw new RuntimeException('SANCTUM_STATEFUL_DOMAINS must include the frontend host and port.');
        }

        if (config('session.driver') !== 'database') {
            throw new RuntimeException('SESSION_DRIVER must be database.');
        }

        if (app()->environment('production') && self::productionFindings() !== []) {
            throw new RuntimeException('Production configuration refused; review operations:check in isolated staging.');
        }
    }

    /** @return list<string> */
    public static function productionFindings(): array
    {
        $findings = [];
        if (config('flowmv.migrator_secret_present')) {
            $findings[] = 'Migrator credentials must not enter application runtime';
        }
        foreach (['app.url', 'flowmv.frontend_url'] as $key) {
            if (parse_url((string) config($key), PHP_URL_SCHEME) !== 'https') {
                $findings[] = $key.': HTTPS required';
            }
        }
        if (config('app.debug')) {
            $findings[] = 'Debug must be disabled';
        }
        if (! config('session.secure') || ! config('session.http_only') || ! in_array(config('session.same_site'), ['lax', 'strict'], true)) {
            $findings[] = 'Secure HttpOnly same-site session required';
        }
        if (config('queue.default') !== 'database') {
            $findings[] = 'Reviewed durable database queue required';
        }
        if ((int) config('queue.connections.database.retry_after') <= 120) {
            $findings[] = 'Queue retry lease must exceed the 120-second worker timeout';
        }
        if (config('database.connections.pgsql.sslmode') !== 'verify-full') {
            $findings[] = 'Verified PostgreSQL TLS required';
        }
        foreach (['ai.simulated', 'integrations.mock_enabled', 'integrations.telegram_simulated', 'email_integrations.simulated', 'slack.simulated', 'sms.simulated', 'demo.profile'] as $key) {
            if (config($key)) {
                $findings[] = $key.': simulation forbidden in production';
            }
        }
        if (in_array(config('mail.default'), ['log', 'array'], true) || in_array('log', config('mail.mailers.failover.mailers', []), true) && config('mail.default') === 'failover') {
            $findings[] = 'Non-logging approved mail transport required';
        }
        $key = config('integrations.keys.'.config('integrations.active_key'));
        $decoded = is_string($key) ? base64_decode($key, true) : false;
        $applicationKey = (string) config('app.key');
        $appKey = str_starts_with($applicationKey, 'base64:') ? base64_decode(substr($applicationKey, 7), true) : $applicationKey;
        if (! is_string($decoded) || strlen($decoded) !== 32 || (is_string($appKey) && hash_equals($decoded, $appKey))) {
            $findings[] = 'Independent 32-byte vault key required';
        }
        if (config('ai.enabled') && (parse_url((string) config('ai.service_url'), PHP_URL_SCHEME) !== 'https' || ! preg_match('/^[a-f0-9]{64}$/D', (string) config('ai.service_key')))) {
            $findings[] = 'Private authenticated AI TLS configuration required';
        }
        $serviceKey = (string) config('ai.service_key');
        if (preg_match('/^[a-f0-9]{64}$/D', $serviceKey)) {
            $bytes = hex2bin($serviceKey);
            if ((is_string($decoded) && hash_equals($decoded, $bytes)) || (is_string($appKey) && hash_equals($appKey, $bytes))) {
                $findings[] = 'AI signing key must be independent of vault and application keys';
            }
        }

        return $findings;
    }
}
