<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\SupplyItem;

/**
 * The arithmetic of a count (docs/ems/captain5-medical-inventory-workflow.md sections 4 and 5).
 * Stock is always held in base units; a person types in the item's count unit. Compatible units are
 * the only thing compared: each, or the item's package unit with an explicit size.
 */
final class CountMath
{
    public const LABELS = [
        'each' => ['unit', 'units'],
        'box' => ['box', 'boxes'],
        'case' => ['case', 'cases'],
        'pack' => ['pack', 'packs'],
        'bag' => ['bag', 'bags'],
    ];

    /** How many base units one count unit holds. */
    public static function perCountUnit(SupplyItem $item): int
    {
        return $item->count_unit === 'each' ? 1 : max(1, (int) $item->units_per_package);
    }

    public static function toBase(SupplyItem $item, int $typed): int
    {
        return $typed * self::perCountUnit($item);
    }

    /** A base quantity as the person reads it: whole when it divides evenly, otherwise one decimal. */
    public static function display(?int $base, int $per): int|float|null
    {
        if ($base === null) {
            return null;
        }

        return $base % $per === 0 ? intdiv($base, $per) : round($base / $per, 1);
    }

    /**
     * quantity_to_order = max(0, required_on_hand - actual_count), in base units. Null means no order level is set.
     */
    public static function orderBase(?int $requiredBase, int $countedBase): ?int
    {
        return $requiredBase === null ? null : max(0, $requiredBase - $countedBase);
    }

    /** Rounded up, because you cannot order part of a box. */
    public static function orderInCountUnits(?int $orderBase, int $per): ?int
    {
        return $orderBase === null ? null : (int) ceil($orderBase / $per);
    }

    /**
     * The typed entry in the shape InventoryCountService converts.
     *
     * @return array<string, int>
     */
    public static function entryFor(SupplyItem $item, int $typed): array
    {
        return match ($item->count_unit) {
            'each' => ['each' => $typed],
            'case' => ['cases' => $typed],
            default => ['boxes' => $typed],
        };
    }

    public static function configProblem(SupplyItem $item): ?string
    {
        return $item->threshold_qty_base === null ? 'No order level set' : null;
    }

    public static function orderRuleText(SupplyItem $item): ?string
    {
        if ($item->threshold_qty_base === null) {
            return null;
        }
        $per = self::perCountUnit($item);
        $value = self::display($item->threshold_qty_base, $per);

        return "Order when fewer than {$value} ".self::label($item->count_unit, $value);
    }

    public static function label(string $unit, int|float|null $value): string
    {
        $labels = self::LABELS[$unit] ?? [$unit, $unit];

        return $value === 1 ? $labels[0] : $labels[1];
    }

    /**
     * The values frozen on a final count line, so a report never changes when the item does later.
     *
     * @return array<string, mixed>
     */
    public static function stamp(SupplyItem $item, ?int $countedBase, ?int $expectedBase): array
    {
        $per = self::perCountUnit($item);
        $order = $countedBase === null ? null : self::orderBase($item->threshold_qty_base, $countedBase);

        return [
            'section_key' => $item->section_key,
            'count_unit' => $item->count_unit,
            'units_per_count_unit' => $per,
            'counted_value' => $countedBase === null ? null : (int) ceil($countedBase / $per),
            'expected_quantity_base' => $expectedBase,
            'required_qty_base' => $item->threshold_qty_base,
            'threshold_source_text' => $item->threshold_source_text !== null ? mb_substr($item->threshold_source_text, 0, 120) : null,
            'order_qty_base' => $order,
            'order_qty_count_unit' => self::orderInCountUnits($order, $per),
            'config_problem' => self::configProblem($item),
        ];
    }
}
