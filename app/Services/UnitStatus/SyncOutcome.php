<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

/** What one `unit-status:sync` run did, for the command line and for tests. */
final readonly class SyncOutcome
{
    public const OK = 'ok';

    public const SKIPPED = 'skipped';

    public const FEED_FAILED = 'feed_failed';

    public const HUB_ERROR = 'hub_error';

    public function __construct(
        public string $status,
        public string $message,
        public int $rowsApplied = 0,
        public int $rowsSkipped = 0,
        public int $pages = 0,
    ) {}
}
