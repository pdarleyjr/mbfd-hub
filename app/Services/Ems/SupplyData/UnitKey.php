<?php

declare(strict_types=1);

namespace App\Services\Ems\SupplyData;

use App\Models\Ems\InventoryRequest;
use Illuminate\Database\Query\Builder;

/**
 * The unit a request was for, as recorded on the request when it was submitted:
 *   unit:{apparatus_id}  the truck row chosen on the form
 *   detail               Detail / Special Event (no truck)
 *   unassigned           a unit request whose truck row has since been deleted (apparatus_id is set null)
 *
 * The key follows the request, never today's truck assignment: the apparatus table holds physical trucks and a
 * changeout moves the unit's identity between rows, so a request is always attributed to the row it named.
 */
final class UnitKey
{
    public const DETAIL = 'detail';

    public const UNASSIGNED = 'unassigned';

    public static function of(?int $apparatusId, ?string $targetKind): string
    {
        return match (true) {
            $apparatusId !== null => 'unit:'.$apparatusId,
            $targetKind === InventoryRequest::TARGET_DETAIL => self::DETAIL,
            default => self::UNASSIGNED,
        };
    }

    public static function apparatusId(string $key): ?int
    {
        return str_starts_with($key, 'unit:') ? (int) substr($key, 5) : null;
    }

    public static function kind(string $key): string
    {
        return str_starts_with($key, 'unit:') ? 'unit' : $key;
    }

    /** Restrict a query that has the request table under $alias to one unit key. */
    public static function restrict(Builder $query, string $key, string $alias = 'r'): void
    {
        $apparatus = self::apparatusId($key);
        if ($apparatus !== null) {
            $query->where("{$alias}.apparatus_id", $apparatus);

            return;
        }
        $query->whereNull("{$alias}.apparatus_id");
        $key === self::DETAIL
            ? $query->where("{$alias}.target_kind", InventoryRequest::TARGET_DETAIL)
            : $query->where("{$alias}.target_kind", '<>', InventoryRequest::TARGET_DETAIL);
    }

    /** SQL that gives each unit a distinct whole number, for COUNT(DISTINCT ...). */
    public static function ordinalSql(string $apparatusColumn, string $kindColumn): string
    {
        return "CASE WHEN {$apparatusColumn} IS NOT NULL THEN {$apparatusColumn} WHEN {$kindColumn} = 'detail_event' THEN -1 ELSE -2 END";
    }
}
