<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Apparatus;
use App\Models\ApparatusInventoryChange;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Effective checklist = baseline template + this apparatus's active approved inventory
 * changes, applied in id order (docs/apparatus-inventory-corrections.md section 2.3).
 *
 * Every operation here is a pure array transformation; nothing is persisted. With no
 * active change the baseline is returned untouched, so its checklist_version is unchanged.
 */
final class InventoryOverlay
{
    public const ITEM_FIELDS = ['name', 'expectedQuantity', 'manufacturer', 'model', 'description'];

    private const OPTIONAL_TEXT_FIELDS = ['manufacturer', 'model', 'description'];

    /**
     * @param  array<string, mixed>  $baseline  a schema-valid checklist
     * @param  Closure(array<string, mixed>): bool  $isSubmittable  the resolver's schema check
     * @return array{checklist: array<string, mixed>, change_ids: list<int>, conflicts: list<array{change_id: int, reason: string}>}
     */
    public function effectiveFor(Apparatus $apparatus, array $baseline, Closure $isSubmittable): array
    {
        if (! $apparatus->exists || $apparatus->getKey() === null) {
            return ['checklist' => $baseline, 'change_ids' => [], 'conflicts' => []];
        }

        $changes = ApparatusInventoryChange::query()
            ->where('apparatus_id', $apparatus->getKey())
            ->active()
            ->orderBy('id')
            ->get(['id', 'operation', 'item_key', 'equipment_key', 'payload']);

        return $this->applyChanges($baseline, $changes->all(), $isSubmittable, (int) $apparatus->getKey());
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @param  list<ApparatusInventoryChange>  $changes
     * @param  Closure(array<string, mixed>): bool  $isSubmittable
     * @return array{checklist: array<string, mixed>, change_ids: list<int>, conflicts: list<array{change_id: int, reason: string}>}
     */
    public function applyChanges(array $baseline, array $changes, Closure $isSubmittable, ?int $apparatusId = null): array
    {
        if ($changes === []) {
            return ['checklist' => $baseline, 'change_ids' => [], 'conflicts' => []];
        }

        $checklist = $this->withMaterializedItemIds($baseline);
        $applied = $conflicts = [];
        foreach ($changes as $change) {
            try {
                $candidate = $this->apply(
                    $checklist,
                    (string) $change->operation,
                    (string) $change->item_key,
                    (string) $change->equipment_key,
                    (array) $change->payload,
                );
                if (! $isSubmittable($candidate)) {
                    throw new InventoryOperationException('invalid_result');
                }
                $checklist = $candidate;
                $applied[] = (int) $change->id;
            } catch (InventoryOperationException $exception) {
                // A template changed under an approved change. The unit keeps a usable
                // checklist; the change is reported for Logistics instead of breaking checkout.
                $conflicts[] = ['change_id' => (int) $change->id, 'reason' => $exception->reason];
                Log::warning('Approved inventory change no longer applies to the checklist.', [
                    'apparatus_id' => $apparatusId,
                    'change_id' => $change->id,
                    'reason' => $exception->reason,
                ]);
            }
        }

        return ['checklist' => $checklist, 'change_ids' => $applied, 'conflicts' => $conflicts];
    }

    /**
     * Applies one operation and returns the new checklist (the input is not modified).
     *
     * @param  array<string, mixed>  $checklist
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(array $checklist, string $operation, string $itemKey, string $equipmentKey, array $payload): array
    {
        $checklist = $this->withMaterializedItemIds($checklist);

        return match ($operation) {
            ApparatusInventoryChange::OP_REMOVE => $this->remove($checklist, $itemKey),
            ApparatusInventoryChange::OP_MODIFY => $this->modify($checklist, $itemKey, $equipmentKey, $payload),
            ApparatusInventoryChange::OP_ADD => $this->add($checklist, $itemKey, $equipmentKey, $payload),
            default => throw new InventoryOperationException('unknown_operation'),
        };
    }

    /**
     * @param  array<string, mixed>  $checklist
     * @return array{compartment_index: int, item_index: int, compartment: array<string, mixed>, item: array<string, mixed>}|null
     */
    public function findItem(array $checklist, string $itemKey): ?array
    {
        foreach (array_values($checklist['compartments'] ?? []) as $compartmentIndex => $compartment) {
            foreach (array_values($compartment['items'] ?? []) as $itemIndex => $item) {
                if ($this->itemId($compartment, $item, $itemIndex) === $itemKey) {
                    return ['compartment_index' => $compartmentIndex, 'item_index' => $itemIndex, 'compartment' => $compartment, 'item' => $item];
                }
            }
        }

        return null;
    }

    /**
     * Items whose equipment identity equals $equipmentKey.
     *
     * @param  array<string, mixed>  $checklist
     * @return list<array{compartment: array<string, mixed>, item: array<string, mixed>}>
     */
    public function itemsWithEquipmentKey(array $checklist, string $equipmentKey): array
    {
        $matches = [];
        foreach ($checklist['compartments'] ?? [] as $compartment) {
            foreach ($compartment['items'] ?? [] as $item) {
                if (is_array($item) && EquipmentIdentity::forItem($item) === $equipmentKey) {
                    $matches[] = ['compartment' => $compartment, 'item' => $item];
                }
            }
        }

        return $matches;
    }

    /** @param array<string, mixed> $compartment */
    public static function compartmentName(array $compartment): string
    {
        return (string) ($compartment['name'] ?? $compartment['title'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $checklist
     * @return array<string, mixed>
     */
    private function remove(array $checklist, string $itemKey): array
    {
        $found = $this->findItem($checklist, $itemKey) ?? throw new InventoryOperationException('item_not_found');
        $compartments = array_values($checklist['compartments']);
        $items = array_values($compartments[$found['compartment_index']]['items']);
        array_splice($items, $found['item_index'], 1);
        $compartments[$found['compartment_index']]['items'] = $items;
        $checklist['compartments'] = $this->withoutEmptyCompartments($compartments);

        return $checklist;
    }

    /**
     * @param  array<string, mixed>  $checklist
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function modify(array $checklist, string $itemKey, string $equipmentKey, array $payload): array
    {
        $found = $this->findItem($checklist, $itemKey) ?? throw new InventoryOperationException('item_not_found');
        $item = $found['item'];
        $compartments = array_values($checklist['compartments']);

        // Identity survives renames and replacements: pin the key the change was planned with.
        $item['equipmentKey'] = $equipmentKey;
        if (array_key_exists('name', $payload)) {
            $name = $this->requiredText($payload['name'], 'invalid_name');
            if ($name !== $item['name']) {
                $item['legacyItemNames'] = $this->appendUnique($item['legacyItemNames'] ?? [], (string) $item['name']);
                $item['name'] = $name;
            }
        }
        if (array_key_exists('expectedQuantity', $payload)) {
            $quantity = $payload['expectedQuantity'];
            if ($quantity === null) {
                unset($item['expectedQuantity']);
            } elseif (is_int($quantity) && $quantity >= 1 && $quantity <= 999) {
                $item['expectedQuantity'] = $quantity;
            } else {
                throw new InventoryOperationException('invalid_quantity');
            }
        }
        foreach (self::OPTIONAL_TEXT_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $value = is_string($payload[$field]) ? trim($payload[$field]) : null;
                if ($value === null || $value === '') {
                    unset($item[$field]);
                } else {
                    $item[$field] = $value;
                }
            }
        }

        $destinationId = isset($payload['compartment_id']) ? (string) $payload['compartment_id'] : null;
        $sourceId = (string) $found['compartment']['id'];
        if ($destinationId === null || $destinationId === $sourceId) {
            $compartments[$found['compartment_index']]['items'][$found['item_index']] = $item;
            $checklist['compartments'] = $compartments;

            return $checklist;
        }

        $destinationIndex = $this->compartmentIndex($compartments, $destinationId) ?? throw new InventoryOperationException('destination_missing');
        $item['legacyCompartmentNames'] = $this->appendUnique($item['legacyCompartmentNames'] ?? [], self::compartmentName($found['compartment']));
        $sourceItems = array_values($compartments[$found['compartment_index']]['items']);
        array_splice($sourceItems, $found['item_index'], 1);
        $compartments[$found['compartment_index']]['items'] = $sourceItems;
        $compartments[$destinationIndex]['items'][] = $item;
        $checklist['compartments'] = $this->withoutEmptyCompartments($compartments);

        return $checklist;
    }

    /**
     * @param  array<string, mixed>  $checklist
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function add(array $checklist, string $itemKey, string $equipmentKey, array $payload): array
    {
        if ($this->findItem($checklist, $itemKey) !== null) {
            throw new InventoryOperationException('item_exists');
        }
        $compartments = array_values($checklist['compartments']);
        $index = $this->compartmentIndex($compartments, (string) ($payload['compartment_id'] ?? ''))
            ?? throw new InventoryOperationException('destination_missing');

        $item = ['id' => $itemKey, 'name' => $this->requiredText($payload['name'] ?? null, 'invalid_name'), 'inputType' => 'checkbox'];
        $quantity = $payload['expectedQuantity'] ?? null;
        if ($quantity !== null) {
            if (! is_int($quantity) || $quantity < 1 || $quantity > 999) {
                throw new InventoryOperationException('invalid_quantity');
            }
            $item['expectedQuantity'] = $quantity;
        }
        foreach (self::OPTIONAL_TEXT_FIELDS as $field) {
            $value = is_string($payload[$field] ?? null) ? trim($payload[$field]) : '';
            if ($value !== '') {
                $item[$field] = $value;
            }
        }
        $item['equipmentKey'] = $equipmentKey;
        $compartments[$index]['items'][] = $item;
        $checklist['compartments'] = $compartments;

        return $checklist;
    }

    /**
     * Positional fallback ids (InspectionPaperFields) would shift once an item moves, so
     * every item carries its id explicitly before any operation runs.
     *
     * @param  array<string, mixed>  $checklist
     * @return array<string, mixed>
     */
    private function withMaterializedItemIds(array $checklist): array
    {
        foreach ($checklist['compartments'] ?? [] as $compartmentIndex => $compartment) {
            foreach ($compartment['items'] ?? [] as $itemIndex => $item) {
                if (is_array($item) && ! isset($item['id'])) {
                    $checklist['compartments'][$compartmentIndex]['items'][$itemIndex]['id'] = $compartment['id'].'-item-'.($itemIndex + 1);
                }
            }
        }

        return $checklist;
    }

    /**
     * @param  array<string, mixed>  $compartment
     * @param  array<string, mixed>  $item
     */
    private function itemId(array $compartment, array $item, int $index): string
    {
        return (string) ($item['id'] ?? $compartment['id'].'-item-'.($index + 1));
    }

    /** @param list<array<string, mixed>> $compartments */
    private function compartmentIndex(array $compartments, string $compartmentId): ?int
    {
        foreach ($compartments as $index => $compartment) {
            if ((string) ($compartment['id'] ?? '') === $compartmentId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $compartments
     * @return list<array<string, mixed>>
     */
    private function withoutEmptyCompartments(array $compartments): array
    {
        return array_values(array_filter($compartments, static fn (array $compartment): bool => ($compartment['items'] ?? []) !== []));
    }

    private function requiredText(mixed $value, string $reason): string
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '' || mb_strlen($text) > 160) {
            throw new InventoryOperationException($reason);
        }

        return $text;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function appendUnique(array $values, string $value): array
    {
        $strings = array_values(array_filter($values, 'is_string'));
        if ($value !== '' && ! in_array($value, $strings, true)) {
            $strings[] = $value;
        }

        return $strings;
    }
}
