<?php

declare(strict_types=1);

namespace App\Services\Defects;

use App\Enums\ApparatusServiceTicketStatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusServiceTicket;
use App\Support\Fleet\DefectActionStatus;

/**
 * "Close — No Action Required" says nothing needs to be done. That is only believable for a
 * report that was wrong, a duplicate, or truly harmless. This guard refuses it while the record
 * itself says the unit's readiness or a repair is involved, so a real or dangerous defect can
 * never leave the board through that shortcut. A completed service ticket means a repair
 * happened, so that issue is Resolved, never "closed, nothing repaired".
 *
 * The check lives in the service layer (not a button's visibility), so every door shares it:
 * the admin inspection page, the Defects list, and Captain 5. refusal() only reads, so a form
 * can ask it whether to offer the action at all.
 */
final class NoActionClosureGuard
{
    /** Fields of action_details that mean real repair or replacement work is already moving. */
    private const WORK_DETAILS = ['parts_ordered', 'vendor_reference', 'expected_delivery', 'repair_appointment'];

    /**
     * Plain-language refusal, or null when the issue may be closed with no action required.
     *
     * @param  bool  $captain5  Captain 5 cannot reclassify impact, correct severity or open tickets, so its advice is to ask Logistics.
     */
    public function refusal(ApparatusDefect $defect, bool $captain5 = false): ?string
    {
        $readiness = [];
        $significant = $defect->severity === 'significant';
        if ($significant) {
            $readiness[] = 'significant damage';
        }
        $impact = (string) $defect->operational_impact;
        if ($impact === 'out_of_service') {
            $readiness[] = 'out of service';
        } elseif ($impact === 'needs_repair') {
            $readiness[] = 'needing repair';
        }

        $work = $this->workUnderway($defect);
        $ticket = $this->linkedTicket($defect);
        $openTicket = $ticket !== null && $ticket['open'] ? $ticket['label'] : null;
        $completedTicket = $ticket !== null && $ticket['completed'] ? $ticket['label'] : null;

        if ($readiness === [] && $work === null && $openTicket === null && $completedTicket === null) {
            return null;
        }

        $parts = [];
        if ($readiness !== []) {
            $parts[] = 'This issue is marked '.$this->joined($readiness).'. That affects whether the unit can respond, so it cannot be closed with no action required.';
        }
        if ($work !== null) {
            $parts[] = "Repair or replacement work is already arranged ({$work}), so it cannot be closed with no action required.";
        }
        if ($openTicket !== null) {
            $parts[] = "A service ticket ({$openTicket}) is still open for this issue, so it cannot be closed with no action required.";
        }
        if ($completedTicket !== null) {
            $parts[] = "A service ticket ({$completedTicket}) was completed for this issue, so a repair was done and it cannot be closed with no action required.";
        }
        $parts[] = $this->nextStep($captain5, $readiness !== [], $significant, $readiness !== [] && ! $significant, $openTicket !== null, $completedTicket !== null);

        return implode(' ', $parts);
    }

    private function nextStep(bool $captain5, bool $readiness, bool $significant, bool $impactOnly, bool $openTicket, bool $completedTicket): string
    {
        if ($captain5) {
            $fix = $significant ? 'ask Logistics to correct the severity' : 'ask Logistics to correct it';

            return $completedTicket
                ? 'Resolve it with Repair completed instead.'
                : "Resolve it once it is repaired or replaced. If the report was entered incorrectly, {$fix}.";
        }
        $steps = [];
        if ($openTicket) {
            $steps[] = 'complete or cancel the ticket first';
        }
        if ($significant) {
            $steps[] = 'if the report was entered incorrectly, change the severity with Correct severity (the change is recorded in the history), then close it';
        }
        // Operational impact and severity both have an audited update path (Classify impact, Correct severity).
        if ($impactOnly) {
            $steps[] = 'if it was entered incorrectly, change the operational impact with Classify impact (the change is recorded in the history), then close it';
        }
        if ($completedTicket) {
            $steps[] = 'resolve it with Repair completed instead';
        } elseif ($significant) {
            $steps[] = 'otherwise resolve it once repaired';
        } else {
            $steps[] = 'resolve it once repaired, or ask Fleet to correct the report';
        }

        return ucfirst(implode('; ', $steps)).'.';
    }

    /** Describes arranged work, e.g. "repair scheduled", or null when none is recorded. */
    private function workUnderway(ApparatusDefect $defect): ?string
    {
        $status = (string) $defect->action_status;
        if ($status === DefectActionStatus::REPAIR_SCHEDULED) {
            return 'repair scheduled';
        }
        if ($status === DefectActionStatus::REPLACEMENT_AVAILABLE) {
            return 'replacement available';
        }
        $details = is_array($defect->action_details) ? $defect->action_details : [];
        foreach (self::WORK_DETAILS as $key) {
            $value = $details[$key] ?? null;
            if ($value !== null && $value !== '' && $value !== false) {
                return 'parts, a vendor or a repair date are on record';
            }
        }

        return null;
    }

    /**
     * The linked ticket's number and whether it is still open or was completed. A cancelled ticket
     * (and an archived one that never completed) counts for neither: no repair came of it.
     *
     * @return array{label: string, open: bool, completed: bool}|null
     */
    private function linkedTicket(ApparatusDefect $defect): ?array
    {
        if ($defect->service_ticket_id === null) {
            return null;
        }
        // A card that already loaded the ticket does not ask again; the service always reads a fresh row.
        $ticket = $defect->relationLoaded('serviceTicket')
            ? $defect->getRelation('serviceTicket')
            : ApparatusServiceTicket::query()->find($defect->service_ticket_id);
        if (! $ticket instanceof ApparatusServiceTicket) {
            return null;
        }
        $completed = $ticket->status === ApparatusServiceTicketStatus::Completed->value;
        $open = $ticket->is_open;

        return $open || $completed
            ? ['label' => $ticket->ticket_number ?: 'Ticket #'.$ticket->getKey(), 'open' => $open, 'completed' => $completed]
            : null;
    }

    /** @param list<string> $items */
    private function joined(array $items): string
    {
        return count($items) <= 1 ? (string) ($items[0] ?? '') : implode(' and ', $items);
    }
}
