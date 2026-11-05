<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\SupplyItem;

/**
 * Green, yellow or red for one item, from what is on hand against its expected amount
 * (docs/ems/captain5-medical-inventory-workflow.md section 12).
 *
 * Each item has two lines, both in its count unit:
 *  - reorder point: at or below it the row is yellow. Preset to half the expected amount.
 *  - critical level: at or below it the row is red, and the alert list is told. Preset to a quarter.
 * A person can set either line for one item; clearing it returns to the preset. Out of stock is always red.
 * The daily checkout app repeats this rule in master/stockLevel.ts so a typed count recolors at once.
 */
final class StockLevel
{
    public const GREEN = 'green';

    public const YELLOW = 'yellow';

    public const RED = 'red';

    /**
     * Null when the item has no expected amount, so it has no colors.
     *
     * @return array{expected: int, reorder_at: int, critical_at: int, reorder_auto: bool, critical_auto: bool}|null
     */
    public static function lines(SupplyItem $item): ?array
    {
        $per = CountMath::perCountUnit($item);
        if ($item->threshold_qty_base === null || $item->threshold_qty_base < $per) {
            return null;
        }
        $expected = intdiv($item->threshold_qty_base, $per);
        // A lowered expected amount can leave a person's yellow line above it; the expected amount wins.
        $reorder = min($expected, $item->reorder_at_qty_base !== null ? intdiv($item->reorder_at_qty_base, $per) : intdiv($expected, 2));
        $critical = $item->critical_at_qty_base !== null ? intdiv($item->critical_at_qty_base, $per) : intdiv($expected, 4);

        return [
            'expected' => $expected,
            'reorder_at' => $reorder,
            'critical_at' => min($critical, $reorder),
            'reorder_auto' => $item->reorder_at_qty_base === null,
            'critical_auto' => $item->critical_at_qty_base === null,
        ];
    }

    /** Null when the item was never counted or has no expected amount. */
    public static function of(SupplyItem $item, ?int $onHandBase): ?string
    {
        $lines = self::lines($item);
        if ($lines === null || $onHandBase === null) {
            return null;
        }
        $per = CountMath::perCountUnit($item);

        return match (true) {
            $onHandBase <= 0, $onHandBase <= $lines['critical_at'] * $per => self::RED,
            $onHandBase <= $lines['reorder_at'] * $per => self::YELLOW,
            default => self::GREEN,
        };
    }
}
