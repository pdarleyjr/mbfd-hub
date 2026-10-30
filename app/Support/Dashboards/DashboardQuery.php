<?php

declare(strict_types=1);

namespace App\Support\Dashboards;

use App\Support\OperationalDisplayWindow;

/** Filters shared by every source for one dashboard render. */
final readonly class DashboardQuery
{
    /** @param  list<string>  $tags  restrict to rows carrying any of these tags (empty = all) */
    public function __construct(
        public OperationalDisplayWindow $window,
        public bool $displayMode = false,
        public array $tags = [],
        public ?string $stage = null,
        public ?string $severity = null,
    ) {}

    public static function now(bool $displayMode = false): self
    {
        return new self(OperationalDisplayWindow::forNow(), $displayMode);
    }
}
