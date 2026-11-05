<?php

declare(strict_types=1);

namespace App\Services\Defects;

use App\Models\ApparatusDefect;
use App\Support\Fleet\DefectActionStatus;
use RuntimeException;

/** A member's "fixed" that cannot be applied, with the HTTP answer the member sees. */
final class MemberCorrectionRefused extends RuntimeException
{
    private function __construct(string $message, public readonly int $status, public readonly string $errorCode, public readonly ?string $currentStatus = null)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('That issue was not found.', 404, 'NOT_FOUND');
    }

    public static function closed(ApparatusDefect $defect): self
    {
        return new self('This issue is already closed.', 409, 'ISSUE_CLOSED', DefectActionStatus::label($defect->action_status));
    }

    public static function keyConflict(): self
    {
        return new self('That request was already used. Reload and try again.', 409, 'CLIENT_ACTION_CONFLICT');
    }
}
