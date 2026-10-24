<?php

declare(strict_types=1);

return [
    /*
     * Promotional list types. "other" needs a free-text label on the list.
     * Add a department promotional track here; no migration is needed.
     */
    'types' => [
        'lieutenant' => 'Lieutenant',
        'captain' => 'Captain',
        'driver_engineer' => 'Driver Engineer',
        'division_chief' => 'Division Chief',
        'other' => 'Other',
    ],

    'list_statuses' => [
        'draft' => 'Draft',
        'active' => 'Active',
        'expired' => 'Expired',
        'superseded' => 'Superseded',
    ],

    'entry_statuses' => [
        'eligible' => 'Eligible',
        'promoted' => 'Promoted',
        'removed' => 'Removed',
        'declined' => 'Declined',
        'expired' => 'Expired',
    ],

    /*
     * Score columns exactly as printed on the official results. Values are
     * stored as text ("82.00", "P", "-") and never recalculated.
     */
    'score_columns' => [
        'written' => 'Written',
        'oral' => 'Oral',
        'composite' => 'Composite',
        'seniority' => 'Seniority',
        'education' => 'Education',
        'max_promotional' => 'Max promotional',
        'veteran' => 'Veteran',
        'total' => 'Total',
    ],

    'import' => [
        'max_rows' => 500,
        'max_bytes' => 1048576,
    ],
];
