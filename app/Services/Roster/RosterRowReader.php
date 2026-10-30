<?php

declare(strict_types=1);

namespace App\Services\Roster;

use DateTimeInterface;

/**
 * Classifies one spreadsheet row by what its cells contain, never by column
 * position, so inserted columns, shifted rows and reordered layouts still read
 * the same. Pure: no I/O.
 *
 * Kinds: blank, header, heading (one populated cell), slot (a staffing line,
 * real or placeholder), other (anything unrecognised).
 */
final class RosterRowReader
{
    private const TIME = '/^\d{1,2}:\d{2}(?::\d{2})?$/';

    private const PLACEHOLDER_NAME = '/^[?\-\s]*,[?\-\s]*$/';

    private const REAL_NAME = '/^[\p{L}][\p{L}\'’.\- ]*,\s*\p{L}/u';

    /**
     * @param  array<int|string, mixed>  $cells
     * @return array{kind: string, text?: string, position?: string, id?: ?string, name?: string, nameKind?: string, code?: string, from?: ?string, through?: ?string, hours?: ?string}
     */
    public static function read(array $cells): array
    {
        $texts = [];
        foreach (array_values($cells) as $index => $value) {
            $text = self::text($value);
            if ($text !== '') {
                $texts[$index] = $text;
            }
        }
        if ($texts === []) {
            return ['kind' => 'blank'];
        }

        $lower = array_map(static fn (string $text): string => mb_strtolower($text), $texts);
        if (in_array('rank', $lower, true) && in_array('name', $lower, true)) {
            return ['kind' => 'header'];
        }

        if (count($texts) === 1) {
            $only = reset($texts);
            $looksLikeName = preg_match(self::REAL_NAME, $only) === 1 && ! str_contains($only, ' / ') && ! str_contains($only, '{');

            return $looksLikeName || preg_match(self::PLACEHOLDER_NAME, $only) === 1 || preg_match(self::TIME, $only) === 1
                ? ['kind' => 'other']
                : ['kind' => 'heading', 'text' => $only];
        }

        return self::slot($texts) ?? ['kind' => 'other'];
    }

    /**
     * @param  array<int|string, string>  $texts
     * @return array<string, mixed>|null
     */
    private static function slot(array $texts): ?array
    {
        $keys = array_keys($texts);
        $idKey = null;
        $nameKey = null;
        $nameKind = 'none';
        $name = '';

        foreach ($keys as $position => $key) {
            if (preg_match('/^\d{4,10}$/', $texts[$key]) !== 1) {
                continue;
            }
            foreach (array_slice($keys, $position + 1) as $later) {
                if (preg_match('/\p{L}/u', $texts[$later]) === 1 && preg_match(self::TIME, $texts[$later]) !== 1) {
                    $idKey = $key;
                    $nameKey = $later;
                    $name = self::cleanName($texts[$later]);
                    $nameKind = self::isRealName($name) ? 'real' : 'placeholder';
                    break 2;
                }
            }
        }

        if ($nameKey === null) {
            foreach ($texts as $key => $text) {
                if (preg_match(self::PLACEHOLDER_NAME, $text) === 1) {
                    $nameKey = $key;
                    $name = $text;
                    $nameKind = 'placeholder';
                    break;
                }
            }
        }

        if ($nameKey === null) {
            $candidates = array_values(array_filter(
                $texts,
                static fn (string $text): bool => preg_match(self::TIME, $text) !== 1 && preg_match('/^\d+(?:\.\d+)?$/', $text) !== 1,
            ));
            $second = $candidates[1] ?? null;
            $found = $second === null ? false : array_search($second, $texts, true);
            if (is_int($found) && preg_match(self::REAL_NAME, self::cleanName($second)) === 1) {
                $nameKey = $found;
                $name = self::cleanName($second);
                $nameKind = 'real';
            }
        }

        $hasTime = (bool) array_filter($texts, static fn (string $text): bool => preg_match(self::TIME, $text) === 1);
        if ($nameKey === null && ! $hasTime) {
            return null;
        }

        $anchor = $idKey ?? $nameKey ?? PHP_INT_MAX;
        $before = array_filter($texts, static fn (string $text, int|string $key): bool => $key < $anchor, ARRAY_FILTER_USE_BOTH);
        $times = [];
        $code = '';
        $hours = null;
        foreach ($texts as $key => $text) {
            if ($nameKey !== null && $key <= $nameKey) {
                continue;
            }
            if (preg_match(self::TIME, $text) === 1) {
                $times[] = self::clock($text);
            } elseif (preg_match('/^\d+(?:\.\d+)?$/', $text) === 1 && $times !== []) {
                $hours = $text;
            } elseif ($code === '' && $times === []) {
                $code = $text;
            }
        }

        return [
            'kind' => 'slot',
            'position' => (string) (reset($before) ?: ''),
            'id' => $idKey !== null ? $texts[$idKey] : null,
            'name' => $name,
            'nameKind' => $nameKind,
            'code' => $code,
            'from' => $times[0] ?? null,
            'through' => $times[1] ?? null,
            'hours' => $hours,
        ];
    }

    /** Normalises any cell to trimmed text; day fractions become HH:MM. */
    public static function text(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i');
        }
        if (is_float($value)) {
            if ($value > 0 && $value < 1) {
                $minutes = (int) round($value * 1440);

                return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
            }

            return $value === floor($value) ? (string) (int) $value : (string) $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (! is_string($value)) {
            return '';
        }

        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $value) ?? '');
    }

    /** Drops trailing certification lists in parentheses and member tags in braces. */
    private static function cleanName(string $name): string
    {
        $clean = preg_replace('/\s*(?:\([^()]*\)|\{[^{}]*\})/u', '', $name) ?? $name;

        return trim(preg_replace('/\s+/u', ' ', $clean) ?? $clean);
    }

    private static function isRealName(string $name): bool
    {
        return preg_match(self::PLACEHOLDER_NAME, $name) !== 1 && preg_match('/\p{L}{2}/u', $name) === 1;
    }

    private static function clock(string $time): string
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return sprintf('%02d:%02d', $hour, $minute);
    }
}
