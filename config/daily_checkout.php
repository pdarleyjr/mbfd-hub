<?php

declare(strict_types=1);

return [
    // Values exceeding these per-inspection increases require reconciliation.
    // Fleet may set tighter bounds; the submitted evidence is never discarded.
    'maximum_miles_increase' => (int) env('DAILY_CHECKOUT_MAXIMUM_MILES_INCREASE', 500),
    'maximum_engine_hours_increase' => (float) env('DAILY_CHECKOUT_MAXIMUM_ENGINE_HOURS_INCREASE', 36),
];
