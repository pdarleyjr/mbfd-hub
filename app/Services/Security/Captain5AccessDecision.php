<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Services\Captain5\OnDutyCaptain5;

/**
 * Immutable result of Captain5Access::decide(). Everyone eligible may use the whole Captain 5 page
 * (view, act, close); configuration (overrides, medical classification) is separate.
 */
final readonly class Captain5AccessDecision
{
    public const SOURCE_SUPER_ADMIN = 'super_admin';

    public const SOURCE_ADMINISTRATOR = 'administrator';

    public const SOURCE_OFFICER_RANK = 'officer_rank';

    public const SOURCE_CAPTAIN5_SEAT = 'captain5_seat';

    public const SOURCE_EMS_CAPTAIN_SEAT = 'ems_captain_seat';

    public const SOURCE_EXPLICIT_ALLOW = 'explicit_allow';

    public const SOURCE_REVIEWER = 'fleet_reviewer';

    /**
     * @param  list<string>  $sources  qualifications that apply after overrides
     */
    public function __construct(
        public array $sources,
        public bool $canViewPage,
        public bool $canViewData,
        public bool $canAct,
        public bool $canDismiss,
        public bool $canConfigure,
        public bool $isReviewer,
        public bool $denied,
        public bool $isShownCaptain5,
        public ?string $bidShiftLetter,
        public OnDutyCaptain5 $onDuty,
    ) {}

    public static function none(OnDutyCaptain5 $onDuty, bool $denied = false): self
    {
        return new self([], false, false, false, false, false, false, $denied, false, null, $onDuty);
    }

    public function has(string $source): bool
    {
        return in_array($source, $this->sources, true);
    }

    /**
     * Whether page actions use the Logistics path: a Fleet/Logistics reviewer keeps speaking as
     * Logistics unless they hold a Captain 5 or EMS Captain seat or are the shift's Captain 5.
     */
    public function actsAsLogistics(): bool
    {
        return $this->isReviewer && ! $this->isShownCaptain5
            && ! $this->has(self::SOURCE_CAPTAIN5_SEAT) && ! $this->has(self::SOURCE_EMS_CAPTAIN_SEAT);
    }

    /** The capacity an action is taken in, snapshotted onto every audit row. */
    public function authority(): string
    {
        return match (true) {
            $this->isShownCaptain5 => 'shift_captain5',
            $this->has(self::SOURCE_EMS_CAPTAIN_SEAT) => 'ems_captain',
            $this->has(self::SOURCE_CAPTAIN5_SEAT) => 'captain5_seat',
            $this->actsAsLogistics() => 'fleet_reviewer',
            $this->canAct => 'officer',
            default => 'view_only',
        };
    }

    public function authorityLabel(): string
    {
        return match ($this->authority()) {
            'shift_captain5' => 'Captain 5 (on shift)',
            'ems_captain' => 'EMS Captain',
            'captain5_seat' => 'Captain 5',
            'fleet_reviewer' => 'Logistics',
            'officer' => 'Captain 5 page',
            default => 'Viewer',
        };
    }

    /** @return array<string, mixed> client-safe capability summary (UI hints only) */
    public function toClientArray(): array
    {
        return [
            'view' => $this->canViewData,
            'act' => $this->canAct,
            'configure' => $this->canConfigure,
            'acts_as' => $this->actsAsLogistics() ? 'logistics' : 'captain5',
            'authority' => $this->authority(),
            'authority_label' => $this->authorityLabel(),
        ];
    }
}
