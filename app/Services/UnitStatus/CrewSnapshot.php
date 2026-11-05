<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use Carbon\CarbonImmutable;

/** The roster facts the page needs, read once per request. */
final readonly class CrewSnapshot
{
    /** @param  array<string, UnitCrew>  $byUnit  unit code => crew (all 16 units present) */
    public function __construct(
        public string $state,
        public string $dutyDate,
        public ?string $shift,
        public ?CarbonImmutable $asOf,
        public bool $hasNames,
        public array $byUnit,
    ) {}

    public function isCurrent(): bool
    {
        return $this->state === 'current';
    }

    public function crew(string $unitCode): UnitCrew
    {
        return $this->byUnit[$unitCode] ?? UnitCrew::notListed();
    }
}
