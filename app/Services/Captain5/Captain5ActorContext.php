<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Services\Security\Captain5AccessDecision;

/**
 * Snapshot of the capacity a Captain 5 page action is taken in. It is written onto every audit
 * event (`apparatus_defect_events.details.actor_context`) so history never depends on who holds
 * which role later. The acting member is always the signed-in user; this only adds context.
 */
final readonly class Captain5ActorContext
{
    /** Operations available on the Captain 5 page. Fleet-only operations (impact, tickets) are absent. */
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
        return $this->decision->canAct && in_array($operation, self::OPERATIONS, true);
    }

    /** @return array<string, mixed> */
    public function toAudit(): array
    {
        $onDuty = $this->decision->onDuty;

        return [
            'role' => 'captain5',
            'authority' => $this->decision->authority(),
            'label' => $this->decision->authorityLabel(),
            'shown_captain5' => $this->decision->isShownCaptain5,
            'acting_request_id' => $this->decision->isShownCaptain5 ? $onDuty->actingRequest?->getKey() : null,
            'duty_date' => $onDuty->dutyDate->format('Y-m-d'),
            'shift' => $onDuty->shiftLetter,
            'bid_shift' => $this->decision->bidShiftLetter,
        ];
    }
}
