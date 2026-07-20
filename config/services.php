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

    'pulsepoint' => [
        'worker_url' => env('PULSEPOINT_WORKER_URL', 'https://pulsepoint-proxy.pdarleyjr.workers.dev/incidents'),
    ],

    // Shared secret guarding the read-only /api/display/* surface. When set, the
    // command-display Cloudflare Functions gateway must present it as X-Display-Token.
    // Empty/unset = open (no-op) for local/dev and staged rollout.
    'display_api' => [
        'token' => env('DISPLAY_API_TOKEN'),
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
    | Snipe-IT Configuration
    |--------------------------------------------------------------------------
    */

    'snipeit' => [
        'url' => env('SNIPEIT_API_URL', 'http://snipeit:80/api/v1/'),
        'token' => env('SNIPEIT_API_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | ScreenTinker Auth Mirror
    |--------------------------------------------------------------------------
    |
    | Configuration for the SyncToScreentinker observer that mirrors MBFD
    | admin password changes into the ScreenTinker user table at
    | https://media.mbfdhub.com. Leave SCREENTINKER_SYNC_URL or
    | SCREENTINKER_SYNC_TOKEN unset to disable the mirror entirely.
    |
    | Use the loopback URL (http://host.docker.internal:8095/...) when both
    | apps run on the same Linux host, to avoid going through the public
    | Cloudflare edge for an internal call.
    |
    */
    'screentinker' => [
        'sync_url' => env('SCREENTINKER_SYNC_URL'),
        'sync_token' => env('SCREENTINKER_SYNC_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | MBFD Bid (Cloudflare Workers) Bridge
    |--------------------------------------------------------------------------
    |
    | Shared bearer token gating the /api/v2/verify-credentials and
    | /api/v2/bid/* endpoints called by the bid Worker. The same value is set
    | as PORTAL_BID_READER on the Worker side. If unset, the middleware fails
    | closed (503) — the bridge is opt-in.
    |
    */
    'bid' => [
        'reader_token' => env('BID_READER_TOKEN'),
        // Convenience: where to send a member when they click "Bid Console"
        // from the portal home. Falls back to staging during cutover.
        'console_url' => env('BID_CONSOLE_URL', 'https://staging.bid.mbfdhub.com'),
    ],

];
