<?php

declare(strict_types=1);

namespace App\Support\UnitStatus;

/** TeleStaff rank labels in the short form the pop-up shows (contract 10.3). Any other rank is shown as written. */
final class RankAbbreviator
{
    private const SHORT = [
        'firefighter' => 'FF',
        'firefighter de' => 'DE',
        'lieutenant' => 'Lt.',
        'combat lieutenant' => 'Lt.',
        'captain' => 'Capt.',
        'combat captain' => 'Capt.',
        'captain pm' => 'Capt.',
        'marine captain' => 'Capt.',
        'division chief' => 'DC',
        'battalion chief' => 'BC',
    ];

    /** Leading dots are dropped (TeleStaff prints ".Firefighter"); matching ignores case and extra spaces. */
    public static function abbreviate(?string $rank): ?string
    {
        $written = trim(ltrim(trim((string) $rank), '. '));
        if ($written === '') {
            return null;
        }
        $key = strtolower(trim(preg_replace('/\s+/u', ' ', $written) ?? $written));

        return self::SHORT[$key] ?? $written;
    }
}
