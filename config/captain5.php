<?php

/*
|--------------------------------------------------------------------------
| Captain 5 EMS Operations Center
|--------------------------------------------------------------------------
| Contract: docs/captain5-ems-operations-center.md. Every value here was
| verified against production data on 2026-10-23 (read-only) and is
| documented there. Change them only with the same evidence.
*/

return [
    // Exact `employee_bid_assignments.unit_label` values (trimmed, case-sensitive).
    'bid_unit_labels' => [
        'captain5' => 'Captain 5',
        'ems_captain' => 'EMS',
    ],

    // Canonical `employees.rank` strings that may open the workspace (normalized compare).
    'officer_ranks' => ['Lieutenant', 'Captain', 'Division Chief', 'Deputy Fire Chief', 'Fire Chief'],

    'timezone' => \App\Support\HubTimezone::NAME,

    // Duty day boundary (TeleStaff daily print: 08:00 to 08:00).
    'duty_day_start_hour' => (int) env('CAPTAIN5_DUTY_DAY_START_HOUR', 8),

    // A/B/C 24-hour rotation. Checked against production inspection `shift` values.
    'shift_rotation' => [
        'enabled' => (bool) env('CAPTAIN5_SHIFT_ROTATION_ENABLED', true),
        'anchor_date' => env('CAPTAIN5_SHIFT_ANCHOR_DATE', '2026-03-09'),
        'anchor_shift' => env('CAPTAIN5_SHIFT_ANCHOR_SHIFT', 'A'),
        'order' => ['A', 'B', 'C'],
    ],

    // Workspace history window (closed issues and events) in days.
    'history_days' => 90,

    // A later checkout that finds a routine reported item present closes the issue (event resolved_by_checkout).
    // Anything significant, blocking, damaged, ticketed or a stretcher report still waits for Verify correction.
    'auto_close_on_checkout' => (bool) env('CAPTAIN5_AUTO_CLOSE_ON_CHECKOUT', true),

    // Kill switch for the Captain 5 communications channel (rollback aid).
    'communications_enabled' => (bool) env('CAPTAIN5_COMMUNICATIONS_ENABLED', true),
];
