<?php

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'cloudflare' => [
        'worker_url' => env('CLOUDFLARE_WORKER_URL', 'https://mbfd-support-ai.pdarleyjr.workers.dev'),
        'api_secret' => env('CLOUDFLARE_API_SECRET'),
        'ai' => [
            'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
            'api_token' => env('CLOUDFLARE_API_TOKEN'),
            'enabled' => env('AI_ANALYSIS_ENABLED', false),
            'models' => [
                'default' => '@cf/meta/llama-3-8b-instruct',
                'fallback' => '@cf/meta/llama-2-7b-chat-int8',
                'alternative' => '@hf/meta-llama/meta-llama-3-8b-instruct',
            ],
            'rate_limit' => [
                'daily_neurons' => 9900,
                'retry_attempts' => 3,
                'retry_delay' => 1000,
                'cache_key' => 'cloudflare_ai_requests',
            ],
            'timeouts' => [
                'connect' => 10,
                'request' => 30,
            ],
        ],
    ],

    'workgroup_ai' => [
        'url' => env('WORKGROUP_AI_WORKER_URL', null),
    ],

];
