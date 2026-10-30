<?php

declare(strict_types=1);

namespace App\Services\DailyRoster\Parsing;

final readonly class TeleStaffRosterSection
{
    /** @param  list<TeleStaffRosterSeat>  $seats */
    public function __construct(
        public int $order,
        public int $sourceRow,
        public string $rawTitle,
        public string $parent,
        public string $unit,
        public array $seats,
    ) {}
}
