<?php

declare(strict_types=1);

namespace App\Services\Ems;

use RuntimeException;

/**
 * A refusal that is safe and useful to show to a person: a plain-language message, a stable
 * machine code and an HTTP status. The JSON API turns it into the standard error envelope.
 */
final class EmsException extends RuntimeException
{
    /** @param array<string, list<string>> $fields */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'invalid',
        public readonly int $status = 422,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what): self
    {
        return new self("That {$what} could not be found.", 'not_found', 404);
    }

    public static function conflict(string $message, string $code = 'conflict'): self
    {
        return new self($message, $code, 409);
    }

    public static function forbidden(string $message = 'You do not have access to do that.'): self
    {
        return new self($message, 'forbidden', 403);
    }
}
