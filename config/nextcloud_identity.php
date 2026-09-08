<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('NEXTCLOUD_IDENTITY_SYNC_ENABLED', false),
    'ssh_key' => env('NEXTCLOUD_IDENTITY_SSH_KEY'),
    'known_hosts' => env('NEXTCLOUD_IDENTITY_KNOWN_HOSTS'),
];
