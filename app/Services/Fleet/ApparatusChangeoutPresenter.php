<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\ApparatusChangeout;
use App\Models\ApparatusChangeoutItem;
use App\Models\ApparatusOperationalAlert;

/** The member and Fleet view of a changeout (docs/apparatus-changeouts.md section 6). Reads only. */
final class ApparatusChangeoutPresenter
{
    /** @return array<string, mixed> */
    public function summary(ApparatusChangeout $changeout, bool $withLines): array
    {
        $changeout->loadMissing(['items', 'alert', 'toApparatus']);
        $items = $changeout->items;
        $alert = $changeout->alert;
        $audit = (array) ($changeout->move_audit ?? []);

        return [
            'id' => (int) $changeout->getKey(),
            'status' => $changeout->status,
            'unit_designation' => $changeout->unit_designation,
            'from_vehicle_number' => $changeout->from_vehicle_number,
            'to_vehicle_number' => $changeout->to_vehicle_number,
            'to_apparatus_id' => $changeout->to_apparatus_id,
            'station_id' => $changeout->station_id,
            'effective_at' => $changeout->effective_at?->utc()->toIso8601ZuluString(),
            'old_truck_status' => $audit['old_truck_status'] ?? ApparatusChangeout::OLD_TRUCK_STATUS,
            'alert_id' => $changeout->alert_id,
            'alert_completed' => $alert !== null && $alert->status === ApparatusOperationalAlert::STATUS_COMPLETED
                && (int) $alert->completed_by_changeout_id === (int) $changeout->getKey(),
            'return_of_changeout_id' => $changeout->return_of_changeout_id,
            'submitted_by_name' => $changeout->submitted_by_name,
            'moved' => $items->where('custody_status', ApparatusChangeoutItem::CUSTODY_MOVED)->count(),
            'left_behind' => $items->where('custody_status', ApparatusChangeoutItem::CUSTODY_LEFT_BEHIND)->count(),
            'next_daily_checkout_path' => $this->nextDailyCheckoutPath($changeout),
            'message' => self::messageFor($changeout),
            'new_truck_record' => (bool) ($audit['new_truck_record'] ?? false),
            'displaced_unit' => isset($audit['displaced_unit']) && is_string($audit['displaced_unit']) ? $audit['displaced_unit'] : null,
            ...($withLines ? ['lines' => $items->sortBy('id')->map(static fn (ApparatusChangeoutItem $item): array => [
                'id' => (int) $item->getKey(),
                'item_key' => $item->item_key,
                'name' => $item->item_name,
                'compartment' => $item->compartment_name,
                'handling' => $item->handling,
                'custody_status' => $item->custody_status,
                'note' => $item->note,
            ])->values()->all()] : []),
        ];
    }

    /** "R 44 is now on truck 1033. Truck 17503 is marked Out of Service." */
    public static function messageFor(ApparatusChangeout $changeout): string
    {
        $unit = $changeout->unit_designation;
        $to = $changeout->to_vehicle_number;
        if (($changeout->move_audit['mode'] ?? null) === ApparatusChangeoutPlanner::MODE_HANDOFF_ONLY) {
            return "{$unit} is on truck {$to}. What you took and what you left is recorded.";
        }

        return "{$unit} is now on truck {$to}. ".self::oldTruckSentence($changeout);
    }

    public static function oldTruckSentence(ApparatusChangeout $changeout): string
    {
        $from = filled($changeout->from_vehicle_number) ? 'Truck '.$changeout->from_vehicle_number : 'The old truck';

        return "{$from} is marked ".ApparatusChangeout::OLD_TRUCK_STATUS.'.';
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
}
