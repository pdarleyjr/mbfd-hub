<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use RuntimeException;

/**
 * A changeout that was refused because the records moved, or because it was already sent. The message is written for
 * the firefighter reading it. Nothing was written when this is thrown: the surrounding transaction rolls back.
 */
final class ChangeoutConflictException extends RuntimeException
{
    public const REPLAY_CONFLICT = 'CHANGEOUT_REPLAY_CONFLICT';

    public const STALE = 'CHANGEOUT_STALE';

    public const TRUCK_UNAVAILABLE = 'CHANGEOUT_TRUCK_UNAVAILABLE';

    public const ALREADY_PENDING = 'CHANGEOUT_ALREADY_PENDING';

    public const CHECKLIST_CHANGED = 'CHANGEOUT_CHECKLIST_CHANGED';

    public const ALREADY_RECORDED = 'CHANGEOUT_ALREADY_RECORDED';

    public const ALREADY_DECIDED = 'CHANGEOUT_ALREADY_DECIDED';

    public const TRUCK_REQUIRED = 'CHANGEOUT_TRUCK_REQUIRED';

    public const NOT_COMPLETED = 'CHANGEOUT_NOT_COMPLETED';

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
