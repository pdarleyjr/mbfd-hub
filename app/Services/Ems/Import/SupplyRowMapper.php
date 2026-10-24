<?php

declare(strict_types=1);

namespace App\Services\Ems\Import;

/**
 * Turns one verbatim supply-room row (mbfd.ems.supply_room_source.v1) into supply item columns.
 * Normalized numbers are separate from the verbatim text and may be null. A threshold is converted
 * to base units ONLY when its unit is "each", or equals the package unit and the row states an
 * explicit base_units_per_package. Anything else stays null and is flagged for review, never guessed.
 */
final class SupplyRowMapper
{
    private const PACKAGE_UNITS = ['each', 'box', 'case', 'pack', 'bag'];

    /**
     * @param  array<string, mixed>  $row
     * @return array{attributes: array<string, mixed>, review_reasons: list<string>}
     */
    public function map(array $row): array
    {
        $parsed = (array) ($row['parsed'] ?? []);
        $threshold = (array) ($parsed['threshold'] ?? []);
        $package = (array) ($parsed['package'] ?? []);
        $order = (array) ($parsed['order'] ?? []);

        $reasons = array_values(array_filter(array_map('strval', (array) ($row['review_reasons'] ?? []))));

        $packageUnit = isset($package['unit']) && in_array($package['unit'], self::PACKAGE_UNITS, true) ? (string) $package['unit'] : null;
        $perPackage = $this->positiveInt($package['base_units_per_package'] ?? null);

        $thresholdBase = null;
        $qty = $this->positiveInt($threshold['qty'] ?? null, allowZero: true);
        $unit = isset($threshold['unit']) ? (string) $threshold['unit'] : null;
        $comparison = $threshold['comparison'] ?? null;
        $text = $this->text($row['order_level_text'] ?? null);

        if ($text !== null && ($qty === null || $unit === null)) {
            $reasons[] = "Order level \"{$text}\" could not be converted to a number of units.";
        } elseif ($qty !== null) {
            if ($unit === 'each') {
                $thresholdBase = $qty;
            } elseif ($unit !== null && $unit === $packageUnit && $perPackage !== null) {
                $thresholdBase = $qty * $perPackage;
            } else {
                $reasons[] = "Order level \"{$text}\" is counted in {$unit}, but the number of individual units per {$unit} is not stated, so it was not converted.";
            }
            if ($comparison !== null && $comparison !== 'lt') {
                $reasons[] = "Order level \"{$text}\" uses a comparison other than \"less than\"; confirm when to reorder.";
                $thresholdBase = null;
            }
        }

        $orderPackages = null;
        if ($packageUnit !== null && ($order['text_unit'] ?? null) === $packageUnit) {
            $orderPackages = $this->positiveInt($order['text_qty'] ?? null);
        }

        $flags = (array) ($row['flags'] ?? []);
        $reasons = array_values(array_unique($reasons));

        return [
            'attributes' => [
                'section_key' => $this->text($row['section_key'] ?? null),
                'name' => (string) ($row['item_text'] ?? ''),
                'bag_alias_text' => $this->text($row['bag_alias_text'] ?? null),
                'base_unit' => 'each',
                'package_unit' => $packageUnit,
                'units_per_package' => $perPackage,
                'packages_per_case' => null,
                'threshold_qty_base' => $thresholdBase,
                'threshold_source_text' => $text,
                'qty_per_package_text' => $this->text($row['qty_per_package_text'] ?? null),
                'order_amount_text' => $this->text($row['order_amount_text'] ?? null),
                'order_qty_packages' => $orderPackages,
                'suppliers' => array_values((array) ($parsed['suppliers'] ?? [])),
                'item_numbers' => array_values((array) ($parsed['item_numbers'] ?? [])),
                'refrigerated' => (bool) ($flags['refrigerated'] ?? false),
                'controlled_substance' => (bool) ($flags['controlled_substance'] ?? false),
                'has_expiration' => array_key_exists('has_expiration', $flags) && $flags['has_expiration'] !== null ? (bool) $flags['has_expiration'] : null,
                'needs_review' => (bool) ($row['needs_review'] ?? false) || $reasons !== [],
                'review_reasons' => $reasons,
                'source' => $row,
            ],
            'review_reasons' => $reasons,
        ];
    }

    private function positiveInt(mixed $value, bool $allowZero = false): ?int
    {
        if (is_int($value) || (is_float($value) && floor($value) === $value) || (is_string($value) && ctype_digit($value))) {
            $int = (int) $value;

            return $int > 0 || ($allowZero && $int === 0) ? $int : null;
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
