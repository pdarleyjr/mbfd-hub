<?php

declare(strict_types=1);

namespace App\Services\DailyRoster\Parsing;

final readonly class TeleStaffRosterSeat
{
    /** @param  list<TeleStaffRosterEntry>  $entries */
    public function __construct(
        public int $order,
        public int $sourceRow,
        public string $rawRank,
        public array $entries,
    ) {}
}
