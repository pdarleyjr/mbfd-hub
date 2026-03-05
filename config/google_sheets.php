<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google Sheets — Apparatus Sync Configuration
    |--------------------------------------------------------------------------
    */

    // Feature flag — set GOOGLE_SHEETS_APPARATUS_SYNC_ENABLED=true to enable
    'apparatus_sync_enabled' => env('GOOGLE_SHEETS_APPARATUS_SYNC_ENABLED', false),

    // Path to the service account JSON key file (must NOT be in the repo)
    'service_account_json' => env('GOOGLE_SERVICE_ACCOUNT_JSON_PATH', '/run/secrets/google_service_account.json'),

    // Target spreadsheet
    'spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID', '1u9MYILAkfEaMfNZnBujvB1J0J33Ha8TybWCd_mVMJC4'),

    // Expected tab title — fail closed if mismatch
    'tab_title' => env('GOOGLE_SHEETS_TAB_TITLE', 'Equipment Maintenance'),

    // Expected sheetId (gid) — fail closed if mismatch
    'tab_sheet_id' => (int) env('GOOGLE_SHEETS_TAB_SHEET_ID', 1714038258),

    // Retry settings for transient errors
    'retry_max_attempts' => (int) env('GOOGLE_SHEETS_RETRY_MAX', 5),
    'retry_base_delay_ms' => (int) env('GOOGLE_SHEETS_RETRY_BASE_MS', 500),
];
