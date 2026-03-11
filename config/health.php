<?php

return [

    /*
     * A result store is responsible for saving the results of the health checks.
     */
    'result_stores' => [
        Spatie\Health\ResultStores\EloquentHealthResultStore::class => [
            'model' => Spatie\Health\Models\HealthCheckResultHistoryItem::class,
            'keep_history_for_days' => 5,
        ],
    ],

    /*
     * You can get notified when specific events occur. Out of the box you can use 'mail' and 'slack'.
     */
    'notifications' => [
        'enabled' => true,

        'notifications' => [
            Spatie\Health\Notifications\CheckFailedNotification::class => ['mail'],
        ],

        'notifiable' => Spatie\Health\Notifications\Notifiable::class,

        'throttle_notifications_for_minutes' => 60,
        'throttle_notifications_key' => 'health:notifications:throttle',

        'mail' => [
            'to' => env('HEALTH_NOTIFICATION_EMAIL', 'admin@mbfdhub.com'),
        ],

        'slack' => [
            'webhook_url' => env('HEALTH_SLACK_WEBHOOK_URL', ''),
        ],
    ],

    /*
     * You can let Oh Dear monitor your application by sending a check result.
     */
    'oh_dear_endpoint' => [
        'enabled' => false,
        'always_send_fresh_results' => true,
        'secret' => env('OH_DEAR_HEALTH_CHECK_SECRET'),
        'url' => env('OH_DEAR_HEALTH_CHECK_URL'),
    ],

    /*
     * The number of seconds that is allowed between making health check requests.
     */
    'throttle_check_time_in_seconds' => 10,

    /*
     * The URL that the health check results will be available on.
     */
    'json_results_url' => '/health',

    'registered_checks' => [
        // Checks are registered in App\Providers\HealthServiceProvider
    ],

];
