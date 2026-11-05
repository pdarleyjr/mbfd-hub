<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

/**
 * TeleStaff's 7-day outlook: the same one-day workbook as the 5-minute export, sent for the next days a few
 * times a day. It fills dates the 5-minute export has not reached and never replaces one it is keeping current.
 */
final class RosterOutlook
{
    /** Stored on ledger rows and on a date's pointer while that date is showing outlook data. */
    public const SOURCE = 'outlook';

    /** Recognised and processed at all. Off leaves its mail to the 5-minute export's classifier, as before. */
    public static function isEnabled(): bool
    {
        return in_array(config('daily_roster.mode'), ['shadow', 'publish'], true)
            && in_array(config('daily_roster.outlook.mode'), ['shadow', 'publish'], true);
    }

    /** Allowed to move a pointer. Never while the 5-minute export itself is only shadowing. */
    public static function publishes(): bool
    {
        return config('daily_roster.mode') === 'publish' && config('daily_roster.outlook.mode') === 'publish';
    }

    public static function maxDaysAhead(): int
    {
        return max(1, (int) config('daily_roster.outlook.max_days_ahead', 7));
    }
}
