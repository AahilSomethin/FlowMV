<?php

return [
    'active_key' => env('INTEGRATION_ACTIVE_KEY', 'v1'),
    'keys' => json_decode(env('INTEGRATION_KEYS', '{}'), true) ?: [],
    'step_up_seconds' => 300,
    'mock_enabled' => env('INTEGRATION_MOCK_ENABLED', false),
    'telegram_enabled' => env('INTEGRATION_TELEGRAM_ENABLED', false),
    'telegram_simulated' => env('INTEGRATION_TELEGRAM_SIMULATED', false),
    'telegram_webhook_origin' => env('INTEGRATION_TELEGRAM_WEBHOOK_ORIGIN'),
];
