<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterAssignment;
use App\Models\DailyRoster\RosterSection;

final readonly class RosterSectionView
{
    /** @param  list<RosterAssignment>  $assignments */
    public function __construct(
        public RosterSection $section,
        public array $assignments,
    ) {}
}
