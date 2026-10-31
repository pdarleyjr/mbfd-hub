<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The 24 hours a station page counts vehicle inspections against: 7:00 AM to 7:00 AM Eastern. This starts before the
 * actual shift change because some members complete their checkouts early. Both the Overview and the Apparatus tab
 * count against it; department-wide readiness views keep the midnight-based day in DailyCheckoutComplianceService.
 */
final readonly class StationShiftWindow
{
    public const START_HOUR = 7;

    /** Frontline units still without an inspection are called out from this hour on. */
    public const ALERT_HOUR = 12;

    private function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public CarbonImmutable $alertFrom,
    ) {}

    public static function containing(CarbonImmutable $moment): self
    {
        $local = $moment->setTimezone(HubTimezone::NAME);
        $start = $local->startOfDay()->setHour(self::START_HOUR);
        if ($local->lessThan($start)) {
            $start = $start->subDay();
        }

        return new self($start, $start->addDay(), $start->setHour(self::ALERT_HOUR));
    }

    public function alertIsDue(CarbonImmutable $moment): bool
    {
        return $moment->greaterThanOrEqualTo($this->alertFrom) && $moment->lessThan($this->end);
    }
}
