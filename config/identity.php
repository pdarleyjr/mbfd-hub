<?php

declare(strict_types=1);

$issuer = trim((string) env('AUTHENTIK_ISSUER', ''));

return [
    'mode' => env('MBFD_IDENTITY_MODE', 'local'),
    'credential_authority' => env('MBFD_CREDENTIAL_AUTHORITY', 'local'),
    'provider' => 'authentik',
    'local_login_enabled' => env('MBFD_LOCAL_LOGIN_ENABLED', true),
    // bcrypt cost 12; generated once from discarded random input. It can never
    // authenticate and avoids generating a new timing-equalization hash per request.
    'canonical_login_dummy_password_hash' => '$2y$12$OcYMvqtWBKLbTVruW3WIDOVrJmPa7FCVZDXFuvZ443vuZkY.6mRpq',
    // The retired Employee principal remains disabled. Member bootstrap is a
    // separate restricted flow that never authenticates the Employee guard.
    'employee_bootstrap_login_enabled' => false,
    'member_bootstrap' => [
        'enabled' => env('MBFD_MEMBER_BOOTSTRAP_ENABLED', false),
        'password_hash' => env('MBFD_MEMBER_BOOTSTRAP_PASSWORD_HASH'),
        'session_ttl_seconds' => 900,
    ],
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
