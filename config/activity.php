<?php

declare(strict_types=1);

/**
 * My activity (docs/unified-activity-and-ownership.md section 5). Presentation only: nothing here changes
 * a record, and an open item stays on the card for as long as its record is open.
 */
return [
    'member' => [
        // Compact card on the Employee dashboard.
        'card_limit' => (int) env('ACTIVITY_CARD_LIMIT', 10),
        // A routine item (a clean inspection) leaves the card this many hours after it happened.
        'routine_hours' => (int) env('ACTIVITY_ROUTINE_HOURS', 48),
        // A closed item leaves the card this many hours after it was last updated.
        'closed_hours' => (int) env('ACTIVITY_CLOSED_HOURS', 72),

        // How far back each source looks for the card (open items are always considered), and its row cap.
        'card_window_days' => 90,
        'card_source_limit' => 40,

        // "View all activity" page.
        'page_window_days' => 180,
        'page_source_limit' => 150,
        'page_size' => 25,

        // Resolving one item from a link: a longer look-back so an older bookmark still opens.
        'lookup_window_days' => 365,
        'lookup_source_limit' => 250,

        'excerpt_length' => 160,

        // Updates older than this never count as unread (the release day), so members are not greeted by a
        // backlog of replies they already read on the issue pages.
        'unread_since' => env('ACTIVITY_UNREAD_SINCE', '2026-11-04T00:00:00Z'),
    ],
];
