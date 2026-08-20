<?php

$lineupTime = env('VIDEO_CONFERENCING_LINEUP_TIME');
$lineupTime = is_string($lineupTime) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $lineupTime)
    ? $lineupTime
    : null;
$livekitProfile = env('VIDEO_CONFERENCING_LIVEKIT_PROFILE', 'self_hosted');
$livekitProfile = in_array($livekitProfile, ['cloud', 'self_hosted'], true)
    ? $livekitProfile
    : 'self_hosted';

return [
    'enabled' => (bool) env('VIDEO_CONFERENCING_ENABLED', false),
    'timezone' => 'America/New_York',
    'lineup_time' => $lineupTime,
    'lineup_max_minutes' => (int) env('VIDEO_CONFERENCING_LINEUP_MAX_MINUTES', 15),
    'readiness' => [
        'poll_seconds' => (int) env('VIDEO_CONFERENCING_STATUS_POLL_SECONDS', 5),
        'heartbeat_seconds' => (int) env('VIDEO_CONFERENCING_READY_HEARTBEAT_SECONDS', 20),
        'stale_after_seconds' => (int) env('VIDEO_CONFERENCING_READY_STALE_SECONDS', 75),
    ],
    'usage' => [
        'information_gb' => (int) env('VIDEO_CONFERENCING_USAGE_INFORMATION_GB', 30),
        'warning_gb' => (int) env('VIDEO_CONFERENCING_USAGE_WARNING_GB', 35),
        'conservation_gb' => (int) env('VIDEO_CONFERENCING_USAGE_CONSERVATION_GB', 40),
        'aggressive_gb' => (int) env('VIDEO_CONFERENCING_USAGE_AGGRESSIVE_GB', 45),
    ],
    'command_pin_hash' => env('VIDEO_CONFERENCING_COMMAND_PIN_HASH'),
    'client_failure_degraded_threshold' => (int) env('VIDEO_CONFERENCING_CLIENT_FAILURE_DEGRADED_THRESHOLD', 3),
    'client_transport' => env(
        'VIDEO_CONFERENCING_CLIENT_TRANSPORT',
        $livekitProfile === 'cloud' ? 'livekit_cloud' : 'tailnet',
    ),
    'realtime' => [
        'host' => env('REVERB_HOST', parse_url((string) env('APP_URL'), PHP_URL_HOST)),
        'port' => (int) env('REVERB_PORT', 443),
        'scheme' => env('REVERB_SCHEME', 'https'),
    ],
    'livekit' => [
        'profile' => $livekitProfile,
        'profiles' => [
            'cloud' => [
                'url' => env('LIVEKIT_CLOUD_URL'),
                'api_url' => env('LIVEKIT_CLOUD_API_URL'),
                'api_key' => env('LIVEKIT_CLOUD_API_KEY'),
                'api_secret' => env('LIVEKIT_CLOUD_API_SECRET'),
            ],
            'self_hosted' => [
                'url' => env('LIVEKIT_SELF_HOSTED_URL', env('LIVEKIT_URL')),
                'api_url' => env('LIVEKIT_SELF_HOSTED_API_URL', env('LIVEKIT_API_URL')),
                'api_key' => env('LIVEKIT_SELF_HOSTED_API_KEY', env('LIVEKIT_API_KEY')),
                'api_secret' => env('LIVEKIT_SELF_HOSTED_API_SECRET', env('LIVEKIT_API_SECRET')),
            ],
        ],
        'token_ttl_seconds' => (int) env('LIVEKIT_TOKEN_TTL_SECONDS', 600),
        'empty_timeout_seconds' => (int) env('LIVEKIT_EMPTY_TIMEOUT_SECONDS', 300),
        'max_participants' => (int) env('LIVEKIT_MAX_PARTICIPANTS', 12),
    ],
];
