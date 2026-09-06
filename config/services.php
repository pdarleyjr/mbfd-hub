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
    | Cloudflare Worker Integration
    |--------------------------------------------------------------------------
    |
    | The support worker remains a separate bounded HTTP integration. Hub
    | generation is configured only under cloudflare.ai.gateway.
    |
    */

    'cloudflare' => [
        'worker_url' => env('CLOUDFLARE_WORKER_URL', 'https://mbfd-support-ai.pdarleyjr.workers.dev'),
        'api_secret' => env('CLOUDFLARE_API_SECRET'),
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
    | MBFD Bid (Cloudflare Workers) Bridge
    |--------------------------------------------------------------------------
    |
    | Separate service credentials gate the canonical federation endpoints and
    | the transitional password verifier. If either credential is unset, its
    | middleware fails closed (503); neither credential falls back to the other.
    |
    */
    'bid' => [
        'reader_token' => env('BID_READER_TOKEN'),
        'federation_token' => env('BID_FEDERATION_TOKEN'),
        // Convenience: where to send a member when they click "Bid Console"
        // from the portal home. Falls back to staging during cutover.
        'console_url' => env('BID_CONSOLE_URL', 'https://staging.bid.mbfdhub.com'),
        'authorization' => [
            'issuer' => env('BID_AUTH_ISSUER', 'https://www.mbfdhub.com'),
            'code_ttl_seconds' => 60,
            'clients' => [
                'bid' => [
                    'callbacks' => [
                        'https://bid.mbfdhub.com/api/auth/callback',
                        'https://staging.bid.mbfdhub.com/api/auth/callback',
                    ],
                ],
            ],
        ],
    ],

    'media_control' => [
        'authorization' => [
            'issuer' => env('MEDIA_CONTROL_AUTH_ISSUER', 'https://www.mbfdhub.com'),
            'service_token' => env('MEDIA_CONTROL_FEDERATION_TOKEN'),
            'code_ttl_seconds' => 60,
            'clients' => [
                'media-control' => [
                    'callbacks' => [
                        'https://media.mbfdhub.com/api/auth/hub/callback',
                    ],
                ],
            ],
        ],
    ],

];
