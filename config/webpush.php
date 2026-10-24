<?php

/*
 * Official push services: Chrome (FCM), Firefox (Mozilla autopush), Safari and
 * iOS Home Screen apps (Apple), and Edge on Windows (WNS, which uses regional
 * hosts such as wns2-ch1p.notify.windows.com).
 */
$defaultPushHosts = [
    'fcm.googleapis.com',
    'updates.push.services.mozilla.com',
    'web.push.apple.com',
    '*.notify.windows.com',
];

$configuredPushHosts = array_values(array_filter(array_map(
    static fn (string $host): string => strtolower(trim($host)),
    explode(',', (string) env('WEBPUSH_ALLOWED_ENDPOINT_HOSTS', '')),
), static fn (string $host): bool => $host !== ''));

$allowedEndpointHosts = $configuredPushHosts !== [] ? $configuredPushHosts : $defaultPushHosts;

return [

    /**
     * These are the keys for authentication (VAPID).
     * These keys must be safely stored and should not change.
     */
    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    /**
     * HTTPS delivery hosts accepted from browser PushSubscription data.
     *
     * WEBPUSH_ALLOWED_ENDPOINT_HOSTS (comma separated) replaces the defaults.
     * When it is empty the official browser push services above are used, so
     * a blank environment value never silently blocks every enrollment. An
     * entry is an exact host, or `*.example.com`, which matches one or more
     * whole labels in front of example.com (never a lookalike suffix). URLs,
     * IP addresses and bare or top-level wildcards are never accepted.
     *
     * @var list<string>
     */
    'allowed_endpoint_hosts' => $allowedEndpointHosts,

    /**
     * The push subscription model: the package model plus device health.
     */
    'model' => \App\Models\HubPushSubscription::class,

    /**
     * This is the name of the table that will be created by the migration and
     * used by the PushSubscription model shipped with this package.
     */
    'table_name' => env('WEBPUSH_DB_TABLE', 'push_subscriptions'),

    /**
     * This is the database connection that will be used by the migration and
     * the PushSubscription model shipped with this package.
     */
    'database_connection' => env('WEBPUSH_DB_CONNECTION', env('DB_CONNECTION', 'sqlite')),

    /**
     * The Guzzle client options used by Minishlink\WebPush.
     */
    'client_options' => [],

    /**
     * The automatic padding in bytes used by Minishlink\WebPush.
     * Set to false to support Firefox Android with v1 endpoint.
     */
    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),

];
