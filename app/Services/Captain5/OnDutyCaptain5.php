<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Captain5\Captain5DutyAssignment;
use App\Models\Employee;
use Carbon\CarbonImmutable;

/** Who holds on-duty Captain 5 authority for a duty day, and why. */
final readonly class OnDutyCaptain5
{
    public const SOURCE_ACTING = 'acting_assignment';

    public const SOURCE_BID = 'bid_holder';

    public const SOURCE_UNKNOWN = 'unknown';

    public function __construct(
        public CarbonImmutable $dutyDate,
        public ?string $shiftLetter,
        public string $source,
        public ?Employee $employee,
        public ?Captain5DutyAssignment $assignment,
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
            'name' => $this->employee !== null ? trim((string) ($this->employee->display_name ?: $this->employee->name)) : null,
            'rank' => $this->employee?->rank,
            'assignment_id' => $this->assignment?->getKey(),
        ];
    }
}
