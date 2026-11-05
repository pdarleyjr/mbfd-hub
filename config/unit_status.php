<?php

declare(strict_types=1);

/*
| Unit Status (/unit-status). Contract: docs/unit-status/unit-status-contract.md.
| Aliases below are configuration, not fleet master data: Apparatus and Station rows are never edited for this feature.
*/
return [
    'enabled' => filter_var(env('UNIT_STATUS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'source' => [
        // Command's private feed, reachable on the Docker network only. Empty env values fall back to the default.
        'url' => env('UNIT_STATUS_SOURCE_URL') ?: 'http://cmd-api:8200',
        'token' => env('UNIT_STATUS_SOURCE_TOKEN'),
        // Plain http is accepted for these hosts only; every other host must be https.
        'private_hosts' => ['cmd-api'],
        'connect_timeout' => 3,
        'timeout' => 8,
    ],

    'timezone' => 'America/New_York',

    // Written to the sync state once, on the first sync, and never reset afterwards.
    'tracking_starts_at' => env('UNIT_STATUS_TRACKING_STARTS_AT') ?: '2026-11-02 08:00',

    'sync_fresh_seconds' => 150,
    // A call open this long in PulsePoint is not treated as current: the unit shows "Not confirmed", never available.
    'current_call_max_age_hours' => 12,
    // v1.1 (contract 10.1): PulsePoint draws "?" units orange, which the collector reads as "Dispatched"; no confirmed unit
    // was ever reported Dispatched. So a "?" unit is on a call only this many minutes after the call was received, and
    // 0 (the default) means never: it has no status, no place on a call and no counted run. A confirmed unit still
    // Dispatched, Acknowledged or blank this many minutes after the call was received has moved on.
    'unconfirmed_grace_minutes' => 0,
    'stalled_dispatch_minutes' => 10,
    // Command's medical rule: CPT5, 300 and FB6 count as rescue on a medical call and as combat on any other call.
    'medical_call_pattern' => '/medical|medic|ems|cardiac|breathing|overdose|stroke|seizure|unconscious|injury|sick|expedite|interfacility/i',
    // One run stops paging after this long; the rest is a backlog the next run resumes exactly where this one stopped.
    'run_budget_seconds' => 20,
    // The built board is shared by everyone polling for this many seconds (0 turns the cache off).
    'board_cache_seconds' => 10,
    'overlap_seconds' => 120,
    'first_run_lookback_hours' => 12,
    'page_limit' => 200,
    'max_pages' => 10,
    'merge_check_seconds' => 300,
    'merge_lookback_hours' => 24,
    'breaker_threshold' => 3,
    'breaker_base_seconds' => 30,
    'breaker_max_seconds' => 300,

    // `class` is rescue, combat or by_call (counted by call type). Order is the order on the page. `designations` are matched exactly after normalization (never by prefix).
    'stations' => [
        1 => [
            ['code' => 'L1', 'name' => 'Ladder 1', 'class' => 'combat', 'designations' => ['L1'], 'roster_label' => 'Ladder 1'],
            ['code' => 'E1', 'name' => 'Engine 1', 'class' => 'combat', 'designations' => ['E1'], 'roster_label' => 'Engine 1'],
            ['code' => 'R1', 'name' => 'Rescue 1', 'class' => 'rescue', 'designations' => ['R1'], 'roster_label' => 'Rescue 1'],
            ['code' => 'R11', 'name' => 'Rescue 11', 'class' => 'rescue', 'designations' => ['R11'], 'roster_label' => 'Rescue 11'],
        ],
        2 => [
            ['code' => 'E2', 'name' => 'Engine 2', 'class' => 'combat', 'designations' => ['E2'], 'roster_label' => 'Engine 2'],
            ['code' => 'R2', 'name' => 'Rescue 2', 'class' => 'rescue', 'designations' => ['R2'], 'roster_label' => 'Rescue 2'],
            ['code' => 'R22', 'name' => 'Rescue 22', 'class' => 'rescue', 'designations' => ['R22'], 'roster_label' => 'Rescue 22'],
            ['code' => 'CPT5', 'name' => 'Captain 5', 'class' => 'by_call', 'designations' => ['CPT5'], 'roster_label' => 'Captain 5'],
            ['code' => '300', 'name' => '300', 'class' => 'by_call', 'designations' => ['300'], 'roster_label' => 'Division Chief 300'],
        ],
        3 => [
            ['code' => 'L3', 'name' => 'Ladder 3', 'class' => 'combat', 'designations' => ['L3'], 'roster_label' => 'Ladder 3'],
            ['code' => 'E3', 'name' => 'Engine 3', 'class' => 'combat', 'designations' => ['E3'], 'roster_label' => 'Engine 3'],
            ['code' => 'R3', 'name' => 'Rescue 3', 'class' => 'rescue', 'designations' => ['R3'], 'roster_label' => 'Rescue 3'],
        ],
        4 => [
            ['code' => 'E4', 'name' => 'Engine 4', 'class' => 'combat', 'designations' => ['E4'], 'roster_label' => 'Engine 4'],
            ['code' => 'R4', 'name' => 'Rescue 4', 'class' => 'rescue', 'designations' => ['R4'], 'roster_label' => 'Rescue 4'],
            ['code' => 'R44', 'name' => 'Rescue 44', 'class' => 'rescue', 'designations' => ['R44'], 'roster_label' => 'Rescue 44'],
        ],
        6 => [
            ['code' => 'FB6', 'name' => 'Fireboat 6', 'class' => 'by_call', 'designations' => ['FB6'], 'roster_label' => 'Fire Boat 6'],
        ],
    ],
];
