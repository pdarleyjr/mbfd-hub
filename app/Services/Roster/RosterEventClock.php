<?php

declare(strict_types=1);

namespace App\Services\Roster;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns roster wall-clock times into ISO 8601 instants with an explicit
 * America/New_York offset. Pure.
 */
final class RosterEventClock
{
    public const TIMEZONE = 'America/New_York';

    /**
     * A through time earlier than the from time ends the next calendar day.
     * Equal times mean 24 hours only when the roster says so; 00:00 to 00:00
     * is TeleStaff's default and carries no time at all.
     *
     * @return array{startAt: string, endAt: string}|null
     */
    public static function window(string $date, ?string $from, ?string $through, ?string $hours = null): ?array
    {
        $start = self::at($date, $from);
        $end = self::at($date, $through);
        if ($start === null || $end === null) {
            return null;
        }

        if ($end == $start) {
            if ($from === '00:00' || (float) $hours !== 24.0) {
                return null;
            }
            $end = self::at($date, $through, 1);
        } elseif ($end < $start) {
            $end = self::at($date, $through, 1);
        }

        return $end === null ? null : [
            'startAt' => $start->format('Y-m-d\TH:i:sP'),
            'endAt' => $end->format('Y-m-d\TH:i:sP'),
        ];
    }

    private static function at(string $date, ?string $time, int $days = 0): ?DateTimeImmutable
    {
        if ($time === null || preg_match('/^(\d{2}):(\d{2})$/', $time, $parts) !== 1) {
            return null;
        }
        [$hour, $minute] = [(int) $parts[1], (int) $parts[2]];
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        $zone = new DateTimeZone(self::TIMEZONE);
        $day = new DateTimeImmutable($date.' 00:00:00', $zone);

        return $day->modify(sprintf('%+d days', $days))->setTime($hour, $minute);
    }
}
