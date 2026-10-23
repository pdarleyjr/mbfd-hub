<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\Captain5\Captain5DutyAssignment;
use App\Services\Captain5\OnDutyCaptain5;

/**
 * Immutable result of Captain5Access::decide(). The five capabilities are kept separate on
 * purpose: viewing the page, viewing scoped data, acting on issues, on-duty Captain 5 authority
 * and administrative configuration (docs/captain5-ems-operations-center.md section 2).
 */
final readonly class Captain5AccessDecision
{
    public const SOURCE_SUPER_ADMIN = 'super_admin';

    public const SOURCE_ADMINISTRATOR = 'administrator';

    public const SOURCE_OFFICER_RANK = 'officer_rank';

    public const SOURCE_CAPTAIN5_SEAT = 'captain5_seat';

    public const SOURCE_EMS_CAPTAIN_SEAT = 'ems_captain_seat';

    public const SOURCE_ACTING = 'acting_assignment';

    public const SOURCE_ALLOW_VIEW = 'explicit_allow_view';

    public const SOURCE_ALLOW_ACT = 'explicit_allow_act';

    public const SOURCE_REVIEWER = 'fleet_reviewer';

    /**
     * @param  list<string>  $sources  qualifications that apply after overrides
     */
    public function __construct(
        public array $sources,
        public bool $canViewPage,
        public bool $canViewData,
        public bool $canAct,
        public bool $hasOnDutyAuthority,
        public bool $canDismiss,
        public bool $canManageAssignments,
        public bool $canConfigure,
        public bool $isReviewer,
        public bool $denied,
        public ?string $bidShiftLetter,
        public ?Captain5DutyAssignment $actingAssignment,
        public OnDutyCaptain5 $onDuty,
    ) {}

    public static function none(OnDutyCaptain5 $onDuty, bool $denied = false): self
    {
        return new self([], false, false, false, false, false, false, false, false, $denied, null, null, $onDuty);
    }

    public function has(string $source): bool
    {
        return in_array($source, $this->sources, true);
    }

    /** The authority an action is taken under, snapshotted onto every audit row. */
    public function authority(): string
    {
        return match (true) {
            $this->hasOnDutyAuthority && $this->actingAssignment !== null => 'on_duty_acting',
            $this->hasOnDutyAuthority => 'on_duty_bid_holder',
            $this->has(self::SOURCE_EMS_CAPTAIN_SEAT) => 'ems_captain',
            $this->has(self::SOURCE_CAPTAIN5_SEAT) => 'captain5_seat',
            $this->has(self::SOURCE_ALLOW_ACT) => 'explicit_act',
            $this->isReviewer => 'fleet_reviewer',
            default => 'view_only',
        };
    }

    public function authorityLabel(): string
    {
        return match ($this->authority()) {
            'on_duty_acting' => 'Captain 5 (acting)',
            'on_duty_bid_holder' => 'Captain 5 (on duty)',
            'ems_captain' => 'EMS Captain',
            'captain5_seat' => 'Captain 5',
            'explicit_act' => 'Captain 5 delegate',
            'fleet_reviewer' => 'Logistics',
            default => 'Viewer',
        };
    }

    /** @return array<string, mixed> client-safe capability summary (UI hints only) */
    public function toClientArray(): array
    {
        return [
            'view' => $this->canViewData,
            'act' => $this->canAct,
            'on_duty' => $this->hasOnDutyAuthority,
            'dismiss' => $this->canDismiss,
            'manage_assignments' => $this->canManageAssignments,
            'configure' => $this->canConfigure,
            'acts_as' => $this->isReviewer && ! $this->hasOnDutyAuthority && ! $this->has(self::SOURCE_CAPTAIN5_SEAT)
                && ! $this->has(self::SOURCE_EMS_CAPTAIN_SEAT) && ! $this->has(self::SOURCE_ALLOW_ACT) && $this->actingAssignment === null
                ? 'logistics' : 'captain5',
            'authority' => $this->authority(),
            'authority_label' => $this->authorityLabel(),
        ];
    }
}
