<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\ApparatusChangeout;
use App\Models\ApparatusChangeoutItem;
use App\Services\Fleet\ApparatusChangeoutPlanner;
use Illuminate\Support\Str;

/** The stored changeout record in the words Fleet reads on the changeout page. Reads only. */
final class ApparatusChangeoutWording
{
    public const GROUP_LEFT = 'left';

    public const GROUP_TOOK = 'took';

    public static function correctionsLeft(ApparatusChangeout $changeout): int
    {
        return (int) ($changeout->move_audit['corrections_left_on_truck'] ?? 0);
    }

    public static function correctionsWarning(ApparatusChangeout $changeout): string
    {
        $count = self::correctionsLeft($changeout);
        $truck = $changeout->from_vehicle_number ?: 'the old truck';

        return $count.' approved checklist '.Str::plural('correction', $count).' stayed on truck '.$truck.' and did not follow '.$changeout->unit_designation
            .'. The Daily Checkout on the new truck will not show '.($count === 1 ? 'it' : 'them').'.';
    }

    /**
     * Each equipment line in one sentence: what the crew left, and what they took.
     *
     * @return array{left: list<string>, took: list<string>}
     */
    public static function lines(ApparatusChangeout $changeout): array
    {
        $changeout->loadMissing('items');
        $groups = [self::GROUP_LEFT => [], self::GROUP_TOOK => []];
        foreach ($changeout->items->sortBy('id') as $item) {
            $groups[$item->isLeftBehind() ? self::GROUP_LEFT : self::GROUP_TOOK][] = self::lineText($item);
        }

        return $groups;
    }

    public static function lineLabel(ApparatusChangeoutItem $item): string
    {
        return filled($item->compartment_name) ? $item->compartment_name.': '.$item->item_name : $item->item_name;
    }

    public static function lineText(ApparatusChangeoutItem $item): string
    {
        return self::lineLabel($item).'.'.(filled($item->note) ? ' Note: '.$item->note : '');
    }

    /**
     * move_audit as sentences: what each truck was before the move and what it is now.
     *
     * @return list<string>
     */
    public static function moveLines(ApparatusChangeout $changeout): array
    {
        $audit = $changeout->move_audit;
        if (! is_array($audit) || $audit === []) {
            return [];
        }
        if (($audit['mode'] ?? null) === ApparatusChangeoutPlanner::MODE_HANDOFF_ONLY) {
            return ['The unit was already on truck '.$changeout->to_vehicle_number.', so no truck record changed here. This recorded what the crew took and left.'];
        }
        $lines = [];
        foreach (['from', 'to'] as $side) {
            $before = $audit['before'][$side] ?? null;
            $after = $audit['after'][$side] ?? null;
            if (is_array($before) && is_array($after)) {
                $lines[] = 'Truck '.($before['vehicle_number'] ?? 'not recorded').' was '.self::identity($before).'. It is now '.self::identity($after).'.';
            }
        }
        if ($lines !== []) {
            $lines[] = 'Vehicle numbers, VINs, meters, defects, inspections and tickets stayed with their own trucks.';
        }

        return $lines;
    }

    /** @param array<string, mixed> $snapshot */
    private static function identity(array $snapshot): string
    {
        $name = $snapshot['designation'] ?? $snapshot['unit_id'] ?? null;
        $status = $snapshot['status'] ?? null;

        return trim((is_string($name) && $name !== '' ? $name : 'no unit').(is_string($status) && $status !== '' ? ' ('.$status.')' : ''));
    }
}
