<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\ApparatusChangeout;
use App\Models\ApparatusChangeoutItem;
use App\Services\Fleet\ApparatusChangeoutPlanner;
use App\Support\HubTimezone;
use Illuminate\Support\Str;

/**
 * The stored changeout record in the words Fleet reads on the changeout page. Reads only. Controlled lines are shown
 * by name only, with no quantity, in keeping with docs/apparatus-changeouts.md section 3.
 */
final class ApparatusChangeoutWording
{
    public const GROUP_EXCEPTIONS = 'exceptions';

    public const GROUP_OPEN = 'open';

    public const GROUP_MOVED = 'moved';

    public static function handoffLabel(?string $handoff): string
    {
        return match ($handoff) {
            ApparatusChangeout::HANDOFF_COMPLETE => 'Settled',
            ApparatusChangeout::HANDOFF_PENDING_VERIFICATION => 'Open lines',
            default => str((string) $handoff)->headline()->toString(),
        };
    }

    /** Says plainly what the current status means for the trucks. */
    public static function stateNote(ApparatusChangeout $changeout): ?string
    {
        return match ($changeout->status) {
            ApparatusChangeout::STATUS_PENDING_FLEET => 'No truck has changed. The crew\'s equipment choices are saved and wait on this decision.',
            ApparatusChangeout::STATUS_REJECTED => 'No truck changed. This record stays for the history.',
            ApparatusChangeout::STATUS_CANCELLED => 'Withdrawn before a decision. No truck changed.',
            default => null,
        };
    }

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
     * Each equipment line in one sentence, grouped: settled but unusual, still open, and moved as expected.
     *
     * @return array{exceptions: list<string>, open: list<string>, moved: list<string>}
     */
    public static function lines(ApparatusChangeout $changeout): array
    {
        $changeout->loadMissing('items.verifiedBy');
        $groups = [self::GROUP_EXCEPTIONS => [], self::GROUP_OPEN => [], self::GROUP_MOVED => []];
        foreach ($changeout->items->sortBy('id') as $item) {
            $groups[self::groupFor($item)][] = self::lineText($item);
        }

        return $groups;
    }

    private static function groupFor(ApparatusChangeoutItem $item): string
    {
        if ($item->isOpen()) {
            return self::GROUP_OPEN;
        }
        if ($item->custody_status === ApparatusChangeoutItem::CUSTODY_LEFT_BEHIND
            || in_array($item->condition, [ApparatusChangeoutItem::CONDITION_MISSING, ApparatusChangeoutItem::CONDITION_DAMAGED], true)
            || self::isPartial($item)
            || filled($item->note)) {
            return self::GROUP_EXCEPTIONS;
        }

        return self::GROUP_MOVED;
    }

    private static function isPartial(ApparatusChangeoutItem $item): bool
    {
        return ! $item->isControlled()
            && $item->quantity_moved !== null
            && $item->expected_quantity !== null
            && $item->quantity_moved < $item->expected_quantity;
    }

    public static function lineLabel(ApparatusChangeoutItem $item): string
    {
        return filled($item->compartment_name) ? $item->compartment_name.': '.$item->item_name : $item->item_name;
    }

    public static function lineText(ApparatusChangeoutItem $item): string
    {
        $parts = [self::lineLabel($item).'.', self::custodyWords($item)];
        if (self::isPartial($item)) {
            $parts[] = 'Moved '.$item->quantity_moved.' of '.$item->expected_quantity.'.';
        }
        if ($item->condition === ApparatusChangeoutItem::CONDITION_MISSING) {
            $parts[] = 'Reported missing.';
        } elseif ($item->condition === ApparatusChangeoutItem::CONDITION_DAMAGED) {
            $parts[] = 'Reported damaged.';
        }
        if ($item->apparatus_defect_id !== null) {
            $parts[] = 'An issue was filed on the truck.';
        }
        if (filled($item->note)) {
            $parts[] = 'Note: '.$item->note;
        }
        if (filled($item->verification_note)) {
            $parts[] = 'Verification note: '.$item->verification_note;
        }

        return implode(' ', $parts);
    }

    public static function custodyWords(ApparatusChangeoutItem $item): string
    {
        return match ($item->custody_status) {
            ApparatusChangeoutItem::CUSTODY_MOVED => 'Moved to the new truck.',
            ApparatusChangeoutItem::CUSTODY_LEFT_BEHIND => 'Left on the old truck.',
            ApparatusChangeoutItem::CUSTODY_NEEDS_REVIEW => 'Needs review.',
            ApparatusChangeoutItem::CUSTODY_PENDING_VERIFICATION => 'Controlled item, waiting for verification.',
            ApparatusChangeoutItem::CUSTODY_VERIFIED => 'Verified'
                .($item->verifiedBy !== null ? ' by '.$item->verifiedBy->name : '')
                .($item->verified_at !== null ? ' on '.$item->verified_at->setTimezone(HubTimezone::NAME)->format('M j, Y g:i A') : '').'.',
            default => str((string) $item->custody_status)->headline()->append('.')->toString(),
        };
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
            return ['The unit was already on truck '.$changeout->to_vehicle_number.', so no truck record changed here. This recorded the equipment handoff only.'];
        }
        $lines = [];
        foreach (['from', 'to'] as $side) {
            $before = $audit['before'][$side] ?? null;
            $after = $audit['after'][$side] ?? null;
            if (is_array($before) && is_array($after)) {
                $lines[] = 'Truck '.($before['vehicle_number'] ?? 'not recorded').' was '.self::identity($before).'. It is now '.self::identity($after).'.';
            }
        }
        if (filled($audit['reported_vehicle_number'] ?? null)) {
            $lines[] = 'The crew reported vehicle '.$audit['reported_vehicle_number'].', and Fleet matched it to truck '.$changeout->to_vehicle_number.'.';
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
