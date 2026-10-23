<?php

declare(strict_types=1);

namespace App\Services\Defects;

use App\Models\ApparatusDefect;
use RuntimeException;

/** Someone else changed the issue after the caller loaded it; nothing was written. */
final class StaleDefectStateException extends RuntimeException
{
    public function __construct(public readonly ApparatusDefect $current)
    {
        parent::__construct('This issue was updated by someone else. Review the latest status and try again.');
    }
}
