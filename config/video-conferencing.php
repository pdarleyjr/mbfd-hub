<?php

$lineupTime = env('VIDEO_CONFERENCING_LINEUP_TIME');
$lineupTime = is_string($lineupTime) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $lineupTime)
    ? $lineupTime
    : null;

return [
    'enabled' => (bool) env('VIDEO_CONFERENCING_ENABLED', false),
    'timezone' => 'America/New_York',
    'lineup_time' => $lineupTime,
    'command_pin_hash' => env('VIDEO_CONFERENCING_COMMAND_PIN_HASH'),
    'livekit' => [
        'url' => env('LIVEKIT_URL'),
        'api_url' => env('LIVEKIT_API_URL'),
        'api_key' => env('LIVEKIT_API_KEY'),
        'api_secret' => env('LIVEKIT_API_SECRET'),
        'token_ttl_seconds' => (int) env('LIVEKIT_TOKEN_TTL_SECONDS', 600),
        'empty_timeout_seconds' => (int) env('LIVEKIT_EMPTY_TIMEOUT_SECONDS', 300),
        'max_participants' => (int) env('LIVEKIT_MAX_PARTICIPANTS', 12),
    ],
];
