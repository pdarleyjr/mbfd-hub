<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInventoryChange;
use App\Models\ApparatusInventoryCorrection;
use App\Services\DailyCheckoutChecklistResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Impact preview of one inventory decision (docs/apparatus-inventory-corrections.md 2.4):
 * which apparatus it would change, how, and which are excluded and why. The same plan
 * is recomputed under row locks when the decision is applied.
 */
final class InventoryChangePlanner
{
    public const SCOPE_UNIT = 'unit';

    public const SCOPE_FAMILY = 'family';

    public const SCOPE_EQUIPMENT = 'equipment';

    /** The reporting apparatus plus every apparatus on the checkout lists Logistics picks. */
    public const SCOPE_CHECKLISTS = 'checklists';

    /** @var list<string> */
    public const SCOPES = [self::SCOPE_UNIT, self::SCOPE_FAMILY, self::SCOPE_EQUIPMENT, self::SCOPE_CHECKLISTS];

    public const ELIGIBLE = 'eligible';

    /** @var array<string, string> exclusion code => explanation for Logistics */
    public const VERDICTS = [
        self::ELIGIBLE => 'Will change',
        'no_change' => 'Already matches this decision',
        'item_not_found' => 'This item is not on this unit\'s checklist',
        'ambiguous_item' => 'More than one matching item on this unit; correct it separately',
        'destination_missing' => 'This unit has no matching compartment',
        'invalid_result' => 'Would leave two items with the same name in one compartment',
        'checklist_unavailable' => 'This unit has no usable Daily Checkout checklist',
        'unit_override' => 'Has its own approved change for this item. Include it to replace that change',
        'not_daily_checkout' => 'Not on Daily Checkout (reserve or unassigned)',
    ];

    /** @var list<string> exclusions Logistics may still choose to include */
    public const INCLUDABLE = [self::ELIGIBLE, 'unit_override', 'not_daily_checkout'];

    private const TEXT_LIMITS = ['manufacturer' => 120, 'model' => 120, 'description' => 500];

    public function __construct(
        private readonly DailyCheckoutChecklistResolver $resolver,
        private readonly InventoryOverlay $overlay,
    ) {}

    /**
     * @param  array{op?: mixed, fields?: mixed}  $operation
     * @return array{
     *     scope: string,
     *     operation: array{op: string, fields: array<string, mixed>},
     *     item_key: string,
     *     equipment_key: string|null,
     *     source_apparatus_id: int,
     *     checklist_types: list<string>,
     *     units: list<array<string, mixed>>,
     *     fingerprint: string
     * }
     */
    public function plan(ApparatusInventoryCorrection $correction, array $operation, string $scope, bool $lock = false, array $checklistTypes = []): array
    {
        if (! in_array($scope, self::SCOPES, true)) {
            throw ValidationException::withMessages(['scope' => 'Choose which apparatus this decision applies to.']);
        }
        $checklistTypes = $this->normalizeChecklistTypes($scope, $checklistTypes);
        $operation = $this->normalizeOperation($correction, $operation);
        if ($operation['op'] === ApparatusInventoryChange::OP_ADD && $scope === self::SCOPE_EQUIPMENT) {
            throw ValidationException::withMessages(['scope' => 'A newly listed item is not carried anywhere yet. Apply it to this apparatus or to its family.']);
        }

        $sourceId = (int) $correction->apparatus_id;
        $itemKey = $operation['op'] === ApparatusInventoryChange::OP_ADD
            ? EquipmentIdentity::addedItemKey((int) $correction->getKey())
            : (string) $correction->item_key;
        $candidates = $this->candidates($sourceId, $scope, $lock, $checklistTypes);
        $source = $candidates->firstWhere('id', $sourceId);
        $sourceResolution = $source instanceof Apparatus ? $this->resolver->resolve($source) : null;
        $equipmentKey = $this->equipmentKey($correction, $operation, $itemKey, $sourceResolution);
        $sourceDestination = $this->sourceDestination($operation, $sourceResolution);

        $units = [];
        foreach ($candidates as $apparatus) {
            $resolution = $apparatus->getKey() === $sourceId ? $sourceResolution : $this->resolver->resolve($apparatus);
            $unit = $this->unitPlan($apparatus, $resolution, $operation, $itemKey, $equipmentKey, $sourceDestination, $apparatus->getKey() === $sourceId, $sourceResolution['checklist_type'] ?? null);
            if ($scope === self::SCOPE_EQUIPMENT && ! $unit['is_source'] && $unit['verdict'] === 'item_not_found') {
                continue; // "every apparatus carrying this item" lists only carriers
            }
            $units[] = $unit;
        }

        $plan = [
            'scope' => $scope,
            'operation' => $operation,
            'item_key' => $itemKey,
            'equipment_key' => $equipmentKey,
            'source_apparatus_id' => $sourceId,
            'checklist_types' => $checklistTypes,
            'units' => $units,
        ];
        $plan['fingerprint'] = $this->fingerprint($plan);

        return $plan;
    }

    /**
     * Validates and normalizes Logistics' decision. Field keys absent = unchanged.
     *
     * @param  array{op?: mixed, fields?: mixed}  $operation
     * @return array{op: string, fields: array<string, mixed>}
     */
    public function normalizeOperation(ApparatusInventoryCorrection $correction, array $operation): array
    {
        $op = is_string($operation['op'] ?? null) ? $operation['op'] : '';
        $isNew = blank($correction->item_key);
        $allowed = $isNew ? [ApparatusInventoryChange::OP_ADD] : [ApparatusInventoryChange::OP_MODIFY, ApparatusInventoryChange::OP_REMOVE];
        if (! in_array($op, $allowed, true)) {
            throw ValidationException::withMessages(['operation' => $isNew
                ? 'A report of an unlisted item can only add that item.'
                : 'Choose whether to change or remove this item.']);
        }
        $raw = is_array($operation['fields'] ?? null) ? $operation['fields'] : [];
        if ($op === ApparatusInventoryChange::OP_REMOVE) {
            return ['op' => $op, 'fields' => []];
        }

        $fields = [];
        foreach (['compartment_id', 'name', 'expectedQuantity', 'manufacturer', 'model', 'description'] as $key) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }
            $value = $raw[$key];
            $fields[$key] = match ($key) {
                'compartment_id' => $this->compartmentId($value),
                'name' => $this->name($value),
                'expectedQuantity' => $this->quantity($value),
                default => $this->optionalText($key, $value),
            };
        }
        if ($op === ApparatusInventoryChange::OP_ADD) {
            $fields['compartment_id'] ??= $correction->compartment_key;
            if (! is_string($fields['name'] ?? null) || ! is_string($fields['compartment_id'] ?? null)) {
                throw ValidationException::withMessages(['operation' => 'Give the new item a name and a compartment.']);
            }
        } elseif (array_key_exists('compartment_id', $fields) && ! isset($fields['compartment_id'])) {
            unset($fields['compartment_id']); // "no move"
        }
        if ($op === ApparatusInventoryChange::OP_MODIFY && $fields === []) {
            throw ValidationException::withMessages(['operation' => 'Choose at least one thing to change.']);
        }

        return ['op' => $op, 'fields' => $fields];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function fingerprint(array $plan): string
    {
        $units = array_map(static fn (array $unit): array => [
            $unit['apparatus_id'], $unit['checklist_version'], $unit['verdict'], $unit['target_item_key'], $unit['destination_compartment_id'],
        ], $plan['units']);

        return hash('sha256', json_encode([$plan['scope'], $plan['operation'], $plan['item_key'], $plan['equipment_key'], $plan['checklist_types'] ?? [], $units], JSON_THROW_ON_ERROR));
    }

    /**
     * Every checkout list that at least one apparatus uses today, with the apparatus on it, for the
     * "selected checkout lists" choice. Lists with no usable mapping (pending, unmapped) are left out:
     * nothing can be changed on them.
     *
     * @return list<array{type: string, label: string, units: list<array{id: int, label: string, daily_checkout: bool}>}>
     */
    public function checklistUsage(): array
    {
        $labels = DailyCheckoutChecklistTemplate::options();
        $byType = [];
        foreach (Apparatus::query()->orderBy('id')->get() as $apparatus) {
            $type = $this->resolver->checklistTypeFor($apparatus);
            if ($type === '' || in_array($type, ['unmapped', DailyCheckoutChecklistTemplate::Pending->value], true)) {
                continue;
            }
            $byType[$type][] = ['id' => (int) $apparatus->getKey(), 'label' => $this->unitLabel($apparatus), 'daily_checkout' => $apparatus->isDailyCheckoutRequired()];
        }
        ksort($byType);

        return array_map(
            static fn (string $type, array $units): array => ['type' => $type, 'label' => $labels[$type] ?? Str::headline($type), 'units' => $units],
            array_keys($byType),
            $byType,
        );
    }

    /**
     * Only meaningful for the checkout-lists scope, where it must name at least one list that exists.
     *
     * @param  array<int|string, mixed>  $types
     * @return list<string>
     */
    private function normalizeChecklistTypes(string $scope, array $types): array
    {
        if ($scope !== self::SCOPE_CHECKLISTS) {
            return [];
        }
        $known = array_column($this->checklistUsage(), 'type');
        $picked = array_values(array_unique(array_filter(array_map(static fn (mixed $type): string => is_string($type) ? $type : '', $types), static fn (string $type): bool => $type !== '')));
        if ($picked === []) {
            throw ValidationException::withMessages(['checklist_types' => 'Choose at least one checkout list.']);
        }
        if (array_diff($picked, $known) !== []) {
            throw ValidationException::withMessages(['checklist_types' => 'One of the chosen checkout lists is not in use. Choose again.']);
        }
        sort($picked);

        return $picked;
    }

    /**
     * @param  list<string>  $checklistTypes
     * @return Collection<int, Apparatus>
     */
    private function candidates(int $sourceId, string $scope, bool $lock, array $checklistTypes = []): Collection
    {
        $query = Apparatus::query()->orderBy('id');
        if ($scope === self::SCOPE_UNIT) {
            $query->whereKey($sourceId);
        }
        $all = $query->get();
        if ($scope === self::SCOPE_CHECKLISTS) {
            $all = $all->filter(fn (Apparatus $apparatus): bool => $apparatus->getKey() === $sourceId
                || in_array($this->resolver->checklistTypeFor($apparatus), $checklistTypes, true))->values();
        }
        if ($scope === self::SCOPE_FAMILY) {
            $source = $all->firstWhere('id', $sourceId);
            $family = $source instanceof Apparatus
                ? DailyCheckoutChecklistResolver::familyForChecklistType($this->resolver->checklistTypeFor($source))
                : null;
            $all = $all->filter(fn (Apparatus $apparatus): bool => $apparatus->getKey() === $sourceId
                || ($family !== null && DailyCheckoutChecklistResolver::familyForChecklistType($this->resolver->checklistTypeFor($apparatus)) === $family))->values();
        }
        if (! $lock) {
            return $all;
        }

        // Lock only the candidates, in ascending id order (the order inspection submissions use).
        return Apparatus::query()->whereKey($all->modelKeys())->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * @param  array{op: string, fields: array<string, mixed>}  $operation
     * @param  array<string, mixed>|null  $resolution
     */
    private function equipmentKey(ApparatusInventoryCorrection $correction, array $operation, string $itemKey, ?array $resolution): ?string
    {
        if ($operation['op'] === ApparatusInventoryChange::OP_ADD) {
            return EquipmentIdentity::forAddedItem((int) $correction->getKey());
        }
        $found = is_array($resolution['checklist'] ?? null) ? $this->overlay->findItem($resolution['checklist'], $itemKey) : null;
        if ($found !== null) {
            return EquipmentIdentity::forItem($found['item']);
        }
        $snapshot = $correction->equipment_key;

        return is_string($snapshot) && $snapshot !== '' ? $snapshot : null;
    }

    /**
     * The destination compartment on the reporting unit, so other units can be matched to it.
     *
     * @param  array{op: string, fields: array<string, mixed>}  $operation
     * @param  array<string, mixed>|null  $resolution
     * @return array{id: string, name: string}|null
     */
    private function sourceDestination(array $operation, ?array $resolution): ?array
    {
        $id = $operation['fields']['compartment_id'] ?? null;
        if (! is_string($id)) {
            return null;
        }
        foreach ($resolution['checklist']['compartments'] ?? [] as $compartment) {
            if ((string) ($compartment['id'] ?? '') === $id) {
                return ['id' => $id, 'name' => InventoryOverlay::compartmentName($compartment)];
            }
        }

        return ['id' => $id, 'name' => ''];
    }

    /**
     * @param  array<string, mixed>|null  $resolution
     * @param  array{op: string, fields: array<string, mixed>}  $operation
     * @param  array{id: string, name: string}|null  $sourceDestination
     * @return array<string, mixed>
     */
    private function unitPlan(
        Apparatus $apparatus,
        ?array $resolution,
        array $operation,
        string $itemKey,
        ?string $equipmentKey,
        ?array $sourceDestination,
        bool $isSource,
        ?string $sourceChecklistType,
    ): array {
        $unit = [
            'apparatus_id' => (int) $apparatus->getKey(),
            'label' => $this->unitLabel($apparatus),
            'checklist_type' => $resolution['checklist_type'] ?? null,
            'daily_checkout' => $apparatus->isDailyCheckoutRequired(),
            'is_source' => $isSource,
            'checklist_version' => $resolution['checklist_version'] ?? null,
            'target_item_key' => null,
            'destination_compartment_id' => null,
            'before' => null,
            'after' => null,
            'overriding_change_ids' => [],
            'open_issues' => 0,
        ];
        $sameTemplate = $sourceChecklistType !== null && ($resolution['checklist_type'] ?? null) === $sourceChecklistType;
        $verdict = $this->evaluate($unit, $apparatus, $resolution, $operation, $itemKey, $equipmentKey, $sourceDestination, $isSource, $sameTemplate);

        return [
            ...$unit,
            'verdict' => $verdict,
            'verdict_label' => self::VERDICTS[$verdict] ?? $verdict,
            'includable' => in_array($verdict, self::INCLUDABLE, true),
            'default_selected' => $verdict === self::ELIGIBLE,
        ];
    }

    private function unitLabel(Apparatus $apparatus): string
    {
        return trim((string) ($apparatus->designation ?: $apparatus->name ?: $apparatus->getAttribute('unit_id'))) ?: 'Apparatus #'.$apparatus->getKey();
    }

    /**
     * Fills the unit's match and returns its verdict.
     *
     * @param  array<string, mixed>  $unit
     * @param  array<string, mixed>|null  $resolution
     * @param  array{op: string, fields: array<string, mixed>}  $operation
     * @param  array{id: string, name: string}|null  $sourceDestination
     */
    private function evaluate(
        array &$unit,
        Apparatus $apparatus,
        ?array $resolution,
        array $operation,
        string $itemKey,
        ?string $equipmentKey,
        ?array $sourceDestination,
        bool $isSource,
        bool $sameTemplate,
    ): string {
        if (($resolution['usable'] ?? false) !== true || ! is_array($resolution['checklist'] ?? null)) {
            return 'checklist_unavailable';
        }
        $checklist = $resolution['checklist'];
        $fields = $operation['fields'];

        if ($operation['op'] === ApparatusInventoryChange::OP_ADD) {
            if ($this->overlay->findItem($checklist, $itemKey) !== null) {
                return 'no_change';
            }
            $target = $itemKey;
        } else {
            $target = $this->matchItem($checklist, $itemKey, $equipmentKey, $isSource, $sameTemplate);
            if (! is_string($target)) {
                return $target === null ? 'item_not_found' : 'ambiguous_item';
            }
            $unit['before'] = $this->describe($checklist, $target);
            if ($operation['op'] === ApparatusInventoryChange::OP_REMOVE) {
                // Open issues stay open (and visible to Logistics) when the item leaves the checklist.
                $unit['open_issues'] = ApparatusDefect::query()
                    ->where('apparatus_id', $apparatus->getKey())
                    ->where('resolved', false)
                    ->where(fn ($query) => $query->where('item_key', $target)->orWhere('item', $unit['before']['name'] ?? ''))
                    ->count();
            }
        }
        $unit['target_item_key'] = $target;

        if (array_key_exists('compartment_id', $fields)) {
            $destination = $isSource ? $fields['compartment_id'] : $this->matchCompartment($checklist, $sourceDestination, $sameTemplate);
            if ($destination === null || ! $this->hasCompartment($checklist, (string) $destination)) {
                return 'destination_missing';
            }
            $fields['compartment_id'] = $destination;
            $unit['destination_compartment_id'] = $destination;
        }

        try {
            $result = $this->overlay->apply($checklist, $operation['op'], $target, (string) $equipmentKey, $fields);
        } catch (InventoryOperationException $exception) {
            return in_array($exception->reason, ['item_not_found', 'destination_missing'], true) ? $exception->reason : 'invalid_result';
        }
        if (! $this->resolver->isSubmittable($result)) {
            return 'invalid_result';
        }
        $unit['after'] = $operation['op'] === ApparatusInventoryChange::OP_REMOVE ? null : $this->describe($result, $target);
        if ($operation['op'] !== ApparatusInventoryChange::OP_REMOVE && $unit['after'] == $unit['before']) {
            return 'no_change';
        }

        if (! $isSource) {
            $overrides = ApparatusInventoryChange::query()
                ->where('apparatus_id', $apparatus->getKey())
                ->where('item_key', $target)
                ->where('scope', self::SCOPE_UNIT)
                ->active()
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            if ($overrides !== []) {
                $unit['overriding_change_ids'] = $overrides;

                return 'unit_override';
            }
            if (! $apparatus->isDailyCheckoutRequired()) {
                return 'not_daily_checkout';
            }
        }

        return self::ELIGIBLE;
    }

    /**
     * The item this decision means on one unit. Returns the item id, null (not carried) or
     * false (ambiguous). On the same template the item id is authoritative (with the same
     * identity); on a variant template ids are positional, so only a single item with the
     * same identity counts.
     *
     * @param  array<string, mixed>  $checklist
     */
    private function matchItem(array $checklist, string $itemKey, ?string $equipmentKey, bool $isSource, bool $sameTemplate): string|false|null
    {
        $byKey = $this->overlay->findItem($checklist, $itemKey);
        if ($isSource) {
            return $byKey !== null ? $itemKey : null;
        }
        if ($equipmentKey === null) {
            return null;
        }
        if ($sameTemplate && $byKey !== null && EquipmentIdentity::forItem($byKey['item']) === $equipmentKey) {
            return $itemKey;
        }
        $matches = $this->overlay->itemsWithEquipmentKey($checklist, $equipmentKey);

        return match (count($matches)) {
            0 => null,
            1 => (string) $matches[0]['item']['id'],
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $checklist
     * @param  array{id: string, name: string}|null  $sourceDestination
     */
    private function matchCompartment(array $checklist, ?array $sourceDestination, bool $sameTemplate): ?string
    {
        if ($sourceDestination === null) {
            return null;
        }
        // Compartment ids are only comparable on the same template: Ladder 1 and Ladder 3 reuse
        // b_comp_N for different positions. On a variant only a unique name match counts.
        if ($sameTemplate && $this->hasCompartment($checklist, $sourceDestination['id'])) {
            return $sourceDestination['id'];
        }
        $name = EquipmentIdentity::normalize($sourceDestination['name']);
        $matches = array_values(array_filter(
            $checklist['compartments'] ?? [],
            static fn (array $compartment): bool => $name !== '' && EquipmentIdentity::normalize(InventoryOverlay::compartmentName($compartment)) === $name,
        ));

        return count($matches) === 1 ? (string) $matches[0]['id'] : null;
    }

    /** @param array<string, mixed> $checklist */
    private function hasCompartment(array $checklist, string $compartmentId): bool
    {
        foreach ($checklist['compartments'] ?? [] as $compartment) {
            if ((string) ($compartment['id'] ?? '') === $compartmentId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $checklist
     * @return array<string, mixed>|null
     */
    public function describe(array $checklist, string $itemKey): ?array
    {
        $found = $this->overlay->findItem($checklist, $itemKey);
        if ($found === null) {
            return null;
        }
        $item = $found['item'];

        return [
            'compartment_id' => (string) $found['compartment']['id'],
            'compartment' => InventoryOverlay::compartmentName($found['compartment']),
            'name' => (string) $item['name'],
            'expectedQuantity' => $item['expectedQuantity'] ?? null,
            'manufacturer' => $item['manufacturer'] ?? null,
            'model' => $item['model'] ?? null,
            'description' => $item['description'] ?? null,
        ];
    }

    private function compartmentId(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || mb_strlen($value) > 128) {
            throw ValidationException::withMessages(['operation' => 'Choose a compartment from this apparatus.']);
        }

        return $value;
    }

    private function name(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';
        if ($name === '' || mb_strlen($name) > 160 || preg_match('/[<>]/', $name) === 1) {
            throw ValidationException::withMessages(['operation' => 'An item name is required (at most 160 characters).']);
        }

        return $name;
    }

    private function quantity(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < 1 || $value > 999) {
            throw ValidationException::withMessages(['operation' => 'Expected quantity must be a whole number from 1 to 999.']);
        }

        return $value;
    }

    private function optionalText(string $key, mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';
        if (mb_strlen($text) > self::TEXT_LIMITS[$key] || preg_match('/[<>]/', $text) === 1) {
            throw ValidationException::withMessages(['operation' => ucfirst($key).' is too long.']);
        }

        return $text === '' ? null : $text;
    }
}
