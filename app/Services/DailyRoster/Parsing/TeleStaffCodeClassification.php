<?php

declare(strict_types=1);

namespace App\Services\DailyRoster\Parsing;

final readonly class TeleStaffCodeClassification
{
    /**
     * @param  string  $rawCode  The code exactly as printed
     * @param  string  $baseCode  The code without a leading `.` or U+2927
     * @param  string|null  $prefix  What was stripped from the code, or null
     * @param  'working'|'not_working'|'annotation'|'unknown'  $availability
     */
    public function __construct(
        public string $rawCode,
        public string $baseCode,
        public ?string $prefix,
        public string $availability,
        public string $category,
        public string $displayLabel,
    ) {}
}
