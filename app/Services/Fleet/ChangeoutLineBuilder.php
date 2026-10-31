<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\ApparatusChangeout;
use App\Models\ApparatusChangeoutItem;
use Illuminate\Validation\ValidationException;

/**
 * Turns a truck's equipment list and the member's choices into the handoff lines of a changeout. Pure: it saves nothing,
 * and a refusal here means nothing was written.
 */
final class ChangeoutLineBuilder
{
    public function __construct(private readonly ApparatusChangeoutPlanner $planner) {}

    /**
     * Every line on the list, at its default unless the member named it.
     *
     * @param  array{checklist_version: string|null, usable: bool, lines: list<array<string, mixed>>}  $equipment
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    public function linesFor(array $equipment, array $input, ?ApparatusChangeout $returnOf): array
    {
        $exceptions = $input['exceptions'];
        if ($input['checklist_version'] !== null && $exceptions !== [] && $input['checklist_version'] !== $equipment['checklist_version']) {
            throw new ChangeoutConflictException(ChangeoutConflictException::CHECKLIST_CHANGED, 'The equipment list for this truck changed while you were working. Nothing was saved. Reload the list, check your choices and send it again.');
        }
        $known = array_column($equipment['lines'], 'item_key');
        foreach (array_keys($exceptions) as $key) {
            if (! in_array((string) $key, $known, true)) {
                throw ValidationException::withMessages(['exceptions' => 'One of the items you chose is not on this truck\'s list. Reload the list and send it again.']);
            }
        }

        $last = $returnOf?->items->keyBy('item_key');
        $lines = [];
        foreach ($equipment['lines'] as $line) {
            $lines[] = $this->lineFrom($line, $exceptions[$line['item_key']] ?? null, $this->planner->lineDefault($line, $last?->get($line['item_key'])));
        }

        // With no usable list nobody can say what moved. One open line makes Fleet settle it instead of reading "complete".
        return $equipment['usable'] ? $lines : [[
            'item_key' => ApparatusChangeoutPlanner::NO_CHECKLIST_KEY,
            'equipment_key' => null,
            'compartment_id' => null,
            'compartment_name' => null,
            'item_name' => 'Equipment list not available for this truck',
            'expected_quantity' => null,
            'handling' => ApparatusChangeoutItem::HANDLING_REVIEW,
            'quantity_moved' => null,
            'condition' => ApparatusChangeoutItem::CONDITION_OK,
            'note' => null,
            'item_class' => ApparatusChangeoutItem::CLASS_STANDARD,
            'custody_status' => ApparatusChangeoutItem::CUSTODY_NEEDS_REVIEW,
        ]];
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>|null  $exception
     * @param  array{handling: string, quantity_moved: int|null, note: string|null}  $default
     * @return array<string, mixed>
     */
    private function lineFrom(array $line, ?array $exception, array $default): array
    {
        $controlled = $line['item_class'] === ApparatusChangeoutItem::CLASS_CONTROLLED;
        $handling = $exception['handling'] ?? $default['handling'];
        $condition = $exception['condition'] ?? ApparatusChangeoutItem::CONDITION_OK;
        $quantity = $exception === null ? $default['quantity_moved'] : $this->checkedQuantity($line, $exception, $handling, $condition, $controlled);
        $note = $exception['note'] ?? $default['note'];

        // A missing or damaged item is never moved: its defect stays with the truck it was on.
        $staysOnTruck = ! $controlled && $handling === ApparatusChangeoutItem::HANDLING_TRANSFER
            && ($condition !== ApparatusChangeoutItem::CONDITION_OK || $quantity === 0);
        if ($staysOnTruck) {
            $handling = ApparatusChangeoutItem::HANDLING_LEAVE;
        }

        return [
            ...$line,
            'handling' => $handling,
            'quantity_moved' => $quantity,
            'condition' => $condition,
            'note' => $note,
            'custody_status' => match (true) {
                $controlled => ApparatusChangeoutItem::CUSTODY_PENDING_VERIFICATION,
                $handling === ApparatusChangeoutItem::HANDLING_TRANSFER => ApparatusChangeoutItem::CUSTODY_MOVED,
                $handling === ApparatusChangeoutItem::HANDLING_LEAVE => ApparatusChangeoutItem::CUSTODY_LEFT_BEHIND,
                default => ApparatusChangeoutItem::CUSTODY_NEEDS_REVIEW,
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $exception
     */
    private function checkedQuantity(array $line, array $exception, string $handling, string $condition, bool $controlled): ?int
    {
        $quantity = $exception['quantity'];
        if ($quantity === null) {
            return null;
        }
        $name = $line['item_name'];
        $expected = $line['expected_quantity'];
        $problem = match (true) {
            $controlled => "{$name} is accounted for separately. Leave the count off.",
            $expected === null => "{$name} has no set count on this truck, so a count cannot be saved. Use the note instead.",
            $quantity > $expected => "{$name} has {$expected} on this truck. Enter a count from 0 to {$expected}.",
            $handling !== ApparatusChangeoutItem::HANDLING_TRANSFER => "A count is only saved for items that are moving. Change {$name} to moving, or clear the count.",
            $condition !== ApparatusChangeoutItem::CONDITION_OK => "Save a count or mark {$name} missing or damaged, not both. Put the rest in the note.",
            default => null,
        };
        if ($problem !== null) {
            throw ValidationException::withMessages(['exceptions' => $problem]);
        }

        return $quantity;
    }
}
