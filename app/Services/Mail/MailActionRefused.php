<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A mailbox action the server will not perform. The message is written for the member who clicked, so a page
 * can show it as is. It renders as 403 when it reaches the HTTP layer.
 */
final class MailActionRefused extends HttpException
{
    public static function forbidden(string $message = 'You do not have access to the Email Center.'): self
    {
        return new self(403, $message);
    }

    public static function automatedThread(): self
    {
        return new self(403, 'Automated messages are read-only. They are kept for reference and cannot be changed here.');
    }

    /** A draft that is not the caller's looks exactly like a draft that does not exist. */
    public static function draftNotFound(): self
    {
        return new self(404, 'That draft was not found.');
    }

    public static function draftLocked(string $message): self
    {
        return new self(409, $message);
    }

    public static function trashedThread(): self
    {
        return new self(409, 'This conversation is in Trash. Restore it first.');
    }
}
