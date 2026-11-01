<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * The Shift Captain 5 or Shift 300 for a duty day by bid seat, and why. Feeds narcotics review and the
 * audit capacity, so TeleStaff never reaches it.
 */
final readonly class OnDutyCaptain5
{
    public const SOURCE_BID = 'bid_holder';

    public const SOURCE_UNKNOWN = 'unknown';

    public function __construct(
        public CarbonImmutable $dutyDate,
        public ?string $shiftLetter,
        public string $source,
        public ?Employee $employee,
    ) {}

    public function isResolved(): bool
    {
        return $this->employee !== null;
    }

    public function isEmployee(?int $employeeProfileId): bool
    {
        return $employeeProfileId !== null && $this->employee !== null
            && (int) $this->employee->getKey() === $employeeProfileId;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'duty_date' => $this->dutyDate->format('Y-m-d'),
            'shift' => $this->shiftLetter,
            'source' => $this->source,
            'resolved' => $this->isResolved(),
            // The member's preferred (display) name when set, otherwise the personnel record name.
            'name' => $this->employee !== null ? trim((string) ($this->employee->display_name ?: $this->employee->name)) : null,
            'rank' => $this->employee?->rank,
            // Always null now. Installed apps still running the previous page build read these keys.
            'acting_until' => null,
            'acting_request_id' => null,
        ];
    }
}
