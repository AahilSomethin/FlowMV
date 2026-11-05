<?php

return [
    'enabled' => (bool) env('AI_ENABLED', false),
    'simulated' => (bool) env('AI_SIMULATED', false),
    'service_url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8020'),
    'service_key' => env('AI_SERVICE_KEY'),
    'ca_file' => env('AI_CA_CERT'),
    'models' => ['openai' => ['gpt-5.4-mini'], 'gemini' => ['gemini-3.8-flash']],
];
