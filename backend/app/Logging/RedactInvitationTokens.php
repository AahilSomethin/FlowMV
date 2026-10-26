<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

class RedactInvitationTokens
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(function (LogRecord $record): LogRecord {
            return $record->with(
                message: self::redact($record->message),
                context: self::redactValue($record->context),
                extra: self::redactValue($record->extra),
            );
        });
    }

    public static function redact(string $value): string
    {
        $patterns = [
            '/(#token=)[^&\s"\'<>]+/i',
            '/([?&]token=)[^&\s"\'<>]+/i',
            '/("token"\s*:\s*")[^"]*/i',
            '/(%23token%3D)[^&\s"\'<>]+/i',
            '/((?:^|[?&#])token%3D)[^&\s"\'<>]+/i',
        ];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, '$1[redacted]', $value) ?? $value;
        }

        return $value;
    }

    public static function redactValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::redact($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && strtolower($key) === 'token') {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = self::redactValue($item);
        }

        return $redacted;
    }
}
