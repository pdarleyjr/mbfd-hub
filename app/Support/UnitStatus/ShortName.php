<?php

declare(strict_types=1);

namespace App\Support\UnitStatus;

/**
 * "Lt. Benson": rank abbreviation plus surname. The surname is the text before the comma of TeleStaff's "Last, First"
 * name, so "Yacum Jr, David (cert/cert)" gives "Yacum Jr". Only the surname is taken from it; nothing else of the raw
 * name (first names, certification text) is ever returned.
 */
final class ShortName
{
    private const SUFFIXES = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv'];

    public static function from(?string $rank, string $rawName, string $displayName): string
    {
        $surname = self::surname($rawName, $displayName);
        $abbreviation = RankAbbreviator::abbreviate($rank);

        return $abbreviation === null ? $surname : trim($abbreviation.' '.$surname);
    }

    public static function surname(string $rawName, string $displayName): string
    {
        $raw = self::beforeComma($rawName);
        if ($raw !== null) {
            // The display name carries the casing members read; use it when it names the same surname.
            $shown = self::beforeComma($displayName);

            return $shown !== null && strcasecmp($shown, $raw) === 0 ? $shown : $raw;
        }

        return self::lastTokens($displayName);
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
