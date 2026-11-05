<?php

return [
    'enabled' => env('INTEGRATION_EMAIL_ENABLED', false),
    'simulated' => env('INTEGRATION_EMAIL_SIMULATED', false),
    'webhook_origin' => env('INTEGRATION_EMAIL_WEBHOOK_ORIGIN'),
    'gmail' => [
        'client_id' => env('GOOGLE_MAIL_CLIENT_ID'),
        'client_secret' => env('GOOGLE_MAIL_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_MAIL_REDIRECT_URI'),
        'topic' => env('GOOGLE_MAIL_PUBSUB_TOPIC'),
        'subscription' => env('GOOGLE_MAIL_PUBSUB_SUBSCRIPTION'),
        'push_audience' => env('GOOGLE_MAIL_PUSH_AUDIENCE'),
        'push_service_account' => env('GOOGLE_MAIL_PUSH_SERVICE_ACCOUNT'),
    ],
    'outlook' => [
        'client_id' => env('MICROSOFT_MAIL_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_MAIL_CLIENT_SECRET'),
        'redirect_uri' => env('MICROSOFT_MAIL_REDIRECT_URI'),
        'tenant' => env('MICROSOFT_MAIL_TENANT', 'organizations'),
    ],
    'documents' => [
        'max_bytes' => min(8 * 1024 * 1024, max(1024, (int) env('DOCUMENT_MAX_BYTES', 8 * 1024 * 1024))),
        'max_count' => min(5, max(1, (int) env('DOCUMENT_MAX_COUNT', 5))),
        'retention_days' => min(90, max(1, (int) env('DOCUMENT_RETENTION_DAYS', 30))),
        'clamav_host' => env('DOCUMENT_CLAMAV_HOST', '127.0.0.1'),
        'clamav_port' => (int) env('DOCUMENT_CLAMAV_PORT', 3310),
        'container_runtime' => env('DOCUMENT_CONTAINER_RUNTIME'),
        'pdf_image' => env('DOCUMENT_PDF_IMAGE'),
    ],
];
