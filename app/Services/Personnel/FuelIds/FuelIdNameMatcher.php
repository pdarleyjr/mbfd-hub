<?php

declare(strict_types=1);

namespace App\Services\Personnel\FuelIds;

use Illuminate\Support\Str;

/**
 * Identity checks for fuel list rows.
 *
 * Employee numbers compare after trimming whitespace and leading zeros only. A last name agrees with an employee's
 * free-text name ("Firstname Lastname" or "LASTNAME, FIRSTNAME") when, ignoring case, accents, apostrophes and
 * generational suffixes, every token of the last name appears in the name, or the joined last name equals a run of
 * consecutive name tokens ("DE LA CRUZ" and "Delacruz" agree both ways).
 */
final class FuelIdNameMatcher
{
    private const SUFFIXES = ['JR', 'SR', 'II', 'III', 'IV', 'V'];

    public static function normalizeEmployeeNumber(string $value): string
    {
        $value = (string) preg_replace('/\s+/u', '', $value);
        $trimmed = ltrim($value, '0');

        return $trimmed === '' && $value !== '' ? '0' : $trimmed;
    }

    public static function lastNameAgrees(string $sourceLastName, ?string $employeeName): bool
    {
        $source = self::tokens($sourceLastName);
        $employee = self::tokens((string) $employeeName);
        if ($source === [] || $employee === []) {
            return false;
        }
        if (array_diff($source, $employee) === []) {
            return true;
        }
        $joined = implode('', $source);
        $count = count($employee);
        for ($start = 0; $start < $count; $start++) {
            $run = '';
            for ($end = $start; $end < $count; $end++) {
                $run .= $employee[$end];
                if ($run === $joined) {
                    return true;
                }
                if (strlen($run) >= strlen($joined)) {
                    break;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function tokens(string $value): array
    {
        $ascii = Str::upper(Str::ascii(str_replace(["'", '’', '`'], '', $value)));
        $parts = preg_split('/[^A-Z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($parts, fn (string $part): bool => ! in_array($part, self::SUFFIXES, true)));
    }
}
