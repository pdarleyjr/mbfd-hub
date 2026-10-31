<?php

/*
 * TeleStaff daily roster automation (docs/operations/telestaff-daily-roster.md).
 *
 * Rollout flags default to the safest state. Sender, subject and filename were read
 * from the first real delivery on 2026-10-30, not assumed.
 */
return [
    // off: no ledger rows. shadow: parse and validate, never publish. publish: full pipeline.
    'mode' => env('DAILY_ROSTER_MODE', 'shadow'),
    // Deletes only the positively identified, verified TeleStaff email and its attachment.
    'cleanup_enabled' => (bool) env('DAILY_ROSTER_CLEANUP_ENABLED', false),
    // The 300 and Captain 5 cards read the published roster for display only.
    'card_display_enabled' => (bool) env('DAILY_ROSTER_CARD_DISPLAY_ENABLED', false),

    'classifier' => [
        'sender' => env('DAILY_ROSTER_SENDER', 'donotreply@miamibeachfl.gov'),
        'sender_domain' => env('DAILY_ROSTER_SENDER_DOMAIN', 'miamibeachfl.gov'),
        'subject' => env('DAILY_ROSTER_SUBJECT', 'Roster Report'),
        'filename' => env('DAILY_ROSTER_FILENAME', 'Dailyroster.xlsx'),
        'authserv_id' => 'mx.cloudflare.net',
    ],

    'workbook' => [
        'sheet' => 'Roster Report',
        'division' => 'Suppression/Rescue',
        'max_bytes' => 2 * 1024 * 1024,
        'max_entries' => 1000,
        'max_total_uncompressed_bytes' => 16 * 1024 * 1024,
        'max_entry_uncompressed_bytes' => 8 * 1024 * 1024,
        'max_entry_ratio' => 200,
        'max_rows' => 5000,
        // Cell elements in the sheet. The real export has about 3,200.
        'max_cells' => 20000,
    ],

    // TeleStaff unit labels that carry command display, matched exactly after trimming.
    'section_roles' => [
        'command_300' => 'Division Chief 300',
        'captain_5' => 'Captain 5',
    ],

    'publication' => [
        'max_days_ahead' => 14,
        'max_days_back' => 7,
        'max_generated_after_received_minutes' => 10,
        'min_section_retention_percent' => 75,
        'min_assignment_percent_of_median' => 50,
        'max_assignment_percent_of_median' => 200,
        'median_window' => 6,
        'max_unknown_code_percent' => 25,
    ],

    'processing' => [
        'lease_seconds' => 300,
        'job_timeout_seconds' => 55,
        'max_attempts' => 5,
        'backoff_minutes' => [1, 5, 15, 60],
        'stuck_dispatch_minutes' => 2,
        'unverified_minutes' => 2,
        'max_cleanup_attempts' => 5,
    ],

    // Local wall clock after which a missing roster for the current duty day counts as late.
    // Used only when no cadence is set below.
    'delayed_after_local' => env('DAILY_ROSTER_DELAYED_AFTER', '10:00'),

    'cadence' => [
        // Minutes between TeleStaff exports. Empty means once a day: nothing is ever called stale, and
        // delayed_after_local decides lateness. Set it when TeleStaff starts sending more often.
        'expected_interval_minutes' => env('DAILY_ROSTER_EXPECTED_INTERVAL_MINUTES') ?: null,
        // How many exports may go missing before members are told and administrators alerted.
        'stale_after_missed' => (int) env('DAILY_ROSTER_STALE_AFTER_MISSED', 3),
    ],

    'live_updates' => [
        // Above this share of seats changed, an open page takes the whole roster instead of spot updates.
        'max_changed_seat_percent' => 50,
    ],

    'retention' => [
        // Finished exports that changed nothing. The record of every real change stays.
        'unchanged_export_days' => (int) env('DAILY_ROSTER_UNCHANGED_EXPORT_DAYS', 30),
        // Versions of a roster that were replaced: the rollback window. What was shown for a date stays.
        'replaced_snapshot_days' => (int) env('DAILY_ROSTER_REPLACED_SNAPSHOT_DAYS', 30),
        // Held or failed mail that a later verified export overtook. Rejected mail is never removed by age.
        'overtaken_email_days' => (int) env('DAILY_ROSTER_OVERTAKEN_EMAIL_DAYS', 14),
        'batch' => 500,
        'max_batches' => 20,
    ],
];
