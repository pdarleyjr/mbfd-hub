<?php

declare(strict_types=1);

namespace App\Support\Fleet;

/**
 * How a scheduled duty's recorded answer is printed for reviewers (docs/apparatus-issue-history.md section 4).
 *
 * New submissions answer a duty Completed or Not Completed. A payload queued on an older phone answered with the
 * equipment words, and those stay valid in history: they print in task language with the recorded word beside
 * them, so nothing recorded is rewritten.
 */
final class ScheduledDutyStatus
{
    public const COMPLETED = 'Completed';

    public const NOT_COMPLETED = 'Not Completed';

    public static function label(?string $status): string
    {
        return match ($status) {
            self::COMPLETED => 'Completed',
            self::NOT_COMPLETED => 'Not completed',
            'Present' => 'Completed (recorded as Present)',
            'Missing', 'Damaged' => 'Not completed (recorded as '.$status.')',
            null, '' => 'Reported',
            default => $status,
        };
    }
}
