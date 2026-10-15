<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The AI backend is temporarily unavailable (connection failure, rate limit or
 * server error after bounded retries). Callers that run unattended treat this
 * as an expected operational condition: keep existing data, log one warning and
 * finish successfully. Programming or contract errors must never use this type.
 */
final class AIServiceUnavailable extends RuntimeException
{
    public static function fromStatus(int $status, string $source = 'AI request', ?\Throwable $previous = null): self
    {
        return new self("{$source} failed: {$status}", $status, $previous);
    }

    public static function unreachable(?\Throwable $previous = null): self
    {
        return new self('AI service is unreachable.', 0, $previous);
    }
}
