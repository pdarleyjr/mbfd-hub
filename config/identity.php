<?php

declare(strict_types=1);

$issuer = trim((string) env('AUTHENTIK_ISSUER', ''));

return [
    'mode' => env('MBFD_IDENTITY_MODE', 'local'),
    'credential_authority' => env('MBFD_CREDENTIAL_AUTHORITY', 'local'),
    'provider' => 'authentik',
    'local_login_enabled' => env('MBFD_LOCAL_LOGIN_ENABLED', true),
    'employee_bootstrap_login_enabled' => env('MBFD_EMPLOYEE_BOOTSTRAP_LOGIN_ENABLED', true),
    'canary_user_ids' => array_values(array_filter(array_map(
        static fn (string $value): ?int => ctype_digit(trim($value)) ? (int) trim($value) : null,
        explode(',', (string) env('MBFD_AUTHENTIK_CANARY_USER_IDS', '')),
    ))),
    'authentik' => [
        'issuer' => $issuer === '' ? '' : rtrim($issuer, '/').'/',
        'api_url' => rtrim((string) env('AUTHENTIK_API_URL', ''), '/'),
        'api_token' => env('AUTHENTIK_API_TOKEN'),
        'client_id' => env('AUTHENTIK_CLIENT_ID'),
        'client_secret' => env('AUTHENTIK_CLIENT_SECRET'),
        'redirect_uri' => env('AUTHENTIK_REDIRECT_URI'),
        'post_logout_redirect_uri' => env('AUTHENTIK_POST_LOGOUT_REDIRECT_URI'),
        'allowed_algorithm' => 'RS256',
        'clock_skew_seconds' => 30,
        'connect_timeout_seconds' => 3,
        'timeout_seconds' => 8,
        'recovery_link_seconds' => 900,
        'import_password_hash' => env('AUTHENTIK_IMPORT_PASSWORD_HASH', false),
    ],
];
