<?php

declare(strict_types=1);

namespace App\Services\DailyRoster\Parsing;

/** Counts for a division block outside the published roster. No member data. */
final readonly class TeleStaffRosterBlockSummary
{
    public function __construct(
        public int $titleRow,
        public string $division,
        public string $shiftLabel,
        public int $sectionCount,
        public int $entryCount,
    ) {}
}
