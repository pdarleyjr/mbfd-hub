<?php

declare(strict_types=1);

namespace App\Support\Narcotics;

/**
 * Where a narcotics check notification opens. Links carry the check's random UUID and nothing else;
 * the page behind them re-checks authorization on every request.
 */
final class NarcoticsLinks
{
    public const FORM_KEY = 'narcotics-check';

    public const STAFF_WORKSPACE = '/daily/captain-5';

    public const MEMBER_FORMS = '/employee/forms';

    /** Captain 5: the Narcotics Checks tab, one check open. */
    public static function staff(string $uuid): string
    {
        return self::STAFF_WORKSPACE.'?tab=narcotics&check='.$uuid;
    }

    /** The member's Forms app: one of their own checks. */
    public static function member(string $uuid): string
    {
        return self::MEMBER_FORMS.'?form='.self::FORM_KEY.'&check='.$uuid;
    }

    /** A new narcotics check in the member's Forms app. */
    public static function memberForm(): string
    {
        return self::MEMBER_FORMS.'?form='.self::FORM_KEY;
    }

    /** The member's own history. */
    public static function memberChecks(): string
    {
        return self::MEMBER_FORMS.'?form='.self::FORM_KEY.'&view=mine';
    }
}
