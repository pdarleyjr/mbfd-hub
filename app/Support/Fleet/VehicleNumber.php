<?php

declare(strict_types=1);

namespace App\Support\Fleet;

/**
 * Physical vehicle IDs are short labels such as "2002" or "R-14". Comparison
 * ignores case and surrounding or repeated spaces so a typed ID still matches
 * the fleet record it names.
 */
final class VehicleNumber
{
    /** Letters, digits, and the few separators fleet labels use. */
    public const PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9 .\/#-]{0,31}\z/';

    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $clean === '' ? null : $clean;
    }

    public static function isWellFormed(?string $value): bool
    {
        return $value !== null && preg_match(self::PATTERN, $value) === 1;
    }

    public static function same(mixed $first, mixed $second): bool
    {
        $a = self::normalize($first);
        $b = self::normalize($second);

        return $a !== null && $b !== null && strcasecmp($a, $b) === 0;
    }
}
