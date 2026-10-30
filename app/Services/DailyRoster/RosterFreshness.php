<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use Carbon\CarbonImmutable;

final readonly class RosterFreshness
{
    public const CURRENT = 'current';

    public const AWAITING = 'awaiting';

    public const DELAYED = 'delayed';

    public const NEEDS_REVIEW = 'needs_review';

    public const NONE = 'none';

    public function __construct(
        public string $state,
        public string $dutyDate,
        public ?CarbonImmutable $asOf,
        public string $version,
    ) {}
}
