<?php

declare(strict_types=1);

namespace App\Support\Fleet;

/**
 * Comparison keys for the identity fields an apparatus row shares with its master
 * vehicle record. Keys are only ever used to compare; stored values keep their
 * original spelling.
 */
final class VehicleIdentity
{
    /**
     * Vehicle number key: trimmed, internal whitespace collapsed, upper case. Zeros and
     * dashes are never touched, so "002-16" and "2-16" stay different vehicles.
     */
    public static function numberKey(mixed $value): ?string
    {
        $clean = VehicleNumber::normalize($value);

        return $clean === null ? null : mb_strtoupper($clean);
    }

    /** VIN key: spaces and dashes removed, upper case. Null when nothing is left. */
    public static function vinKey(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $key = preg_replace('/[\s\-]+/u', '', mb_strtoupper((string) $value)) ?? '';

        return $key === '' ? null : $key;
    }

    /** Trimmed text with internal whitespace collapsed; null when blank. */
    public static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $clean === '' ? null : $clean;
    }

    /**
     * A usable four digit model year, or null. The master stores years as text and the
     * apparatus as an integer, so both sides are compared by their digits.
     */
    public static function year(mixed $value): ?int
    {
        $digits = preg_replace('/\D/', '', (string) (self::text($value) ?? ''));

        return is_string($digits) && strlen($digits) === 4 ? (int) $digits : null;
    }
}
