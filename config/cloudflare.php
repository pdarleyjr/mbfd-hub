<?php

return [
    'ai' => [
        'enabled' => env('AI_GATEWAY_ENABLED', true),
        'gateway' => [
            'url' => env('AI_GATEWAY_URL', 'http://172.20.0.1:11440'),
            'capability' => env('AI_GATEWAY_CAPABILITY', 'mbfd-general'),
            // This is a protected file reference, never the credential value.
            'credential_file' => env('AI_GATEWAY_CREDENTIAL_FILE', '/run/secrets/mbfd-hub-ai-gateway-token'),
            'timeout' => (int) env('AI_GATEWAY_TIMEOUT', 120),
        ],

        'rate_limit' => [
            'daily_neurons' => env('CLOUDFLARE_AI_DAILY_LIMIT', 9900),
            'cache_key' => 'cloudflare_ai_requests',
            'retry_attempts' => 3,
            'retry_delay' => 1000, // milliseconds
        ],

        'timeouts' => [
            'connect' => 10, // seconds
            'request' => 30, // seconds
        ],
    ],

    // NEW: Worker integration
    'worker_url' => env('CLOUDFLARE_WORKER_URL', 'https://mbfd-support-ai.pdarleyjr.workers.dev'),
    'worker_api_secret' => env('CLOUDFLARE_WORKER_API_SECRET'),
];
