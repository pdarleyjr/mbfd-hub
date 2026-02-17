<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

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

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Workers AI Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Cloudflare Workers AI integration for intelligent
    | capital project prioritization and analysis.
    |
    | To obtain your Account ID:
    | 1. Log in to https://dash.cloudflare.com
    | 2. Select your account
    | 3. Go to Workers & Pages
    | 4. Your Account ID is displayed in the right sidebar
    |
    */

    'cloudflare' => [
        // Legacy worker configuration (keep for compatibility)
        'worker_url' => env('CLOUDFLARE_WORKER_URL', 'https://mbfd-support-ai.pdarleyjr.workers.dev'),
        'api_secret' => env('CLOUDFLARE_API_SECRET'),
        
        // Workers AI configuration
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
                'daily_neurons' => 9900, // Stay under 10k free tier limit
                'retry_attempts' => 3,
                'retry_delay' => 1000, // milliseconds
                'cache_key' => 'cloudflare_ai_requests',
            ],
            
            'timeouts' => [
                'connect' => 10,
                'request' => 30,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Gmail OAuth Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Gmail API integration for sending supply order emails
    | to vendors. Uses OAuth 2.0 with refresh tokens for authentication.
    |
    */

    'gmail' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'refresh_token' => env('GOOGLE_REFRESH_TOKEN'),
        'sender_email' => env('GMAIL_SENDER_EMAIL', 'mbfdsupport@gmail.com'),
    ],

];
