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

    /**
     * @param  string  $version  Changes only when what a member sees would change, so a page refetches only then.
     * @param  string  $etag  Changes when anything moved, including the times on an export with no changes.
     * @param  CarbonImmutable|null  $asOf  When TeleStaff generated the newest export that confirmed this roster.
     * @param  CarbonImmutable|null  $checkedAt  When the Hub last accepted an export for this date.
     * @param  CarbonImmutable|null  $changedAt  When the people on the roster last changed.
     */
    public function __construct(
        public string $state,
        public string $dutyDate,
        public ?CarbonImmutable $asOf,
        public string $version,
        public string $etag = '',
        public ?int $snapshotId = null,
        public ?CarbonImmutable $checkedAt = null,
        public ?CarbonImmutable $changedAt = null,
    ) {}
}
