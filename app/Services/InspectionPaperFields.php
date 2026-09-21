<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Validates write-in answers against the exact issued paper checklist. */
final class InspectionPaperFields
{
    /** Server-owned aliases retain defect continuity when the paper relocates or renames an item. */
    public function equipmentDefinitions(array $checklist): array
    {
        $definitions = [];
        foreach ($checklist['compartments'] ?? [] as $compartment) {
            foreach ($compartment['items'] ?? [] as $index => $item) {
                $id = $item['id'] ?? $compartment['id'].'-item-'.($index + 1);
                $definitions[$compartment['id']][$id] = [
                    'compartment' => $compartment['name'] ?? $compartment['title'],
                    'item' => $item['name'],
                    'compartment_names' => array_values(array_unique([$compartment['name'] ?? $compartment['title'], ...($item['legacyCompartmentNames'] ?? [])])),
                    'item_names' => array_values(array_unique([$item['name'], ...($item['legacyItemNames'] ?? [])])),
                ];
            }
        }

        return $definitions;
    }

    public function matchingEquipment(array $definitions, string $compartment, string $item): array
    {
        return collect($definitions)->flatten(1)->filter(static fn (array $definition): bool => in_array($compartment, $definition['compartment_names'], true)
            && in_array($item, $definition['item_names'], true)
        )->values()->all();
    }

    public function validate(array $submission, array $checklist): void
    {
        $definitions = $checklist['fields'] ?? $checklist['officerChecklist'] ?? [];
        $expected = array_column($definitions, null, 'id');
        $seen = [];
        foreach ($submission['field_values'] ?? [] as $answer) {
            $id = $answer['id'];
            $definition = $expected[$id] ?? null;
            if ($definition === null || isset($seen[$id]) || ! $this->validValue($definition, $answer['value'])) {
                throw ValidationException::withMessages(['field_values' => 'Each paper field must match its issued identifier and value type.']);
            }
            $seen[$id] = true;
        }
        if (array_diff_key($expected, $seen) !== []) {
            throw ValidationException::withMessages(['field_values' => 'Include every paper field, using an empty value for optional entries.']);
        }

        $compartments = array_column($checklist['compartments'], null, 'id');
        foreach ($submission['compartments'] as $compartment) {
            $items = [];
            foreach ($compartments[$compartment['id']]['items'] ?? [] as $index => $definition) {
                $items[$definition['id'] ?? $compartment['id'].'-item-'.($index + 1)] = $definition;
            }
            $seenItems = [];
            foreach ($compartment['items'] as $item) {
                $definition = $items[$item['id'] ?? ''] ?? null;
                if ($definition === null || $definition['name'] !== ($item['name'] ?? null) || isset($seenItems[$item['id']]) || ($item['observed'] ?? false) !== true) {
                    throw ValidationException::withMessages(['compartments' => 'Every issued item requires an explicit member observation.']);
                }
                $seenItems[$item['id']] = true;
                if (($definition['inputType'] ?? 'checkbox') !== 'checkbox') {
                    $definition['required'] = $item['status'] === 'Present' && ($definition['valueRequired'] ?? true);
                    if (! $this->validValue($definition, $item['value'] ?? null)) {
                        throw ValidationException::withMessages(['compartments' => 'Record the requested reading or identifier for each present write-in item.']);
                    }
                } elseif (array_key_exists('value', $item)) {
                    throw ValidationException::withMessages(['compartments' => 'This equipment item does not accept a write-in value.']);
                }
            }
        }
        foreach ($submission['scheduled_tasks'] ?? [] as $task) {
            if (($task['observed'] ?? false) !== true) {
                throw ValidationException::withMessages(['scheduled_tasks' => 'Confirm each scheduled duty before submitting.']);
            }
        }
    }

    public function snapshot(array $submission, array $checklist): array
    {
        $definitions = array_column($checklist['fields'] ?? $checklist['officerChecklist'] ?? [], null, 'id');

        return array_map(static fn (array $answer): array => [
            'id' => $answer['id'],
            'name' => $definitions[$answer['id']]['name'],
            'input_type' => $definitions[$answer['id']]['inputType'],
            'value' => $answer['value'],
        ], $submission['field_values'] ?? []);
    }

    private function validValue(array $definition, mixed $value): bool
    {
        if ($value === null || $value === '') {
            return ! ($definition['required'] ?? false);
        }

        return match ($definition['inputType'] ?? null) {
            'text' => is_string($value) && mb_strlen($value) <= 2000 && trim($value) !== '',
            'date' => is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $date) === 1 && checkdate((int) $date[2], (int) $date[3], (int) $date[1]),
            'number', 'percentage' => (is_int($value) || is_float($value)) && is_finite((float) $value)
                && abs($value) < 1000000000 && (($definition['inputType'] !== 'percentage') || ($value >= 0 && $value <= 100)),
            'checkbox' => is_bool($value),
            default => false,
        };
    }
}
