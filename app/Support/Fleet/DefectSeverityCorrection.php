<?php

declare(strict_types=1);

namespace App\Support\Fleet;

/**
 * The words for correcting an issue's severity. Severity ("Routine" or "Significant damage") decides
 * whether the unit's readiness is affected and whether the issue may be closed with no action required,
 * so changing it is a recorded decision with a reason, made only by Logistics reviewers.
 */
final class DefectSeverityCorrection
{
    public const ACTION_LABEL = 'Correct severity';

    public const REASON_LABEL = 'Why is the severity being changed?';

    public const REASON_HELPER = 'Required. This stays in the issue history, and the people who reported or confirmed the issue are told.';

    public const DIALOG_TEXT = 'Use this when the damage level was entered incorrectly. It does not close or resolve the issue, and the change is recorded in the history.';

    public const SUBMIT_LABEL = 'Save severity';

    public const SUCCESS = 'Severity corrected.';

    public const ROUTINE = 'routine';

    public const SIGNIFICANT = 'significant';

    /** @var array<string, string> */
    public const LABELS = [
        self::ROUTINE => 'Routine',
        self::SIGNIFICANT => 'Significant damage',
    ];

    public static function label(?string $severity): string
    {
        return self::LABELS[(string) $severity] ?? self::LABELS[self::ROUTINE];
    }

    /** A plain-language problem with the reason, or null when it is acceptable. */
    public static function reasonProblem(?string $reason): ?string
    {
        if (DefectNoActionClosure::isMeaningful($reason, [self::REASON_LABEL, self::ACTION_LABEL, self::SUBMIT_LABEL])) {
            return null;
        }

        return 'Say why the severity is being changed, in at least '.DefectNoActionClosure::MIN_REASON_LENGTH.' characters. For example: "Entered as significant by mistake; the latch is only scuffed".';
    }
}
