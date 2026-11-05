<?php

declare(strict_types=1);

namespace App\Services\StationRequests;

use RuntimeException;

/** A member resolve that was refused without writing anything. The controller turns it into a JSON error. */
final class StationRequestMemberResolveRefused extends RuntimeException
{
    public const REQUEST_CLOSED = 'REQUEST_CLOSED';

    public const CLIENT_ACTION_CONFLICT = 'CLIENT_ACTION_CONFLICT';

    /** @param array<string, mixed> $extra */
    private function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly string $errorCode,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function closed(string $status, string $statusLabel): self
    {
        return new self(
            'This request is already closed.',
            409,
            self::REQUEST_CLOSED,
            ['status' => $status, 'status_label' => $statusLabel],
        );
    }

    public static function clientActionReused(): self
    {
        return new self('That action id was already used by someone else.', 409, self::CLIENT_ACTION_CONFLICT);
    }

    public static function notEligible(): self
    {
        return new self('Your account cannot mark station requests resolved.', 403, 'NOT_ELIGIBLE');
    }
}
