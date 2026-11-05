<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use App\Models\Apparatus;

/**
 * The owner-approved front-line units (config/daily_checkout_frontline.php): E1, L1, R1 and the rest. A front-line unit
 * is never Out of Service. Its truck can be: when that happens a different truck is put on the unit with a swap.
 */
final class FrontlineUnits
{
    /** @return list<array{unit_id: string, station: int, template: string}> */
    public static function policy(): array
    {
        $policy = config('daily_checkout_frontline', []);

        return is_array($policy) ? array_values($policy) : [];
    }

    /** @return list<string> */
    public static function unitIds(): array
    {
        return array_map(static fn (array $entry): string => (string) $entry['unit_id'], self::policy());
    }

    /**
     * The keys a unit is known by in unit IDs, designations and names. Fire Boat 6 is also written out in full.
     *
     * @return list<string>
     */
    public static function aliasKeys(string $unitId): array
    {
        $key = UnitIdentity::key($unitId);

        return $key === 'FB6' ? ['FB6', 'FIREBOAT6'] : [$key];
    }

    /** The approved unit this truck runs as right now, by its unit ID, or null for a spare or support truck. */
    public static function carriedBy(Apparatus $truck): ?string
    {
        $key = UnitIdentity::key($truck->getAttribute('unit_id'));
        if ($key === '') {
            return null;
        }
        foreach (self::unitIds() as $unitId) {
            if (UnitIdentity::key($unitId) === $key) {
                return $unitId;
            }
        }

        return null;
    }

    public static function isFrontline(Apparatus $truck): bool
    {
        return self::carriedBy($truck) !== null;
    }
}
