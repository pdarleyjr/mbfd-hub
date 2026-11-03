<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

/**
 * Who the published roster puts on one unit right now. Names and ranks only: employee ids, codes, categories,
 * certifications and review reasons never leave CrewProjection.
 */
final readonly class UnitCrew
{
    /**
     * @param  list<array{rank: ?string, name: string}>  $onTruck
     * @param  list<array{rank: ?string, name: string, from: ?string, through: ?string}>  $later
     */
    public function __construct(
        public bool $listed,
        public array $onTruck = [],
        public array $later = [],
        public int $vacancies = 0,
    ) {}

    public static function notListed(): self
    {
        return new self(false);
    }

    public function hasWorkingCrew(): bool
    {
        return $this->onTruck !== [];
    }
}
