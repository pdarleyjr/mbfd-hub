<?php

declare(strict_types=1);

namespace App\Services\Training\CrewPlanner;

use Carbon\CarbonImmutable;

/**
 * What the Hub knows about staffing for one duty date. Either an accepted TeleStaff snapshot, or the plain
 * fact that there is none, in which case nobody may be described as on duty.
 */
final readonly class TrainingRosterDuty
{
    /** @param array<string, array<string, mixed>> $staff resolved duty, keyed by the roster's employee ID */
    public function __construct(
        public string $dutyDate,
        public ?int $snapshotId,
        public ?int $version,
        public ?string $shift,
        public bool $shiftIsScheduledOnly,
        public ?CarbonImmutable $asOf,
        public array $staff,
        /** Why no roster is available, in words for the planner; null when one is. */
        public ?string $unavailableReason,
        public ?string $warning,
    ) {}

    public function isVerified(): bool
    {
        return $this->snapshotId !== null;
    }

    public function shiftLabel(): string
    {
        if ($this->shift === null) {
            return 'Shift unconfirmed';
        }

        return $this->shift.' Shift'.($this->shiftIsScheduledOnly ? ' (scheduled)' : '');
    }
}
