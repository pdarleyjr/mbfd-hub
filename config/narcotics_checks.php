<?php

/*
|--------------------------------------------------------------------------
| Narcotics check (member photo -> Captain 5 approval and archive)
|--------------------------------------------------------------------------
| Contract: docs/narcotics-check.md. A member sends one photo (and an optional
| note) from Employee Portal > Forms; Captain 5 approves and archives it or
| rejects it with an optional explanation. Nothing here claims regulatory
| compliance: the retention value is a local setting that the department sets
| from its own policy, and it is off (photos are kept) until it does.
*/

return [
    // Kill switch for the member card and every endpoint. Rows and photos are never touched by it.
    'enabled' => (bool) env('NARCOTICS_CHECKS_ENABLED', true),

    'image' => [
        // What the server accepts. The browser sends one already-resized, metadata-free photo (~1-3 MB);
        // the larger ceiling covers a phone's original when the browser cannot process it.
        'max_upload_kb' => (int) env('NARCOTICS_CHECK_MAX_UPLOAD_KB', 15360),
        // The stored "full" size (longest edge). The photo is re-encoded here, so all metadata is dropped.
        'full_edge' => 3200,
        'preview_edge' => 1280,
        'thumb_edge' => 360,
        // Too small to read a label or a count: refuse it so the member retakes it.
        'min_edge' => 480,
        // Decompression-bomb guard, checked from the header before any pixel is decoded.
        'max_source_pixels' => 40_000_000,
        'webp_quality' => 86,
        'jpeg_quality' => 88,
    ],

    'note_max_length' => 500,
    'rejection_note_max_length' => 500,

    // A member cannot have more than this many checks waiting for Captain 5 at once.
    'max_pending_per_member' => (int) env('NARCOTICS_CHECK_MAX_PENDING', 10),

    // Days to keep the PHOTO FILES of a decided check (the record, decision and audit rows are always kept).
    // 0 = keep. Set it from department records policy; `narcotics-checks:prune-images` honours it.
    'retention_days' => (int) env('NARCOTICS_CHECK_IMAGE_RETENTION_DAYS', 0),
];
