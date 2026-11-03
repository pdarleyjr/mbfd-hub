<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use Carbon\CarbonImmutable;

/** The fixed facts one sync run applies rows against. */
final readonly class SyncContext
{
    public function __construct(
        public CarbonImmutable $now,
        public CarbonImmutable $trackingStartsAt,
        public CarbonImmutable $firstSyncedAt,
    ) {}
}
