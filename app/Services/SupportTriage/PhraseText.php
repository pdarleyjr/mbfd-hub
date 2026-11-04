<?php

declare(strict_types=1);

namespace App\Services\SupportTriage;

use App\Services\AiAutomation\TermMatcher;

/**
 * Text handling shared by the equipment classifier and the rule service: one normalized, plural-insensitive
 * form for item names and free text, so "Backboards", "backboard" and "Back-board (adult)" meet in the same place.
 *
 * Unit and place names ("Engine 2", "Ladder 3", "Fire Station 4") are removed from free text first, exactly as
 * TermMatcher does for the Playbooks word lists, so a truck's name never reads as a safety word.
 */
final class PhraseText
{
    // Mirrors TermMatcher::DESIGNATORS (private there); keep the two in step.
    private const DESIGNATORS = [
        '/(?<![\p{L}\p{N}])fire\s+(?:station|stations|rescue|department|dept|shop|prevention|marshal|watch)(?:\s*-?\s*\d+[a-z]?)?(?![\p{L}\p{N}])/u',
        '/(?<![\p{L}\p{N}])(?:engine|ladder|rescue|squad|truck|tower|fireboat|fire boat|marine|battalion|station|unit|e|l|r|bc)\s*-?\s*\d+[a-z]?(?![\p{L}\p{N}])/u',
    ];

    /** Free text ready for phrase matching: normalized, unit and place names removed. */
    public static function prepare(?string $text): string
    {
        $text = TermMatcher::normalize($text);
        foreach (self::DESIGNATORS as $pattern) {
            $text = (string) preg_replace($pattern, ' ', $text);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The comparison form of an item name or rule phrase: parenthetical text removed, normalized, every word
     * folded to its singular. A trailing `*` is a word prefix marker and is not part of the key.
     */
    public static function key(?string $name): string
    {
        $name = (string) preg_replace('/\([^)]*\)/u', ' ', (string) $name);
        $name = rtrim(trim($name), '*');

        return implode(' ', self::fold(self::words(TermMatcher::normalize($name))));
    }

    /** The same fold applied to prepared free text. */
    public static function foldText(string $prepared): string
    {
        return implode(' ', self::fold(self::words($prepared)));
    }

    /** @return list<string> */
    public static function words(string $normalized): array
    {
        return $normalized === '' ? [] : array_values(array_filter(explode(' ', $normalized), static fn (string $word): bool => $word !== ''));
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    public static function fold(array $words): array
    {
        return array_map(self::singular(...), $words);
    }

    public static function singular(string $word): string
    {
        if (mb_strlen($word) <= 3) {
            return $word;
        }
        if (str_ends_with($word, 'ies') && mb_strlen($word) > 4) {
            return mb_substr($word, 0, -3).'y';
        }
        if (str_ends_with($word, 's') && ! str_ends_with($word, 'ss') && ! str_ends_with($word, 'us') && ! str_ends_with($word, 'is')) {
            $word = mb_substr($word, 0, -1);
        }
        // "axe"/"axes" and "box"/"boxes" meet at the same stem.
        if (mb_strlen($word) > 3 && preg_match('/(?:s|x|z|ch|sh)e$/u', $word) === 1) {
            $word = mb_substr($word, 0, -1);
        }

        return $word;
    }

    /** Whether a rule phrase is a word-prefix pattern ("battery*"). */
    public static function isPattern(string $phrase): bool
    {
        return str_ends_with(trim($phrase), '*');
    }
}
