<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\ApparatusChangeout;
use App\Models\ApparatusChangeoutItem;
use App\Models\ApparatusOperationalAlert;

/**
 * The member and Fleet view of a changeout (docs/apparatus-changeouts.md section 6). Reads only. Controlled lines are the
 * name only unless the viewer can verify them.
 */
final class ApparatusChangeoutPresenter
{
    /** @return array<string, mixed> */
    public function summary(ApparatusChangeout $changeout, bool $canVerify, bool $isFleet, bool $withLines): array
    {
        $changeout->loadMissing(['items', 'alert', 'toApparatus']);
        $items = $changeout->items;
        $open = $items->filter(static fn (ApparatusChangeoutItem $item): bool => $item->isOpen());
        $alert = $changeout->alert;

        return [
            'id' => (int) $changeout->getKey(),
            'status' => $changeout->status,
            'status_label' => ApparatusChangeout::statusLabel($changeout->status),
            'handoff_status' => $changeout->handoff_status,
            'mode' => $changeout->move_audit['mode'] ?? null,
            'unit_designation' => $changeout->unit_designation,
            'from_vehicle_number' => $changeout->from_vehicle_number,
            'to_vehicle_number' => $changeout->to_vehicle_number,
            'to_apparatus_id' => $changeout->to_apparatus_id,
            'station_id' => $changeout->station_id,
            'effective_at' => $changeout->effective_at?->utc()->toIso8601ZuluString(),
            'alert_id' => $changeout->alert_id,
            'return_of_changeout_id' => $changeout->return_of_changeout_id,
            'submitted_by_name' => $changeout->submitted_by_name,
            'moved' => $items->where('custody_status', ApparatusChangeoutItem::CUSTODY_MOVED)->count(),
            'left_behind' => $items->where('custody_status', ApparatusChangeoutItem::CUSTODY_LEFT_BEHIND)->count(),
            'open' => $open->count(),
            'open_items' => $open->map(static fn (ApparatusChangeoutItem $item): array => ['name' => $item->item_name, 'status' => $item->custody_status])->values()->all(),
            'alert_completed' => $alert !== null && $alert->status === ApparatusOperationalAlert::STATUS_COMPLETED
                && (int) $alert->completed_by_changeout_id === (int) $changeout->getKey(),
            'can_verify' => $canVerify,
            'next_daily_checkout_path' => $this->nextDailyCheckoutPath($changeout),
            'message' => $this->messageFor($changeout, $open->count()),
            ...($isFleet ? ['corrections_left_on_truck' => $changeout->move_audit['corrections_left_on_truck'] ?? null] : []),
            ...($withLines ? ['lines' => $items->map(fn (ApparatusChangeoutItem $item): array => $this->lineFor($item, $canVerify))->values()->all()] : []),
        ];
    }

    /** The crew needs a fresh Daily Checkout on the new truck: today's checkout stays with the old truck. */
    private function nextDailyCheckoutPath(ApparatusChangeout $changeout): ?string
    {
        $truck = $changeout->toApparatus;
        if (! $changeout->isCompleted() || $truck === null || $truck->slug !== $changeout->unit_slug) {
            return null;
        }

        return ($truck->getAttributes()['daily_checkout_requirement'] ?? null) === 'required' ? '/daily/apparatus/'.$truck->slug : null;
    }

    private function messageFor(ApparatusChangeout $changeout, int $open): string
    {
        $unit = $changeout->unit_designation;
        $to = $changeout->to_vehicle_number;
        $from = $changeout->from_vehicle_number ?: 'its current truck';

        return match ($changeout->status) {
            ApparatusChangeout::STATUS_COMPLETED => "{$unit} is now on truck {$to}.".($open > 0 ? " {$open} ".($open === 1 ? 'item still needs' : 'items still need').' to be confirmed.' : ''),
            ApparatusChangeout::STATUS_PENDING_FLEET => "Your work is saved. Fleet needs to confirm truck {$to} before {$unit} changes. No truck has changed yet.",
            ApparatusChangeout::STATUS_REJECTED => "Fleet did not approve this change. {$unit} stays on truck {$from}.",
            default => "This change was cancelled. {$unit} stays on truck {$from}.",
        };
    }

    /** @return array<string, mixed> */
    private function lineFor(ApparatusChangeoutItem $item, bool $canVerify): array
    {
        $base = [
            'id' => (int) $item->getKey(),
            'item_key' => $item->item_key,
            'name' => $item->item_name,
            'controlled' => $item->isControlled(),
            'custody_status' => $item->custody_status,
        ];
        if ($item->isControlled() && ! $canVerify) {
            return $base;
        }

        return [
            ...$base,
            'compartment_name' => $item->compartment_name,
            'expected_quantity' => $item->expected_quantity,
            'handling' => $item->handling,
            'quantity_moved' => $item->quantity_moved,
            'condition' => $item->condition,
            'note' => $item->note,
            'apparatus_defect_id' => $item->apparatus_defect_id,
            'verified_at' => $item->verified_at?->utc()->toIso8601ZuluString(),
            'verification_note' => $item->verification_note,
        ];
    }
}
