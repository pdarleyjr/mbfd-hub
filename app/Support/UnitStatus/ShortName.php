<?php

declare(strict_types=1);

namespace App\Support\UnitStatus;

/**
 * "Lt. Benson": rank abbreviation plus surname, from the roster's display name only. The raw TeleStaff name carries
 * certification text that may itself contain commas, so it is never read here.
 */
final class ShortName
{
    private const SUFFIXES = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv'];

    public static function from(?string $rank, string $displayName): string
    {
        $surname = self::surname($displayName);
        $abbreviation = RankAbbreviator::abbreviate($rank);

        return $abbreviation === null ? $surname : trim($abbreviation.' '.$surname);
    }

    /** From the display name alone: "Last, First" gives the part before the comma, "First Last" the last word. */
    public static function surname(string $displayName): string
    {
        return self::beforeComma($displayName) ?? self::lastTokens($displayName);
    }

    private static function beforeComma(string $name): ?string
    {
        if (! str_contains($name, ',')) {
            return null;
        }
        $surname = trim(explode(',', $name, 2)[0]);

        return $surname === '' ? null : $surname;
    }

    /** No comma: the last word of the name, with a generational suffix kept together with the word before it. */
    private static function lastTokens(string $name): string
    {
        $tokens = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return '';
        }
        $last = $tokens[array_key_last($tokens)];
        if (count($tokens) >= 2 && in_array(strtolower($last), self::SUFFIXES, true)) {
            return $tokens[count($tokens) - 2].' '.$last;
        }

        return $last;
    }
}
