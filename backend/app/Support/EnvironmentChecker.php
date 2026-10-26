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
    }
}
