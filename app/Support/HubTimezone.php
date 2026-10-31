<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** The fixed civil timezone for Hub operations; persisted instants remain UTC. */
final class HubTimezone
{
    public const NAME = 'America/New_York';

    /** A moment in the words a member reads, for messages that may arrive after it happened. */
    public static function dateTime(DateTimeInterface $value): string
    {
        return CarbonImmutable::instance($value)->setTimezone(self::NAME)->format('F j, Y \a\t g:i A T');
    }

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
