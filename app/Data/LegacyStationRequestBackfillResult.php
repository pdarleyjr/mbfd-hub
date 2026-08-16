<?php

declare(strict_types=1);

namespace App\Data;

final readonly class LegacyStationRequestBackfillResult
{
    public function __construct(
        public int $created,
        public int $skipped,
    ) {}
}
