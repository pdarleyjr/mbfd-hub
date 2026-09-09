<?php

return [
    'ai' => [
        'enabled' => env('CLOUDFLARE_AI_ENABLED', false),
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID', ''),
        'api_token' => env('CLOUDFLARE_API_TOKEN', ''),

        // The gateway driver sends only a logical capability. Backend and
        // physical-model selection remain exclusively gateway-owned.
        'driver' => env('AI_DRIVER', 'gateway'),
        'gateway' => [
            'url' => env('AI_GATEWAY_URL', ''),
            'capability' => env('AI_GATEWAY_CAPABILITY', 'mbfd-general'),
            'credential' => env('AI_GATEWAY_CREDENTIAL', ''),
            'timeout' => (int) env('AI_GATEWAY_TIMEOUT', 360),
        ],

        'models' => [
            'default' => env('CLOUDFLARE_AI_MODEL', '@cf/meta/llama-3-8b-instruct'),
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
