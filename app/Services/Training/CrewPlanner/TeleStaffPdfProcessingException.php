<?php

declare(strict_types=1);

namespace App\Services\Training\CrewPlanner;

use InvalidArgumentException;

/** Only the allowlisted reason is suitable for operational diagnostics. */
final class TeleStaffPdfProcessingException extends InvalidArgumentException
{
    public const REPORT_UNAVAILABLE = 'report_unavailable';

    public const PROCESS_UNAVAILABLE = 'process_unavailable';

    public const PROCESS_START_FAILED = 'process_start_failed';

    public const PROCESS_TIMEOUT = 'process_timeout';

    public const INVALID_REPORT = 'invalid_report';

    public const OUTPUT_INVALID = 'output_invalid';

    public const PROCESS_FAILED = 'process_failed';

    public readonly string $reason;

    public function __construct(string $reason)
    {
        $this->reason = in_array($reason, [self::REPORT_UNAVAILABLE, self::PROCESS_UNAVAILABLE,
            self::PROCESS_START_FAILED, self::PROCESS_TIMEOUT, self::INVALID_REPORT,
            self::OUTPUT_INVALID, self::PROCESS_FAILED], true) ? $reason : self::PROCESS_FAILED;

        // Never retain a previous exception, path, command, report text or output.
        parent::__construct('This PDF could not be processed safely. Try a complete daily TeleStaff report.');
    }
}
