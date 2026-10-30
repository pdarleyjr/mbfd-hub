<?php

declare(strict_types=1);

namespace App\Services\DailyRoster\Parsing;

use RuntimeException;

/**
 * A workbook that failed closed. The code is safe to store and show to administrators;
 * the message never contains cell text.
 */
final class TeleStaffWorkbookException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly ?int $sourceRow = null)
    {
        parent::__construct($sourceRow === null ? $errorCode : "{$errorCode} at row {$sourceRow}");
    }
}
