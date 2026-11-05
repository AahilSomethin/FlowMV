<?php

return [
    'enabled' => env('SLACK_ENABLED', false),
    'simulated' => env('SLACK_SIMULATED', false),
    'client_id' => env('SLACK_CLIENT_ID'),
    'client_secret' => env('SLACK_CLIENT_SECRET'),
    'signing_secret' => env('SLACK_SIGNING_SECRET'),
    'app_id' => env('SLACK_APP_ID'),
    'pkce' => env('SLACK_PKCE_ENABLED', false),
    'redirect_uri' => env('SLACK_REDIRECT_URI'),
    'scopes' => ['commands', 'app_mentions:read', 'chat:write', 'channels:read', 'groups:read'],
];
