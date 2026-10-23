<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Captain5\Captain5ActingRequest;
use App\Models\Employee;
use Carbon\CarbonImmutable;

/** Who is shown as the shift's Captain 5 for a duty day, and why. Display and routing only. */
final readonly class OnDutyCaptain5
{
    public const SOURCE_ACTING = 'acting';

    public const SOURCE_BID = 'bid_holder';

    public const SOURCE_UNKNOWN = 'unknown';

    public function __construct(
        public CarbonImmutable $dutyDate,
        public ?string $shiftLetter,
        public string $source,
        public ?Employee $employee,
        public ?Captain5ActingRequest $actingRequest,
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
            'acting_until' => $this->actingRequest?->ends_at?->utc()->toIso8601String(),
            'acting_request_id' => $this->actingRequest?->getKey(),
        ];
    }
}
