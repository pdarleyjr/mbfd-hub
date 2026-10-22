<?php

declare(strict_types=1);

// Meter decisions are structural (docs/daily-inspection-continuity.md section 2.2):
// a reading below the fleet's authoritative reading is held for review, anything
// else is accepted. There is intentionally no maximum-increase threshold.
return [
    // A meter baseline token older than this is treated as unverified, so a stale form
    // cannot make a lower reading look like it was merely overtaken. Seven days.
    'meter_baseline_max_age_hours' => 168,
];
