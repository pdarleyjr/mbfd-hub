<?php

declare(strict_types=1);

if (! defined('MBFD_PHPUNIT_BOOTSTRAP')) {
    define('MBFD_PHPUNIT_BOOTSTRAP', true);
}

$allowDisposablePostgres = getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') === '1';
$disposablePostgres = [
    'host' => (string) getenv('DISPOSABLE_POSTGRES_HOST'),
    'port' => (string) getenv('DISPOSABLE_POSTGRES_PORT'),
    'database' => (string) getenv('DISPOSABLE_POSTGRES_DATABASE'),
    'username' => (string) getenv('DISPOSABLE_POSTGRES_USERNAME'),
    'password' => (string) getenv('DISPOSABLE_POSTGRES_PASSWORD'),
];

if ($allowDisposablePostgres
    && ($disposablePostgres['host'] !== '127.0.0.1'
        || ! preg_match('/^[1-9][0-9]{3,4}$/', $disposablePostgres['port'])
        || (int) $disposablePostgres['port'] > 65535
        || ! preg_match('/^mbfd_hub_test_[a-z0-9_]+$/', $disposablePostgres['database'])
        || ! preg_match('/^mbfd_test_[a-z0-9_]+$/', $disposablePostgres['username'])
        || $disposablePostgres['password'] !== '')) {
    fwrite(STDERR, "Disposable PostgreSQL must be loopback-only with a dedicated mbfd_hub_test_* database and mbfd_test_* user.\n");
    exit(2);
}

$testEnvironment = [
    'ABLY_KEY' => '',
    'AI_ANALYSIS_ENABLED' => 'false',
    'AI_GATEWAY_ENABLED' => 'false',
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'APP_URL' => 'http://localhost',
    'AWS_ACCESS_KEY_ID' => '',
    'AWS_ENDPOINT' => '',
    'AWS_SECRET_ACCESS_KEY' => '',
    'BCRYPT_ROUNDS' => '4',
    'BID_CONSOLE_URL' => '',
    'BID_FEDERATION_TOKEN' => '',
    'BID_READER_TOKEN' => '',
    'BROADCAST_DRIVER' => 'log',
    'CACHE_STORE' => 'array',
    'CLOUDFLARE_ACCOUNT_ID' => '',
    'CLOUDFLARE_AI_ENABLED' => 'false',
    'CLOUDFLARE_AI_GATEWAY_URL' => '',
    'CLOUDFLARE_API_SECRET' => '',
    'CLOUDFLARE_API_TOKEN' => '',
    'CLOUDFLARE_WORKER_API_SECRET' => '',
    'CLOUDFLARE_WORKER_URL' => '',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_HOST' => '',
    'DB_PASSWORD' => '',
    'DB_PORT' => '',
    'DB_USERNAME' => '',
    'DB_URL' => '',
    'DISPLAY_API_TOKEN' => '',
    'FILESYSTEM_DISK' => 'local',
    'GOOGLE_SERVICE_ACCOUNT_JSON_PATH' => '',
    'GOOGLE_SHEETS_APPARATUS_SYNC_ENABLED' => 'false',
    'HEALTH_SLACK_WEBHOOK_URL' => '',
    'LIVEKIT_API_KEY' => '',
    'LIVEKIT_API_SECRET' => '',
    'LIVEKIT_CLOUD_API_KEY' => '',
    'LIVEKIT_CLOUD_API_SECRET' => '',
    'LIVEKIT_CLOUD_API_URL' => '',
    'LIVEKIT_CLOUD_URL' => '',
    'LIVEKIT_SELF_HOSTED_API_KEY' => '',
    'LIVEKIT_SELF_HOSTED_API_SECRET' => '',
    'LIVEKIT_SELF_HOSTED_API_URL' => '',
    'LIVEKIT_SELF_HOSTED_URL' => '',
    'LIVEKIT_URL' => '',
    'MAIL_MAILER' => 'array',
    'MAIL_PASSWORD' => '',
    'MAIL_URL' => '',
    'OH_DEAR_HEALTH_CHECK_SECRET' => '',
    'OH_DEAR_HEALTH_CHECK_URL' => '',
    'AI_GATEWAY_CREDENTIAL_FILE' => '',
    'AI_GATEWAY_URL' => '',
    'POSTMARK_TOKEN' => '',
    'PRIVATE_FILESYSTEM_DISK' => 'local',
    'PULSE_ENABLED' => 'false',
    'PULSEPOINT_WORKER_URL' => '',
    'PUSHER_APP_KEY' => '',
    'PUSHER_APP_SECRET' => '',
    'QUEUE_CONNECTION' => 'sync',
    'R2_ACCESS_KEY_ID' => '',
    'R2_ENDPOINT' => '',
    'R2_SECRET_ACCESS_KEY' => '',
    'REDIS_PASSWORD' => '',
    'REDIS_URL' => '',
    'RESEND_KEY' => '',
    'SCREENTINKER_SYNC_TOKEN' => '',
    'SCREENTINKER_SYNC_URL' => '',
    'SENTRY_DSN' => '',
    'SENTRY_AUTH_TOKEN' => '',
    'SENTRY_LARAVEL_DSN' => '',
    'SENTRY_ORG' => '',
    'SENTRY_PROJECT_FRONTEND' => '',
    'SESSION_DRIVER' => 'array',
    'SNIPEIT_API_TOKEN' => '',
    'SNIPEIT_API_URL' => '',
    'SLACK_BOT_USER_OAUTH_TOKEN' => '',
    'TELESCOPE_ENABLED' => 'false',
    'VAPID_PEM_FILE' => '',
    'VAPID_PRIVATE_KEY' => '',
    'VAPID_PUBLIC_KEY' => '',
    'VAPID_SUBJECT' => '',
    'VITE_SENTRY_DSN' => '',
    'VITE_SENTRY_RELEASE' => '',
    'VIDEO_CONFERENCING_ENABLED' => 'false',
    'WEBPUSH_ALLOWED_ENDPOINT_HOSTS' => '',
    'WEBPUSH_DB_CONNECTION' => $allowDisposablePostgres ? 'pgsql' : 'sqlite',
    'WORKGROUP_AI_ENABLED' => 'false',
    'WORKGROUP_AI_WORKER_SECRET' => '',
    'WORKGROUP_AI_WORKER_URL' => '',
];

if ($allowDisposablePostgres) {
    $testEnvironment = array_merge($testEnvironment, [
        'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE' => $disposablePostgres['database'],
        'DB_HOST' => $disposablePostgres['host'],
        'DB_PASSWORD' => '',
        'DB_PORT' => $disposablePostgres['port'],
        'DB_USERNAME' => $disposablePostgres['username'],
        'EXPECTED_TEST_DB_CONNECTION' => 'pgsql',
    ]);
}

foreach ($testEnvironment as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require dirname(__DIR__).'/vendor/autoload.php';
