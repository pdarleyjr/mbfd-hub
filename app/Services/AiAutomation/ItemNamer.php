<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

/**
 * How a checklist item is named inside a member reply: readable, never shouting, never a wall of text.
 * "Spanner Wrench (rear)" -> "Spanner Wrench"; "KITTY LITTER" -> "Kitty Litter"; "SCBA CYLINDER" -> "SCBA Cylinder".
 */
final class ItemNamer
{
    public const MAX_NAMED_ITEMS = 3;

    private const MAX_LABEL_CHARACTERS = 60;

    /** Kept in capitals when a whole name arrives in capitals. */
    private const ACRONYMS = [
        'SCBA', 'TIC', 'PPE', 'PPV', 'AED', 'CPR', 'BVM', 'RIT', 'PASS', 'EMS', 'ALS', 'BLS', 'LED', 'AC', 'DC', 'PAR', 'MSA', 'HD', 'IV', 'CO', 'AM', 'FM', 'UHF', 'VHF', 'GPS', 'USB', 'NFPA', 'LUCAS',
    ];

    public static function label(string $itemName): string
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\s*[(\[].*?[)\]]\s*/u', ' ', $itemName)));
        $label = trim($label !== '' ? $label : trim($itemName), " \t,;:-");

        $shouting = preg_match('/\p{Ll}/u', $label) !== 1;
        $words = [];
        foreach (explode(' ', $label) as $word) {
            $words[] = self::word($word, $shouting);
        }

        $label = implode(' ', $words);
        if (mb_strlen($label) > self::MAX_LABEL_CHARACTERS) {
            $label = rtrim(mb_substr($label, 0, self::MAX_LABEL_CHARACTERS));
            $label = (string) preg_replace('/\s+\S*$/u', '', $label) ?: $label;
        }

        return $label;
    }

    /**
     * "A", "A and B", "A, B and C", then "A, B, C and other items". Duplicates (ignoring case) are named once.
     *
     * @param  list<string>  $itemNames
     */
    public static function join(array $itemNames): string
    {
        $labels = [];
        foreach ($itemNames as $name) {
            $label = self::label($name);
            if ($label !== '') {
                $labels[mb_strtolower($label)] ??= $label;
            }
        }
        $labels = array_values($labels);

        if ($labels === []) {
            return 'item';
        }
        if (count($labels) > self::MAX_NAMED_ITEMS) {
            return implode(', ', array_slice($labels, 0, self::MAX_NAMED_ITEMS)).' and other items';
        }
        if (count($labels) === 1) {
            return $labels[0];
        }

        return implode(', ', array_slice($labels, 0, -1)).' and '.end($labels);
    }

    /** @param  list<string>  $itemNames */
    public static function distinctCount(array $itemNames): int
    {
        $seen = [];
        foreach ($itemNames as $name) {
            $label = self::label($name);
            if ($label !== '') {
                $seen[mb_strtolower($label)] = true;
            }
        }

        return count($seen);
    }

    private static function word(string $word, bool $shouting): string
    {
        $isUpper = preg_match('/^[\p{Lu}\p{N}\-\/&.]+$/u', $word) === 1 && preg_match('/\p{Lu}/u', $word) === 1;
        if (! $isUpper) {
            return $word;
        }
        $letters = (string) preg_replace('/[^\p{L}]/u', '', $word);
        // Model numbers and sizes (20V, M18, 4-GAS) and known acronyms stay as typed.
        if (preg_match('/\p{N}/u', $word) === 1 || in_array(mb_strtoupper($letters), self::ACRONYMS, true)) {
            return $word;
        }
        // A short all-capital word inside a mixed-case name is most likely an acronym (K-Tool, TIC, DWH).
        if (! $shouting && mb_strlen($letters) <= 4) {
            return $word;
        }

        return mb_convert_case(mb_strtolower($word), MB_CASE_TITLE);
    }
}
