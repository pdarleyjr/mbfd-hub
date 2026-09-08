<?php

declare(strict_types=1);

return [
    'issuer' => env('OIDC_ISSUER', 'https://mbfdhub.com'),
    'clients' => [
        'cmd' => env('OIDC_CMD_CLIENT_ID', ''),
        'cloud' => env('OIDC_CLOUD_CLIENT_ID', ''),
    ],
];
