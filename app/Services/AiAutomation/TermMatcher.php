<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

/**
 * Case-insensitive whole-word / whole-phrase matching against the Playbooks word lists. Plurals fold
 * (s/es), a trailing "*" makes a term a word prefix, and unit or place names ("Engine 2", "Ladder 3",
 * "Fire Station 2") are removed first so they do not read as safety words.
 */
final class TermMatcher
{
    private const DESIGNATORS = [
        // Place phrases first, so "fire station 4" goes as a whole before "station 4" is taken alone.
        '/(?<![\p{L}\p{N}])fire\s+(?:station|stations|rescue|department|dept|shop|prevention|marshal|watch)(?:\s*-?\s*\d+[a-z]?)?(?![\p{L}\p{N}])/u',
        '/(?<![\p{L}\p{N}])(?:engine|ladder|rescue|squad|truck|tower|fireboat|fire boat|marine|battalion|station|unit|e|l|r|bc)\s*-?\s*\d+[a-z]?(?![\p{L}\p{N}])/u',
    ];

    /**
     * @param  list<string>  $terms
     */
    public static function first(?string $text, array $terms): ?string
    {
        $haystack = self::prepare($text);
        if ($haystack === '') {
            return null;
        }

        foreach ($terms as $term) {
            $prefix = str_ends_with(trim($term), '*');
            $needle = self::normalize(rtrim(trim($term), '*'));
            if ($needle === '') {
                continue;
            }

            $tail = $prefix ? '[\p{L}\p{N}]*' : '(?:s|es)?';
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').$tail.'(?![\p{L}\p{N}])/u';

            if (preg_match($pattern, $haystack) === 1) {
                return $term;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $terms
     */
    public static function any(?string $text, array $terms): bool
    {
        return self::first($text, $terms) !== null;
    }

    public static function normalize(?string $text): string
    {
        $text = mb_strtolower((string) $text);
        $text = str_replace(['’', "'"], '', $text);
        $text = (string) preg_replace('/[^\p{L}\p{N}\s\-]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function prepare(?string $text): string
    {
        $text = self::normalize($text);
        foreach (self::DESIGNATORS as $pattern) {
            $text = (string) preg_replace($pattern, ' ', $text);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
