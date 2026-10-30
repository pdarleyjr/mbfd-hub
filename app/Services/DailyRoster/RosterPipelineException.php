<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use RuntimeException;

/**
 * A pipeline fault that is safe to log and send to error reporting: the message is the code and
 * nothing else, so no workbook text, name or ID can reach a log through it.
 */
final class RosterPipelineException extends RuntimeException
{
    public const TRANSITION_UNDEFINED = 'state_transition_undefined';

    public const TRANSITION_CONFLICT = 'state_transition_conflict';

    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }

    /** Another worker moved the row first. Expected under concurrency, and never an alert. */
    public function isConflict(): bool
    {
        return $this->errorCode === self::TRANSITION_CONFLICT;
    }
}
