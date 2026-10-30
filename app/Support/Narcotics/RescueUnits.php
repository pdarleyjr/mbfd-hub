<?php

declare(strict_types=1);

namespace App\Support\Narcotics;

use Illuminate\Validation\Rule;

/**
 * The rescue unit a member names on a narcotics check (docs/narcotics-check.md section 1).
 *
 * The list lives in config/narcotics_checks.php and nowhere else: the form receives it from the form
 * endpoint and this class validates every submission against it, so the browser's copy is never trusted.
 * Two values are stored: the option chosen (`rescue_unit`) and, only when that option is "Other", the text
 * the member typed (`rescue_unit_other`). A listed unit never keeps free text.
 */
final class RescueUnits
{
    /** Single line: no control characters (tabs and newlines included), no invisible formatting characters. */
    public const FREE_TEXT_PATTERN = '/^[^\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+$/u';

    /** Trims ordinary and non-breaking spaces from both ends; text that is not valid UTF-8 is returned unchanged so validation refuses it. */
    public static function trimText(string $text): string
    {
        return preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $text) ?? $text;
    }

    /** @return list<string> every option in the order shown, "Other" last */
    public static function options(): array
    {
        return [...array_values(array_map('strval', (array) config('narcotics_checks.rescue_units'))), self::other()];
    }

    /** The listed unit a search term names exactly ("r2" is R2), ignoring case and spaces; null for anything else, including "Other". */
    public static function listedUnit(string $term): ?string
    {
        $wanted = mb_strtolower(preg_replace('/\s+/u', '', $term) ?? $term);
        foreach (array_values(array_map('strval', (array) config('narcotics_checks.rescue_units'))) as $unit) {
            if (mb_strtolower($unit) === $wanted) {
                return $unit;
            }
        }

        return null;
    }

    public static function other(): string
    {
        return (string) config('narcotics_checks.rescue_unit_other_option');
    }

    public static function maxOtherLength(): int
    {
        return (int) config('narcotics_checks.rescue_unit_other_max_length');
    }

    /**
     * Trims the typed text (ordinary and non-breaking spaces) before it is validated; anything that is not
     * text is left alone so the validator reports it.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function prepare(array $input): array
    {
        if (isset($input['rescue_unit_other']) && is_string($input['rescue_unit_other'])) {
            $input['rescue_unit_other'] = self::trimText($input['rescue_unit_other']);
        }

        return $input;
    }

    /**
     * The text box is only judged when "Other" was chosen: with a listed unit it is ignored and not stored.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(mixed $choice): array
    {
        $rules = ['rescue_unit' => ['required', 'string', Rule::in(self::options())]];
        if ($choice === self::other()) {
            $rules['rescue_unit_other'] = ['required', 'string', 'max:'.self::maxOtherLength(), 'regex:'.self::FREE_TEXT_PATTERN];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        $max = self::maxOtherLength();

        return [
            'rescue_unit.required' => 'Choose your rescue unit.',
            'rescue_unit.string' => 'Choose your rescue unit from the list.',
            'rescue_unit.in' => 'Choose your rescue unit from the list.',
            'rescue_unit_other.required' => 'Enter which rescue unit you are on.',
            'rescue_unit_other.string' => 'Enter the rescue unit as text.',
            'rescue_unit_other.max' => "The rescue unit can be at most {$max} characters.",
            'rescue_unit_other.regex' => 'The rescue unit must be on one line, without special characters.',
        ];
    }

    /**
     * What is stored, from validated data: the option, and the typed text only for "Other".
     *
     * @param  array<string, mixed>  $validated
     * @return array{rescue_unit: string, rescue_unit_other: string|null}
     */
    public static function resolve(array $validated): array
    {
        $choice = (string) $validated['rescue_unit'];

        return [
            'rescue_unit' => $choice,
            'rescue_unit_other' => $choice === self::other() ? (string) $validated['rescue_unit_other'] : null,
        ];
    }

    /** What people read: the typed text for "Other", else the unit; null for a check sent before units existed. */
    public static function label(?string $choice, ?string $other): ?string
    {
        if ($choice === null || $choice === '') {
            return null;
        }
        if ($choice === self::other()) {
            $text = trim((string) $other);

            return $text !== '' ? $text : $choice;
        }

        return $choice;
    }
}
