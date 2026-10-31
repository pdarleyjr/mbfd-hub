<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterSnapshot;
use Carbon\CarbonImmutable;

final readonly class RosterView
{
    /** @param  list<RosterSectionView>  $sections */
    public function __construct(
        public RosterSnapshot $snapshot,
        public array $sections,
        public ?CarbonImmutable $asOf,
        public ?CarbonImmutable $updatedAt = null,
        public ?CarbonImmutable $changedAt = null,
    ) {}
}
