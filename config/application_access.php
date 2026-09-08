<?php

declare(strict_types=1);

return [
    // Deployment evidence is independent of a saved permission or OAuth client.
    // Set only after verifying the matching consumer release and configuration.
    'runtime_verified' => [
        'bid' => env('BID_IDENTITY_RUNTIME_VERIFIED', false),
        'media_control' => env('MEDIA_CONTROL_IDENTITY_RUNTIME_VERIFIED', false),
        'cmd' => env('CMD_IDENTITY_RUNTIME_VERIFIED', false),
        'cloud' => env('CLOUD_IDENTITY_RUNTIME_VERIFIED', false),
    ],
];
