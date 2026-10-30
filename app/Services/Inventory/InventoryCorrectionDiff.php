<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\ApparatusInventoryCorrection;

/**
 * The reviewer's "before -> after" for one member report, in plain words, built only from what
 * the report itself recorded (the snapshot of the checklist entry the member was looking at and
 * the member's proposal). It never reads or writes the inventory, so it is cheap enough for a
 * dashboard card and stays truthful for historical reports.
 */
final class InventoryCorrectionDiff
{
    private const NONE = 'Not set';

    /**
     * @param  array<string, string>  $compartmentNames  compartment id => name, only needed for reports recorded before the
     *                                                   destination name was stored with them
     * @return list<array{label: string, before: string, after: string}>
     */
    public static function rows(ApparatusInventoryCorrection $correction, array $compartmentNames = []): array
    {
        $snapshot = $correction->target_snapshot ?: [];
        $proposal = $correction->proposal ?: [];
        $before = static fn (string $key): string => self::text($snapshot[$key] ?? null);
        $note = self::text($correction->member_note, '');

        $rows = match ($correction->reason) {
            InventoryCorrectionReason::MOVED => [[
                'label' => 'Compartment',
                'before' => $before('compartment'),
                'after' => self::text($snapshot['to_compartment'] ?? null, filled($proposal['to_location_text'] ?? null)
                    ? (string) $proposal['to_location_text'].' (member\'s words)'
                    : (isset($proposal['to_compartment_id']) ? ($compartmentNames[(string) $proposal['to_compartment_id']] ?? 'Compartment '.$proposal['to_compartment_id']) : self::NONE)),
            ]],
            InventoryCorrectionReason::WRONG_QUANTITY => [[
                'label' => 'Quantity',
                'before' => $before('expectedQuantity'),
                'after' => self::text($proposal['quantity'] ?? null),
            ]],
            InventoryCorrectionReason::WRONG_DESCRIPTION, InventoryCorrectionReason::REPLACED => self::fieldRows($snapshot, $proposal, ['name' => 'Name', 'manufacturer' => 'Manufacturer', 'model' => 'Model', 'description' => 'Description']),
            InventoryCorrectionReason::NO_LONGER_CARRIED => [[
                'label' => 'On the checklist',
                'before' => $before('name'),
                'after' => 'Remove from the checklist',
            ]],
            InventoryCorrectionReason::NEW_ITEM => [[
                'label' => 'New item in '.self::text($snapshot['compartment'] ?? null, 'a compartment'),
                'before' => 'Not on the checklist',
                'after' => self::text($proposal['name'] ?? null).(isset($proposal['quantity']) ? ' × '.$proposal['quantity'] : ''),
            ]],
            default => [],
        };

        if ($rows === [] && $note !== '') {
            $rows[] = ['label' => 'Reported', 'before' => $before('name'), 'after' => $note];
        }

        return $rows;
    }

    /** One line for a card: "Compartment: Front Cab -> Rear Cab". */
    public static function summary(ApparatusInventoryCorrection $correction, array $compartmentNames = []): string
    {
        return collect(self::rows($correction, $compartmentNames))
            ->map(static fn (array $row): string => $row['label'].': '.$row['before'].' → '.$row['after'])
            ->implode('; ');
    }

    /**
     * Only the fields the member actually proposed.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $proposal
     * @param  array<string, string>  $fields
     * @return list<array{label: string, before: string, after: string}>
     */
    private static function fieldRows(array $snapshot, array $proposal, array $fields): array
    {
        $rows = [];
        foreach ($fields as $key => $label) {
            if (filled($proposal[$key] ?? null)) {
                $rows[] = ['label' => $label, 'before' => self::text($snapshot[$key] ?? null), 'after' => self::text($proposal[$key])];
            }
        }

        return $rows;
    }

    private static function text(mixed $value, string $fallback = self::NONE): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? $fallback : $text;
    }
}
