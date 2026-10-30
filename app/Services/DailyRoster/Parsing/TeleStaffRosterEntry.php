<?php

declare(strict_types=1);

namespace App\Services\DailyRoster\Parsing;

final readonly class TeleStaffRosterEntry
{
    /**
     * @param  'employee'|'vacancy_open'|'vacancy_suppressed'  $kind
     * @param  list<string>  $certifications
     * @param  'working'|'not_working'|'annotation'|'unknown'|'vacant'  $availability
     * @param  list<string>  $reviewReasons
     */
    public function __construct(
        public int $sourceRow,
        public bool $isContinuation,
        public string $kind,
        public ?string $employeeId,
        public ?string $rawName,
        public ?string $displayName,
        public array $certifications,
        public ?string $rawCode,
        public ?string $baseCode,
        public ?string $prefix,
        public string $availability,
        public ?string $category,
        public ?string $displayLabel,
        public string $rawFrom,
        public string $rawThrough,
        public float $reportedHours,
        public ?string $startAt,
        public ?string $endAt,
        public array $reviewReasons,
    ) {}
}
