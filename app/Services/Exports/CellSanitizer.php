<?php

declare(strict_types=1);

namespace App\Services\Exports;

/**
 * Neutralizes spreadsheet formula injection in exported text.
 *
 * A cell whose text begins with = + - @ (ASCII or fullwidth/compatibility forms), or
 * with any control character, can be evaluated as a formula or hijack a CSV field
 * by Excel, LibreOffice and Sheets. Such text is prefixed with an apostrophe so it
 * is always shown as plain text. Numbers and dates are never passed through here.
 */
final class CellSanitizer
{
    /** Leading characters (after optional whitespace/control/zero-width characters) that start a formula. */
    private const FORMULA_START = '/^[\s\x00-\x1F\x7F\x{200B}-\x{200F}\x{2028}\x{2029}\x{FEFF}]*[=+\-@\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}\x{2212}\x{FE62}\x{FE63}\x{FE69}]/u';

    private const CONTROL_START = '/^[\x00-\x1F\x7F]/';

    /** Excel cell text limit is 32,767 characters. */
    public const MAX_CELL_LENGTH = 32000;

    public static function neutralize(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');
        if ($value === '') {
            return $value;
        }

        if (self::startsLikeFormula($value)) {
            return "'".$value;
        }

        return $value;
    }

    /** Clean text for a spreadsheet cell: valid UTF-8, bounded length, formula-safe. */
    public static function cell(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');
        if (mb_strlen($value) > self::MAX_CELL_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_CELL_LENGTH).' ... [truncated]';
        }

        return self::neutralize($value);
    }

    /**
     * Sanitize exporter states: text is neutralized; numbers, booleans and null pass through untouched.
     * Filament's exporters stringify numbers before this point, so a plain signed number such as "-5"
     * is kept as is (it cannot carry a formula); anything with an operator after it is still neutralized.
     */
    public static function value(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?$/', $value) === 1) {
            return $value;
        }

        return self::neutralize($value);
    }

    /**
     * Write one CSV line with every text value neutralized (RFC 4180 quoting, no backslash escape).
     *
     * @param  resource  $handle
     * @param  array<int, mixed>  $row
     */
    public static function putCsv($handle, array $row): void
    {
        fputcsv($handle, array_map(self::value(...), $row), ',', '"', '');
    }

    private static function startsLikeFormula(string $value): bool
    {
        if (preg_match(self::CONTROL_START, $value) === 1 || preg_match(self::FORMULA_START, $value) === 1) {
            return true;
        }

        // Catch any other Unicode compatibility form of the trigger characters (e.g. small or wide variants).
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);
            if (is_string($normalized) && $normalized !== $value) {
                return preg_match(self::FORMULA_START, $normalized) === 1;
            }
        }

        return false;
    }
}
