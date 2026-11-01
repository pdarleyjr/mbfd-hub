<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use RuntimeException;

/**
 * A changeout that was refused because the unit already moved, or because the same id came back with different choices.
 * There are no others: the crew's work goes through. The message is written for the firefighter reading it. Nothing
 * was written when this is thrown: the surrounding transaction rolls back.
 */
final class ChangeoutConflictException extends RuntimeException
{
    public const REPLAY_CONFLICT = 'CHANGEOUT_REPLAY_CONFLICT';

    public const STALE = 'CHANGEOUT_STALE';

    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> the 409 response body */
    public function toResponseArray(): array
    {
        return ['message' => $this->getMessage(), 'code' => $this->errorCode, ...$this->context];
    }
}
