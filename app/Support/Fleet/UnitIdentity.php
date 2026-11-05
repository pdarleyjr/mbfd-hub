<?php

declare(strict_types=1);

namespace App\Support\Fleet;

/**
 * Comparison keys and conventions for a unit's identity (E1, "E 1", "ENGINE E1", e1). A unit is not a truck: these
 * fields move between truck rows on a swap, while a truck keeps its vehicle number. Keys are only used to compare;
 * stored values keep their spelling.
 */
final class UnitIdentity
{
    /** The identity fields of a unit. VehicleSwapService moves them, plus station and frontline role. */
    public const FIELDS = ['unit_id', 'designation', 'name', 'slug'];

    /** Letters and digits only, upper case: "E 1", "e-1" and "E1" are one key. Empty when nothing is left. */
    public static function key(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            return '';
        }

        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $value));
    }

    /** The first run of digits as a number: 1 for "E 1", "ENGINE E1" and "e-1"; 1033 for "R-1033-2". */
    public static function number(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        return preg_match('/\d+/', (string) $value, $match) === 1 ? (int) $match[0] : null;
    }

    /** The first letter, upper case: E for "E 1" and "Engine 1", F for "Fire Boat 6" and "FB6". */
    public static function letter(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return preg_match('/[A-Za-z]/', $value, $match) === 1 ? strtoupper($match[0]) : null;
    }

    /** A designation that names no unit: "Reserve", "Reserve Engine" and the like are shared by many spare trucks. */
    public static function isSharedLabel(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\s*(reserve|spare)\b/i', $value) === 1;
    }

    /** "E1" becomes "E 1", "R22" becomes "R 22". Anything else is returned as typed. */
    public static function designationFor(string $unitId): string
    {
        $clean = trim($unitId);

        return preg_match('/^([A-Za-z]{1,3})\s*-?\s*(\d{1,3})$/', $clean, $match) === 1
            ? strtoupper($match[1]).' '.$match[2]
            : $clean;
    }

    /** "E1" becomes "e1", "R-1033" becomes "r1033": the slug convention the Daily Checkout links use. */
    public static function slugFor(string $unitId): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $unitId));
    }

    /** "ENGINE E1" from type Engine and unit E1. Without a type, the unit ID. */
    public static function nameFor(string $unitId, ?string $type): string
    {
        $type = trim((string) $type);

        return $type === '' ? trim($unitId) : mb_strtoupper($type).' '.trim($unitId);
    }
}
