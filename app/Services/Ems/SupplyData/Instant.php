<?php

declare(strict_types=1);

namespace App\Services\Ems\SupplyData;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * An instant bound into SQL so that the answer never depends on the database session's time zone.
 *
 * PostgreSQL: the value is an ISO-8601 string with an explicit `+00:00` offset and the placeholder is
 * `CAST(? AS timestamptz)`, so it is compared as the exact instant whatever `TimeZone` the session uses.
 * SQLite stores datetimes as bare UTC text (`Y-m-d H:i:s`), so the same text is bound there. Every instant is
 * converted to UTC first; boundaries are Eastern midnights computed in PHP, which keeps daylight-saving days right.
 */
final class Instant
{
    private const SQLITE_FORMAT = 'Y-m-d H:i:s';

    private const ISO_UTC = 'Y-m-d\TH:i:s\+00:00';

    public static function bind(CarbonImmutable $instant): string
    {
        return $instant->utc()->format(self::isPostgres() ? self::ISO_UTC : self::SQLITE_FORMAT);
    }

    /** The placeholder to write in SQL text for a bound instant. */
    public static function placeholder(): string
    {
        return self::isPostgres() ? 'CAST(? AS timestamptz)' : '?';
    }

    /** `$column $operator <instant>` as a raw where on a query builder. */
    public static function where(Builder $query, string $column, string $operator, CarbonImmutable $instant): Builder
    {
        return $query->whereRaw("{$column} {$operator} ".self::placeholder(), [self::bind($instant)]);
    }

    private static function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
