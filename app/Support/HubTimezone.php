<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** The fixed civil timezone for Hub operations; persisted instants remain UTC. */
final class HubTimezone
{
    public const NAME = 'America/New_York';

    /** The Eastern calendar date at UTC midnight, matching Eloquent date-cast comparisons. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(self::NAME)->toDateString(), 'UTC')->startOfDay();
    }

    /** Reinterpret a date-only value's written date at Eastern midnight, without shifting its day. */
    public static function calendarDate(DateTimeInterface $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value->format('Y-m-d'), self::NAME)->startOfDay();
    }
}
