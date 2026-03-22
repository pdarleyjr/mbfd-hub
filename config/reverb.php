<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Server Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration contains the default server configuration for
    | Laravel Reverb. It will start a Reverb server that listens on
    | the configured host and port.
    |
    */

    'default' => env('REVERB_SERVER', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Reverb Servers
    |--------------------------------------------------------------------------
    |
    | This array contains the list of servers that Reverb can use. You may
    | define multiple servers for load balancing. Each server should have
    | a unique name and the host and port it should bind to.
    |
    */

    'servers' => [

        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'hostname' => env('REVERB_HOSTNAME', 'localhost'),
            'options' => [
                'tls' => [],
            ],
            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
            ],
            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb Applications
    |--------------------------------------------------------------------------
    |
    | This array contains the list of application configurations. Each
    | application should have an id, key, and secret for authentication.
    | These applications are used to authenticate clients connecting to
    | the WebSocket server.
    |
    */

    'apps' => [

        [
            'app_id' => env('REVERB_APP_ID', 'app-id'),
            'key' => env('REVERB_APP_KEY', 'app-key'),
            'secret' => env('REVERB_APP_SECRET', 'app-secret'),
            'capacity' => null,
            'allowed_origins' => explode(',', env(
                'REVERB_ALLOWED_ORIGINS',
                'https://www.mbfdhub.com,https://mbfdhub.com'
            )),
            'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
            'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10000),
        ],

    ],

];
