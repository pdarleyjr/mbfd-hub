<?php

declare(strict_types=1);

namespace App\Services\Ems\Import;

/**
 * The rules for turning one Count sheet cell into a starting number (docs/ems/supply-room-workbook-exceptions.md).
 * Pure functions so the same answer is easy to check by hand and in tests. The extraction script
 * (scripts/ems/extract_supply_workbook.py) applies the same rules to write its report; the importer recomputes
 * them here from the item's CURRENT package data, so a person's change to a package size is always respected.
 *
 *  - blank is never zero;
 *  - an integer cell is a bare number; "N bx", "Nbx", "N bx (50)" are N in that unit;
 *  - a unit is converted only when it equals the item's package unit and the units per package are known;
 *  - a bare number is units when the order level is in units or has no unit, and AMBIGUOUS when the order level is
 *    in a package unit (it could be boxes or units), so no starting number is applied.
 */
final class SupplyWorkbookCounts
{
    public const PACKAGE_UNITS = ['box', 'case', 'pack', 'bag'];

    public const COUNT_UNITS = ['each', 'box', 'case', 'pack', 'bag'];

    /**
     * @param  array<string, mixed>  $count  kind (blank|bare|unit|invalid), value, unit, units_note
     * @param  array<string, mixed>  $threshold  class (each|package|none), unit, text
     * @return array{count_unit: string|null, baseline_base: int|null, exception: string|null, message: string|null}
     */
    public static function resolve(array $count, array $threshold, ?string $packageUnit, ?int $perPackage, bool $controlled): array
    {
        $none = ['count_unit' => null, 'baseline_base' => null, 'exception' => null, 'message' => null];
        if ($controlled) {
            return [...$none, 'exception' => 'controlled_no_baseline', 'message' => 'Controlled items never get a starting count.'];
        }
        $kind = (string) ($count['kind'] ?? 'blank');
        $value = isset($count['value']) ? (int) $count['value'] : null;
        $unit = isset($count['unit']) ? (string) $count['unit'] : null;
        $known = $perPackage !== null && $perPackage > 0;

        if ($kind === 'blank') {
            return $none;
        }
        if ($kind === 'invalid' || $value === null || $value < 0) {
            return [...$none, 'exception' => 'unreadable_quantity', 'message' => 'The quantity cannot be read as a number.'];
        }
        if ($kind === 'bare') {
            if (($threshold['class'] ?? 'none') === 'package') {
                $orderUnit = $threshold['unit'] ?? null;
                $usable = $orderUnit !== null && $orderUnit === $packageUnit && $known ? $orderUnit : 'each';

                return [...$none, 'count_unit' => $usable, 'exception' => 'ambiguous_bare_quantity',
                    'message' => "The sheet shows {$value}, but the order level is written in packages, so it could be packages or single units."];
            }

            return [...$none, 'count_unit' => 'each', 'baseline_base' => $value];
        }
        if ($unit === 'each') {
            return [...$none, 'count_unit' => 'each', 'baseline_base' => $value];
        }
        if ($unit !== null && $unit === $packageUnit && $known) {
            $note = isset($count['units_note']) ? (int) $count['units_note'] : null;
            if ($note !== null && $note !== $perPackage) {
                return [...$none, 'exception' => 'unit_note_mismatch',
                    'message' => "The sheet says {$value} {$unit} ({$note}), but the item is {$perPackage} per {$unit}."];
            }

            return [...$none, 'count_unit' => $unit, 'baseline_base' => $value * $perPackage];
        }

        return [...$none, 'exception' => 'unit_mismatch',
            'message' => "The sheet says {$value} ".($unit ?? 'units').', which does not match how this item is packed.'];
    }

    /**
     * What a person types on Monday: each, or the package unit when that is how the item is counted and ordered.
     * Always satisfies the contract: each, or equal to the package unit with a known size.
     *
     * @param  array<string, mixed>  $threshold
     */
    public static function countUnit(?string $chosen, array $threshold, ?string $packageUnit, ?int $perPackage): string
    {
        $unit = $chosen;
        if ($unit === null && ($threshold['class'] ?? 'none') === 'package') {
            $unit = $threshold['unit'] ?? null;
        }

        return self::valid($unit, $packageUnit, $perPackage) ? (string) $unit : 'each';
    }

    public static function valid(?string $unit, ?string $packageUnit, ?int $perPackage): bool
    {
        return $unit === 'each' || ($unit !== null && in_array($unit, self::PACKAGE_UNITS, true) && $unit === $packageUnit && $perPackage !== null && $perPackage > 0);
    }

    /** The key the count form uses for a typed number in this unit. */
    public static function entryKey(string $unit): string
    {
        return match ($unit) {
            'each' => 'each',
            'case' => 'cases',
            default => 'boxes',
        };
    }
}
