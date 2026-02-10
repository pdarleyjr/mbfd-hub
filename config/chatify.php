<?php

return [
    /*
    |-------------------------------------
    | Messenger display name
    |-------------------------------------
    */
    'name' => env('CHATIFY_NAME', 'Chatify Messenger'),

    /*
    |-------------------------------------
    | The disk on which to store added
    | files and derived images by default.
    |-------------------------------------
    */
    'storage_disk_name' => env('CHATIFY_STORAGE_DISK', 'public'),

    /*
    |-------------------------------------
    | Routes configurations
    |-------------------------------------
    */
    'routes' => [
        'custom' => env('CHATIFY_CUSTOM_ROUTES', false),
        'prefix' => env('CHATIFY_ROUTES_PREFIX', 'internal/chatify'),
        'middleware' => ['web', 'auth'],
        'namespace' => env('CHATIFY_ROUTES_NAMESPACE', 'App\Http\Controllers\vendor\Chatify'),
    ],
    'api_routes' => [
        'prefix' => env('CHATIFY_API_ROUTES_PREFIX', 'chatify/api'),
        'middleware' => env('CHATIFY_API_ROUTES_MIDDLEWARE', ['api']),
        'namespace' => env('CHATIFY_API_ROUTES_NAMESPACE', 'App\Http\Controllers\vendor\Chatify\Api'),
    ],

    /*
    |-------------------------------------
    | Pusher API credentials
    | Updated to use Laravel Reverb (Pusher-protocol compatible)
    |-------------------------------------
    */
    'pusher' => [
        'debug' => env('APP_DEBUG', false),
        'key' => env('REVERB_APP_KEY', env('PUSHER_APP_KEY')),
        'secret' => env('REVERB_APP_SECRET', env('PUSHER_APP_SECRET')),
        'app_id' => env('REVERB_APP_ID', env('PUSHER_APP_ID')),
        'options' => [
            'cluster' => env('REVERB_APP_CLUSTER', env('PUSHER_APP_CLUSTER', 'mt1')),
            'host' => env('REVERB_HOST', env('PUSHER_HOST', '127.0.0.1')),
            'port' => env('REVERB_PORT', env('PUSHER_PORT', 8080)),
            'scheme' => env('REVERB_SCHEME', env('PUSHER_SCHEME', 'http')),
            'encrypted' => true,
            'useTLS' => env('REVERB_SCHEME', env('PUSHER_SCHEME', 'http')) === 'https',
        ],
    ],

    /*
    |-------------------------------------
    | User Avatar
    |-------------------------------------
    */
    'user_avatar' => [
        'folder' => 'users-avatar',
        'default' => 'avatar.png',
    ],

    /*
    |-------------------------------------
    | Gravatar
    |
    | imageset property options:
    | [ 404 | mp | identicon (default) | monsterid | wavatar ]
    |-------------------------------------
    */
    'gravatar' => [
        'enabled' => true,
        'image_size' => 200,
        'imageset' => 'identicon'
    ],

    /*
    |-------------------------------------
    | Attachments
    |-------------------------------------
    */
    'attachments' => [
        'folder' => 'attachments',
        'download_route_name' => 'attachments.download',
        'allowed_images' => ['png', 'jpg', 'jpeg', 'gif'],
        'allowed_files' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip'],
        'max_upload_size' => 10240, // 10MB in KB
    ],

    /*
    |-------------------------------------
    | Messenger's colors
    |-------------------------------------
    */
    'colors' => (array) [
        '#2180f3',
        '#2196F3',
        '#00BCD4',
        '#3F51B5',
        '#673AB7',
        '#4CAF50',
        '#FFC107',
        '#FF9800',
        '#ff2522',
        '#9C27B0',
    ],
    /*
    |-------------------------------------
    | Sounds
    | You can enable/disable the sounds and
    | change sound's name/path placed at
    | `public/` directory of your app.
    |
    |-------------------------------------
    */
    'sounds' => [
        'enabled' => true,
        'public_path' => 'sounds/chatify',
        'new_message' => 'new-message-sound.mp3',
    ]
];
