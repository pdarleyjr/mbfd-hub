<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use RuntimeException;

/** The Command feed could not be read. `reason` is a stable code that is safe to store and log; bodies and tokens never are. */
final class SourceFeedUnavailable extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notConfigured(string $why): self
    {
        return new self('not_configured', 'The Unit Status source feed is not configured: '.$why);
    }

    public static function unreachable(bool $timedOut): self
    {
        return new self($timedOut ? 'timeout' : 'unreachable', 'The Command feed could not be reached.');
    }

    public static function status(int $status): self
    {
        $reason = match (true) {
            $status === 401 || $status === 403 => 'unauthorized',
            $status === 429 => 'rate_limited',
            $status >= 300 && $status < 400 => 'redirect',
            $status === 503 => 'feed_not_configured',
            $status >= 500 => 'server_error',
            default => 'http_'.$status,
        };

        return new self($reason, 'The Command feed answered HTTP '.$status.'.');
    }

    public static function schema(): self
    {
        return new self('bad_schema', 'The Command feed uses a schemaVersion this Hub does not know.');
    }

    public static function invalid(): self
    {
        return new self('invalid_response', 'The Command feed answered with something that is not a feed page.');
    }
}
