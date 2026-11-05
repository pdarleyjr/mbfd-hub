<?php

declare(strict_types=1);

namespace App\Support\UnitStatus;

use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Instants are ISO-8601 UTC in the payload; labels are Eastern with an "ET" suffix. */
final class UnitStatusTime
{
    public static function iso(?DateTimeInterface $moment): ?string
    {
        return $moment === null ? null : CarbonImmutable::instance($moment)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** "4:58 PM ET" */
    public static function time(?DateTimeInterface $moment): ?string
    {
        return self::format($moment, 'g:i A');
    }

    /** "Nov 2, 4:58 PM ET" */
    public static function dateTime(?DateTimeInterface $moment): ?string
    {
        return self::format($moment, 'M j, g:i A');
    }

    /** "19:00", the 24-hour clock used for relief times. */
    public static function clock(?DateTimeInterface $moment): ?string
    {
        return $moment === null ? null : CarbonImmutable::instance($moment)->setTimezone(HubTimezone::NAME)->format('H:i');
    }

    private static function format(?DateTimeInterface $moment, string $format): ?string
    {
        return $moment === null
            ? null
            : CarbonImmutable::instance($moment)->setTimezone(HubTimezone::NAME)->format($format).' ET';
    }
}
