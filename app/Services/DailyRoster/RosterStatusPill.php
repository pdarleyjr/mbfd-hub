<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

/** The status of one roster row as a member reads it. */
final readonly class RosterStatusPill
{
    public function __construct(
        public string $label,
        public string $filterLabel,
        public string $tone,
        public string $meaning,
        public string $title,
        public string $screenReaderText,
    ) {}
}
