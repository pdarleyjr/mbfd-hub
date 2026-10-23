<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\ApparatusInventoryChange;
use App\Models\ApparatusInventoryCorrection;

/** What a member says is wrong with a checklist entry, and the change it suggests. */
final class InventoryCorrectionReason
{
    public const NO_LONGER_CARRIED = 'no_longer_carried';

    public const MOVED = 'moved';

    public const REPLACED = 'replaced';

    public const WRONG_QUANTITY = 'wrong_quantity';

    public const WRONG_DESCRIPTION = 'wrong_description';

    public const NEW_ITEM = 'new_item';

    public const OTHER = 'other';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::NO_LONGER_CARRIED => 'No longer carried',
            self::MOVED => 'Moved to a different compartment',
            self::REPLACED => 'Replaced with a different model or type',
            self::WRONG_QUANTITY => 'Quantity is wrong',
            self::WRONG_DESCRIPTION => 'Name or description is wrong',
            self::NEW_ITEM => 'Carried but not on the checklist',
            self::OTHER => 'Something else',
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::labels());
    }

    public static function label(?string $reason): string
    {
        return self::labels()[$reason] ?? 'Inventory correction';
    }

    /**
     * The operation the member's proposal suggests, as the starting point for Logistics.
     * `null` fields mean "Logistics must decide" (for example a move described only in words).
     *
     * @return array{op: string, fields: array<string, mixed>}
     */
    public static function suggestedOperation(ApparatusInventoryCorrection $correction): array
    {
        $proposal = is_array($correction->proposal) ? $correction->proposal : [];
        $text = static fn (string $key): ?string => is_string($proposal[$key] ?? null) && trim($proposal[$key]) !== '' ? trim($proposal[$key]) : null;
        $quantity = is_int($proposal['quantity'] ?? null) ? $proposal['quantity'] : null;

        return match ($correction->reason) {
            self::NO_LONGER_CARRIED => ['op' => ApparatusInventoryChange::OP_REMOVE, 'fields' => []],
            self::MOVED => ['op' => ApparatusInventoryChange::OP_MODIFY, 'fields' => ['compartment_id' => $text('to_compartment_id')]],
            self::REPLACED => ['op' => ApparatusInventoryChange::OP_MODIFY, 'fields' => array_filter([
                'name' => $text('name'),
                'manufacturer' => $text('manufacturer'),
                'model' => $text('model'),
                'description' => $text('description'),
            ], static fn (mixed $value): bool => $value !== null)],
            self::WRONG_QUANTITY => ['op' => ApparatusInventoryChange::OP_MODIFY, 'fields' => ['expectedQuantity' => $quantity]],
            self::WRONG_DESCRIPTION => ['op' => ApparatusInventoryChange::OP_MODIFY, 'fields' => array_filter([
                'name' => $text('name'),
                'description' => $text('description'),
            ], static fn (mixed $value): bool => $value !== null)],
            self::NEW_ITEM => ['op' => ApparatusInventoryChange::OP_ADD, 'fields' => array_filter([
                'compartment_id' => $correction->compartment_key,
                'name' => $text('name'),
                'expectedQuantity' => $quantity,
                'manufacturer' => $text('manufacturer'),
                'model' => $text('model'),
                'description' => $text('description'),
            ], static fn (mixed $value): bool => $value !== null)],
            default => ['op' => ApparatusInventoryChange::OP_MODIFY, 'fields' => []],
        };
    }
}
