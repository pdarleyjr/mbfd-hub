<?php

declare(strict_types=1);

namespace App\Support\Narcotics;

/**
 * The seal number a member types on a narcotics check (docs/narcotics-check.md section 1).
 *
 * Free text on purpose: seals mix digits, letters, dashes and spaces, so nothing is restricted to digits.
 * Required on every new submission; trimmed (ordinary and non-breaking spaces), 1 to the configured length in
 * characters, one line, no control, zero-width or bidirectional characters. Stored exactly as typed after trimming.
 * The single-line rule is the one the "Other" rescue unit text uses (RescueUnits::FREE_TEXT_PATTERN).
 */
final class SealNumber
{
    public static function maxLength(): int
    {
        return (int) config('narcotics_checks.seal_number_max_length');
    }

    /**
     * Trims the typed text before it is validated; anything that is not text is left alone so the validator reports it.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function prepare(array $input): array
    {
        if (isset($input['seal_number']) && is_string($input['seal_number'])) {
            $input['seal_number'] = RescueUnits::trimText($input['seal_number']);
        }

        return $input;
    }

    /** @return array<string, array<int, string>> */
    public static function rules(): array
    {
        return ['seal_number' => ['required', 'string', 'max:'.self::maxLength(), 'regex:'.RescueUnits::FREE_TEXT_PATTERN]];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        $max = self::maxLength();

        return [
            'seal_number.required' => 'Enter the seal number.',
            'seal_number.string' => 'Enter the seal number as text.',
            'seal_number.max' => "The seal number can be at most {$max} characters.",
            'seal_number.regex' => 'The seal number must be on one line, without special characters.',
        ];
    }
}
