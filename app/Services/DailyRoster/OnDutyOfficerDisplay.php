<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\Employee;
use App\Services\Captain5\OnDutyCaptain5;
use Carbon\CarbonImmutable;

/**
 * Who the 300 and Captain 5 cards show, and why. It carries the unchanged seat result next to the
 * shown result, so a display decision can never be mistaken for the authority answer.
 *
 * With the card display flag off, toArray() is exactly OnDutyCaptain5::toArray(): same keys, same
 * values, nothing added. With it on, the payload gains `as_of`, `freshness`, `identity` and `reason`
 * and `source` may read `telestaff`. No employee ID is ever part of the payload.
 */
final readonly class OnDutyOfficerDisplay
{
    public const SOURCE_TELESTAFF = 'telestaff';

    public const FRESHNESS_CURRENT = 'current';

    public const FRESHNESS_AWAITING = 'awaiting';

    public const FRESHNESS_DELAYED = 'delayed';

    public const FRESHNESS_NOT_APPLICABLE = 'not_applicable';

    public const IDENTITY_LINKED = 'linked';

    public const IDENTITY_UNLINKED = 'unlinked';

    public const REASON_CONFLICT = 'conflict';

    public const REASON_NEEDS_REVIEW = 'needs_review';

    public const REASON_NO_WORKING_ROW = 'no_working_row';

    public const REASON_SECTION_MISSING = 'section_missing';

    private function __construct(
        /** The seat answer that authority keeps using. */
        public OnDutyCaptain5 $seat,
        public string $source,
        public ?string $name,
        public ?string $rank,
        /** The Hub member behind a roster row or seat; null when shown by source name only, or when nobody is shown. */
        public ?Employee $employee,
        public ?CarbonImmutable $asOf,
        public string $freshness,
        public ?string $identity,
        public ?string $reason,
        private bool $additive,
    ) {}

    /** The flag is off (or the roster could not be read): today's payload, untouched. */
    public static function unchanged(OnDutyCaptain5 $seat): self
    {
        return self::fromSeat($seat, self::FRESHNESS_NOT_APPLICABLE, null, null, false);
    }

    /** The existing seat result, explaining why today's roster is not behind it. */
    public static function seat(OnDutyCaptain5 $seat, string $freshness, ?string $reason = null, ?CarbonImmutable $asOf = null): self
    {
        return self::fromSeat($seat, $freshness, $reason, $asOf, true);
    }

    public static function fromRoster(OnDutyCaptain5 $seat, string $name, ?string $rank, ?Employee $employee, ?CarbonImmutable $asOf): self
    {
        return new self(
            $seat,
            self::SOURCE_TELESTAFF,
            $name,
            $rank,
            $employee,
            $asOf,
            self::FRESHNESS_CURRENT,
            $employee !== null ? self::IDENTITY_LINKED : self::IDENTITY_UNLINKED,
            null,
            true,
        );
    }

    /** The roster for this instant is usable but does not name one person: nobody is shown. */
    public static function unknown(OnDutyCaptain5 $seat, string $reason, ?CarbonImmutable $asOf): self
    {
        return new self($seat, OnDutyCaptain5::SOURCE_UNKNOWN, null, null, null, $asOf, self::FRESHNESS_CURRENT, null, $reason, true);
    }

    public function isResolved(): bool
    {
        return $this->name !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = $this->seat->toArray();
        if (! $this->additive) {
            return $payload;
        }

        return array_merge($payload, [
            'source' => $this->source,
            'resolved' => $this->isResolved(),
            'name' => $this->name,
            'rank' => $this->rank,
            'as_of' => $this->asOf?->utc()->toIso8601String(),
            'freshness' => $this->freshness,
            'identity' => $this->identity,
            'reason' => $this->reason,
        ]);
    }

    private static function fromSeat(OnDutyCaptain5 $seat, string $freshness, ?string $reason, ?CarbonImmutable $asOf, bool $additive): self
    {
        $payload = $seat->toArray();

        return new self($seat, $seat->source, $payload['name'], $payload['rank'], $seat->employee, $asOf, $freshness, null, $reason, $additive);
    }
}
