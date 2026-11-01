<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use Carbon\CarbonImmutable;

/** Where a member works for one stretch of what is left of the shift. */
final readonly class RosterMovePlacement
{
    public function __construct(
        public string $unit,
        public string $rank,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}

    /** The same seat at the same times, whatever status code or certifications the row carries. */
    public function key(): string
    {
        return $this->seat().'|'.$this->startsAt->getTimestamp().'|'.$this->endsAt->getTimestamp();
    }

    public function seat(): string
    {
        return $this->unit.'|'.$this->rank;
    }
}
