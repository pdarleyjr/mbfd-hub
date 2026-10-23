<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Services\Security\Captain5AccessDecision;

/**
 * Snapshot of the authority a Captain 5 action is taken under. It is written onto every
 * audit event (`apparatus_defect_events.details.actor_context`) so history never depends on
 * who holds which role later.
 */
final readonly class Captain5ActorContext
{
    /** Operations Captain 5 may perform on an in-scope issue. Fleet-only operations are absent. */
    public const OPERATIONS = ['acknowledge', 'update_action', 'resolve', 'verify_correction', 'reopen', 'dismiss'];

    /** action_details keys Captain 5 may set (assignment of Logistics staff stays with Logistics). */
    public const DETAIL_KEYS = ['parts_ordered', 'vendor_reference', 'expected_delivery', 'pickup_instructions'];

    public function __construct(
        public Captain5AccessDecision $decision,
    ) {}

    public static function from(Captain5AccessDecision $decision): self
    {
        return new self($decision);
    }

    public function mayPerform(string $operation): bool
    {
        if (! $this->decision->canAct || ! in_array($operation, self::OPERATIONS, true)) {
            return false;
        }

        return $operation !== 'dismiss' || $this->decision->canDismiss;
    }

    /** @return array<string, mixed> */
    public function toAudit(): array
    {
        $onDuty = $this->decision->onDuty;

        return [
            'role' => 'captain5',
            'authority' => $this->decision->authority(),
            'label' => $this->decision->authorityLabel(),
            'on_duty' => $this->decision->hasOnDutyAuthority,
            'assignment_id' => $this->decision->actingAssignment?->getKey(),
            'duty_date' => $onDuty->dutyDate->format('Y-m-d'),
            'shift' => $this->decision->actingAssignment->shift_letter ?? $onDuty->shiftLetter,
            'bid_shift' => $this->decision->bidShiftLetter,
        ];
    }
}
