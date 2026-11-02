<?php

declare(strict_types=1);

namespace App\Support\Fleet;

/**
 * The comparison form of an issue's compartment or item name: case and spacing never make a second
 * issue for the same thing ("Radio", "radio ", "RADIO" are one item). Exact otherwise: no stemming,
 * no punctuation folding, no similarity, so two different things are never joined by a guess.
 */
final class DefectNameKey
{
    public static function normalize(mixed $name): string
    {
        if (! is_scalar($name)) {
            return '';
        }

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $name)));
    }

    public static function same(mixed $a, mixed $b): bool
    {
        $left = self::normalize($a);

        return $left !== '' && $left === self::normalize($b);
    }

    /** @param  iterable<mixed>  $names */
    public static function contains(iterable $names, mixed $name): bool
    {
        $needle = self::normalize($name);
        if ($needle === '') {
            return false;
        }
        foreach ($names as $candidate) {
            if (self::normalize($candidate) === $needle) {
                return true;
            }
        }

        return false;
    }
}
