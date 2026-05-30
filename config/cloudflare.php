<?php

return [
    'ai' => [
        'enabled' => env('CLOUDFLARE_AI_ENABLED', false),
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID', ''),
        'api_token' => env('CLOUDFLARE_API_TOKEN', ''),

        // AI backend driver: 'local' routes generation to on-prem Ollama
        // (qwen3.6:35b) via LocalAIService; 'cloudflare' uses Workers AI.
        'driver' => env('AI_DRIVER', 'local'),
        'local' => [
            'url' => env('OLLAMA_URL', 'http://host.docker.internal:11434'),
            'model' => env('OLLAMA_MODEL', 'qwen3.6:35b'),
            // Local model cold-load (~45s) + generation can exceed the 30s CF
            // default; allow plenty of headroom so command-center calls don't
            // fail on a cold model. Warm calls finish in a few seconds.
            'timeout' => (int) env('OLLAMA_TIMEOUT', 120),
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
