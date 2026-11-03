<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\CatalogItem;
use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\InventorySnapshotLine;
use App\Models\Ems\StorageLocation;
use App\Models\Ems\SupplyItem;
use App\Models\Ems\SupplyItemEvent;
use App\Models\Ems\SupplyOrder;
use App\Models\Ems\SupplyOrderEvent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The Master Supply Room Inventory (docs/ems/captain5-medical-inventory-workflow.md sections 4 and 6).
 * One read of the whole room, a few hundred rows, so shelf paging and search happen on the device.
 * Renames and moves keep the item's id and catalog identity, so history and form links stay attached.
 */
final class MasterInventoryService
{
    private const EDIT_REASON = 'Edited on the Captain 5 page';

    /** Stored order status => what the master list calls it. */
    public const ORDER_STATES = [SupplyOrder::PENDING => 'pending', SupplyOrder::PLACED => 'ordered', SupplyOrder::BACKORDERED => 'backordered'];

    public const SECTION_LABELS = [
        'supplies' => 'Supplies',
        'supplies-after-unlabeled-band' => 'Supplies',
        'medications' => 'Medications',
        'stored-on-racks' => 'Stored on racks',
        'accessories-grey-cabinet' => 'Accessories (grey cabinet)',
        'stored-with-captain-5' => 'Stored with Captain 5',
        'narcotics-500-501' => 'Narcotics',
    ];

    public function __construct(
        private readonly Idempotency $idempotency,
        private readonly StockLedger $ledger,
        private readonly EmsAccess $access,
        private readonly InventoryCountService $counts,
        private readonly CountFormService $countForm,
    ) {}

    /** @return array<string, mixed> */
    public function read(User $user): array
    {
        $locations = StorageLocation::query()->orderBy('sort')->orderBy('code')->get();
        $items = $this->tracked()->get();
        $rows = $this->present($items);

        $draft = $this->countForm->openDraft();
        $lastCount = InventorySnapshot::query()->where('kind', InventorySnapshot::KIND_COUNT)->where('status', InventorySnapshot::FINAL)
            ->where(fn ($query) => $query->whereNull('origin')->orWhere('origin', InventorySnapshot::ORIGIN_COUNT_FORM))
            ->orderByDesc('version')->first();

        $states = array_count_values(array_column($rows, 'order_state'));
        $levels = array_count_values(array_filter(array_column($rows, 'level')));

        return [
            'sections' => $this->sections($locations, $items),
            'items' => $rows,
            'summary' => [
                'needs_order' => $states['needs_order'] ?? 0, 'pending' => $states['pending'] ?? 0,
                'ordered' => $states['ordered'] ?? 0, 'backordered' => $states['backordered'] ?? 0,
                'red' => $levels[StockLevel::RED] ?? 0, 'yellow' => $levels[StockLevel::YELLOW] ?? 0, 'green' => $levels[StockLevel::GREEN] ?? 0,
            ],
            'units' => CountMath::units(),
            'inactive' => SupplyItem::query()->with('location:id,code')->where('active', false)->where('controlled_substance', false)->orderBy('name')->get()
                ->map(fn (SupplyItem $item): array => ['id' => $item->getKey(), 'name' => $item->name, 'location_code' => $item->location?->code])->all(),
            'draft' => $draft === null ? null : [
                'id' => $draft->uuid, 'started_at' => $draft->started_at->toIso8601String(),
                'started_by' => $draft->started_by_user_id !== null ? User::query()->whereKey($draft->started_by_user_id)->value('name') : null,
                'counted' => $draft->lines()->whereNotNull('quantity_base')->count(),
            ],
            'last_count' => $lastCount === null ? null : ['id' => $lastCount->uuid, 'finalized_at' => $lastCount->finalized_at?->toIso8601String(), 'finalized_name' => $lastCount->finalized_name],
            'can_edit' => $this->access->canEditInventory($user),
        ];
    }

    /**
     * @param  array<string, mixed>  $input  {name, location_code?, new_location_label?, count_unit, units_per_package?, threshold?, on_hand?}
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function create(User $user, string $key, array $input): array
    {
        $this->authorize($user);
        $data = EmsValidator::validate($input, $this->rules() + [
            'on_hand' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ], $this->messages());
        if (trim((string) ($data['name'] ?? '')) === '') {
            throw new EmsException('Give the item a name.', 'name_required', 422, ['name' => ['Give the item a name.']]);
        }

        return $this->idempotency->run($user, 'ems.master.create', $key, function () use ($user, $data): array {
            $this->ledger->lock();
            $actor = EmsActor::forUser($user, 'captain5');
            $name = trim((string) $data['name']);
            $location = $this->resolveLocation($data);
            $duplicate = SupplyItem::query()->where('active', true)->where('location_id', $location?->getKey())->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists();
            if ($duplicate) {
                throw EmsException::conflict("\"{$name}\" is already on that shelf.", 'duplicate_item');
            }
            $unit = (string) ($data['count_unit'] ?? 'each');
            $perPackage = isset($data['units_per_package']) ? (int) $data['units_per_package'] : null;
            $this->assertConvertible($unit, $perPackage);

            $catalog = CatalogItem::query()->create(['key' => 'captain5-added-'.Str::lower((string) Str::uuid()), 'name' => $name, 'active' => true]);
            $item = SupplyItem::query()->create([
                'catalog_item_id' => $catalog->getKey(), 'location_id' => $location?->getKey(), 'name' => $name, 'base_unit' => 'each', 'count_unit' => $unit,
                'package_unit' => $unit === 'each' ? null : $unit, 'units_per_package' => $unit === 'each' ? null : $perPackage, 'active' => true,
            ]);
            if (array_key_exists('threshold', $data) && $data['threshold'] !== null) {
                $item->update($this->thresholdColumns($item, (int) $data['threshold']));
            }
            $this->audit($item, 'created', null, $name, $actor);
            if (isset($data['on_hand'])) {
                $this->counts->recordDirectCount($item, (int) $data['on_hand'], $user);
            }

            return $this->one($item);
        });
    }

    /**
     * @param  array<string, mixed>  $input  any of {name, location_code, new_location_label, count_unit, units_per_package, threshold, active}
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function update(int $itemId, User $user, string $key, array $input): array
    {
        $this->authorize($user);
        $data = EmsValidator::validate($input, $this->rules() + ['active' => ['sometimes', 'boolean']], $this->messages());

        return $this->idempotency->run($user, "ems.master.update.{$itemId}", $key, function () use ($itemId, $user, $data): array {
            $this->ledger->lock();
            $item = $this->lockedItem($itemId);
            $this->applyFields($item, $data, EmsActor::forUser($user, 'captain5'));

            return $this->one($item);
        });
    }

    /**
     * The Edit mode save: several items at once, all or nothing. `expected` is the expected on hand in the item's
     * count unit (as changed in the same row); `on_hand` is a physical count of that item.
     *
     * @param  array<string, mixed>  $input  {changes: [{id, name?, location_code?, new_location_label?, count_unit?, units_per_package?, expected?, on_hand?}]}
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function batch(User $user, string $key, array $input): array
    {
        $this->authorize($user);
        $rules = ['changes' => ['required', 'array', 'min:1', 'max:500'], 'changes.*.id' => ['required', 'integer', 'distinct']];
        foreach ($this->rules() + ['expected' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'], 'on_hand' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999']] as $field => $fieldRules) {
            $rules["changes.*.{$field}"] = $fieldRules;
        }
        $messages = ['changes.required' => 'There is nothing to save.', 'changes.*.id.distinct' => 'An item appears twice in this save.'];
        foreach ($this->messages() + ['expected.integer' => 'Enter a whole number.', 'expected.min' => 'The expected amount cannot be negative.', 'on_hand.integer' => 'Enter a whole number.', 'on_hand.min' => 'A count cannot be negative.'] as $rule => $message) {
            $messages["changes.*.{$rule}"] = $message;
        }
        $data = EmsValidator::validate($input, $rules, $messages);

        return $this->idempotency->run($user, 'ems.master.batch', $key, function () use ($user, $data): array {
            $this->ledger->lock();
            $actor = EmsActor::forUser($user, 'captain5');
            $ids = [];
            foreach (array_values($data['changes']) as $index => $row) {
                try {
                    $item = $this->lockedItem((int) $row['id']);
                    $fields = array_diff_key($row, array_flip(['id', 'expected', 'on_hand']));
                    if (array_key_exists('expected', $row)) {
                        $fields['threshold'] = $row['expected'];
                    }
                    $this->applyFields($item, $fields, $actor);
                    if (($row['on_hand'] ?? null) !== null) {
                        $this->counts->recordDirectCount($item, (int) $row['on_hand'], $user);
                    }
                    $ids[] = (int) $item->getKey();
                } catch (EmsException $exception) {
                    throw $this->forRow($exception, $index, $row);
                }
            }
            $rows = collect($this->present(SupplyItem::query()->with(['location:id,code,label', 'catalogItem:id,name,aliases'])->whereIn('id', $ids)->get()))->keyBy('id');

            return ['items' => array_map(fn (int $id): array => $rows->get($id), $ids)];
        });
    }

    /** One item as the master list shows it, for callers that changed something about it. */
    public function row(int $itemId): array
    {
        return $this->one(SupplyItem::query()->findOrFail($itemId));
    }

    private function lockedItem(int $itemId): SupplyItem
    {
        $item = SupplyItem::query()->whereKey($itemId)->lockForUpdate()->first() ?? throw EmsException::notFound('supply item');
        if ($item->controlled_substance) {
            throw new EmsException('Controlled substances are handled through the narcotics procedure, not here.', 'controlled_not_tracked', 422);
        }

        return $item;
    }

    /** @param array<string, mixed> $data */
    private function applyFields(SupplyItem $item, array $data, EmsActor $actor): void
    {
        foreach ($this->changes($item, $data) as $field => $after) {
            $before = $item->getAttribute($field);
            if ($before === $after || ($before !== null && $after !== null && $before == $after)) {
                continue;
            }
            $this->audit($item, $field, $before, $after, $actor);
            $item->setAttribute($field, $after);
        }
        $resized = $item->isDirty(['count_unit', 'units_per_package']);
        $item->save();
        if ($resized) {
            $this->resizeOpenOrder($item, $actor);
        }
    }

    /**
     * An open order is kept in the item's count unit, so when that unit or its size changes the order follows:
     * the same number of the same package at its new size, or the same single units in a different unit.
     * Otherwise "Mark arrived" from the admin Reorder tab would add the old amount.
     */
    private function resizeOpenOrder(SupplyItem $item, EmsActor $actor): void
    {
        $order = SupplyOrder::query()->where('supply_item_id', $item->getKey())->whereIn('status', SupplyOrder::OPEN)->lockForUpdate()->first();
        if ($order === null || ($item->count_unit !== 'each' && (int) $item->units_per_package < 1)) {
            return;
        }
        $per = CountMath::perCountUnit($item);
        $packages = $order->order_unit === $item->count_unit || $order->quantity_base === null
            ? $order->packages
            : max(1, (int) ceil($order->quantity_base / $per));
        $base = $packages * $per;
        StockLedger::assertWithinLimit($base);
        if ($packages === $order->packages && $base === $order->quantity_base && $order->order_unit === $item->count_unit) {
            return;
        }
        $order->update(['packages' => $packages, 'quantity_base' => $base, 'order_unit' => $item->count_unit]);
        SupplyOrderEvent::query()->create([
            'supply_order_id' => $order->getKey(), 'from_status' => $order->status, 'to_status' => $order->status, 'quantity_base' => $base,
            'actor_user_id' => $actor->userId, 'actor_name' => $actor->name, 'note' => 'The item\'s unit or size changed, so the order was restated as '.$packages.' '.CountMath::label($item->count_unit, $packages).'.', 'created_at' => now(),
        ]);
    }

    /** A refusal about one row of a batch, with its field errors keyed the way the form sent them. */
    /** @param array<string, mixed> $row */
    private function forRow(EmsException $exception, int $index, array $row = []): EmsException
    {
        $fields = [];
        foreach ($exception->fields as $field => $messages) {
            $fields["changes.{$index}.".($field === 'threshold' ? 'expected' : $field)] = $messages;
        }
        if ($fields === []) {
            // Refusals that carry no field name the box the person would change to fix them.
            $field = match ($exception->errorCode) {
                'quantity_too_large' => $row === [] || array_key_exists('expected', $row) && ! array_key_exists('on_hand', $row) ? 'expected' : 'on_hand',
                'package_size_needed' => 'units_per_package',
                'item_in_open_count' => 'count_unit',
                default => 'id',
            };
            $fields["changes.{$index}.{$field}"] = [$exception->getMessage()];
        }

        return new EmsException($exception->getMessage(), $exception->errorCode, $exception->status, $fields);
    }

    /**
     * A person typed the real number: it is a physical count of this one item.
     *
     * @param  array<string, mixed>  $input  {on_hand}
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function count(int $itemId, User $user, string $key, array $input): array
    {
        $this->authorize($user);
        $data = EmsValidator::validate($input, ['on_hand' => ['required', 'integer', 'min:0', 'max:999999']], [
            'on_hand.required' => 'Enter how many are on hand. Zero is a real count.', 'on_hand.integer' => 'Enter a whole number.',
            'on_hand.min' => 'A count cannot be negative.', 'on_hand.max' => 'That count is too large. Check the number.',
        ]);

        return $this->idempotency->run($user, "ems.master.count.{$itemId}", $key, function () use ($itemId, $user, $data): array {
            $this->ledger->lock();
            $item = SupplyItem::query()->find($itemId) ?? throw EmsException::notFound('supply item');
            $this->counts->recordDirectCount($item, (int) $data['on_hand'], $user);

            return $this->one($item);
        });
    }

    /** @return array<string, mixed> */
    private function one(SupplyItem $item): array
    {
        return $this->present(SupplyItem::query()->with(['location:id,code,label', 'catalogItem:id,name,aliases'])->whereKey($item->getKey())->get())[0];
    }

    /**
     * @param  Collection<int, SupplyItem>  $items
     * @return list<array<string, mixed>>
     */
    public function present(Collection $items): array
    {
        $current = $this->ledger->current($items->filter->tracksStock()->values());
        $counts = $this->ledger->lastCounts($items->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $deducted = $this->ledger->deductionsSinceCount($counts);
        $orders = SupplyOrder::query()->whereIn('supply_item_id', $items->pluck('id')->all())->whereIn('status', SupplyOrder::OPEN)
            ->orderByDesc('id')->get()->unique('supply_item_id')->keyBy('supply_item_id');

        return $items->map(function (SupplyItem $item) use ($current, $counts, $deducted, $orders): array {
            $per = CountMath::perCountUnit($item);
            $base = $current[$item->getKey()]['quantity'] ?? null;
            $toOrder = $base === null ? null : CountMath::orderInCountUnits(CountMath::orderBase($item->threshold_qty_base, $base), $per);
            $order = $orders->get($item->getKey());
            $taken = $deducted[$item->getKey()] ?? 0;
            $count = $counts[$item->getKey()] ?? null;
            $labels = CountMath::LABELS[$item->count_unit] ?? [$item->count_unit, $item->count_unit];
            $lines = StockLevel::lines($item);

            return [
                'id' => $item->getKey(),
                'name' => $item->name,
                'aliases' => $this->aliases($item),
                'location_code' => $item->location?->code,
                'location_label' => $item->location?->label,
                'section_key' => $item->section_key,
                'section_label' => self::SECTION_LABELS[(string) $item->section_key] ?? ($item->section_key !== null ? Str::of($item->section_key)->replace('-', ' ')->ucfirst()->toString() : null),
                'base_unit' => $item->base_unit,
                'count_unit' => $item->count_unit,
                'count_unit_label' => $labels[0],
                'count_unit_label_plural' => $labels[1],
                'units_per_count_unit' => $per,
                'package_unit' => $item->package_unit,
                'units_per_package' => $item->units_per_package,
                'on_hand_base' => $base,
                // More was taken than the ledger held (the supply room screen never refuses a member holding the
                // item): show 0, not a negative shelf, and flag the item for a count.
                'on_hand' => CountMath::display($base === null ? null : max(0, $base), $per),
                'count_due' => $base !== null && $base < 0,
                'deducted_base' => $taken,
                'deducted' => CountMath::display($taken, $per),
                'counted_at' => ($count['counted_at'] ?? null)?->toIso8601String(),
                'counted_by' => $count['counted_by'] ?? null,
                'estimated' => $count['estimated'] ?? false,
                'required_base' => $item->threshold_qty_base,
                'expected' => CountMath::display($item->threshold_qty_base, $per),
                'to_order' => $toOrder,
                'order_state' => match (true) {
                    $order !== null => self::ORDER_STATES[$order->status],
                    $base === null => 'not_counted',
                    $item->threshold_qty_base === null => 'no_level',
                    $toOrder > 0 => 'needs_order',
                    default => 'ok',
                },
                'level' => StockLevel::of($item, $base),
                'reorder_at' => $lines['reorder_at'] ?? null,
                'critical_at' => $lines['critical_at'] ?? null,
                'reorder_at_auto' => $lines['reorder_auto'] ?? true,
                'critical_at_auto' => $lines['critical_auto'] ?? true,
                'open_order' => $order === null ? null : $this->openOrder($order, $item, $per),
                'order_rule_text' => CountMath::orderRuleText($item),
                'refrigerated' => $item->refrigerated,
                'needs_review' => $item->needs_review,
                'review_reasons' => array_values($item->review_reasons ?? []),
                'config_problem' => CountMath::configProblem($item),
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function openOrder(SupplyOrder $order, SupplyItem $item, int $per): array
    {
        // Shown in the item's count unit today, even if the item was counted differently when the order was made.
        $quantity = $order->order_unit === $item->count_unit || $order->quantity_base === null
            ? $order->packages
            : (int) ceil($order->quantity_base / $per);

        return [
            'id' => $order->getKey(), 'status' => self::ORDER_STATES[$order->status], 'quantity' => $quantity,
            'unit_label' => CountMath::label($item->count_unit, $quantity), 'quantity_base' => $order->quantity_base,
            'expected_on' => $order->expected_on?->toDateString(), 'note' => $order->notes,
            'placed_at' => $order->placed_at->toIso8601String(), 'placed_by' => $order->placed_by_name,
            'updated_at' => ($order->status_changed_at ?? $order->placed_at)->toIso8601String(),
            'updated_by' => $order->status_changed_by_name ?? $order->placed_by_name,
        ];
    }

    /**
     * Sheet order within a shelf, which is the order the shelves are laid out.
     *
     * @return \Illuminate\Database\Eloquent\Builder<SupplyItem>
     */
    private function tracked(): \Illuminate\Database\Eloquent\Builder
    {
        return SupplyItem::query()->with(['location:id,code,label,sort', 'catalogItem:id,name,aliases'])
            ->where('active', true)->where('controlled_substance', false)->orderBy('id');
    }

    /**
     * @param  Collection<int, StorageLocation>  $locations
     * @param  Collection<int, SupplyItem>  $items
     * @return list<array{code: string|null, label: string, item_count: int}>
     */
    private function sections(Collection $locations, Collection $items): array
    {
        $counts = $items->countBy(fn (SupplyItem $item): string => (string) $item->location_id);
        $sections = [];
        foreach ($locations as $location) {
            $count = $counts->get((string) $location->getKey(), 0);
            if ($count > 0) {
                $sections[] = ['code' => $location->code, 'label' => $location->label, 'item_count' => $count];
            }
        }
        $unassigned = $counts->get('', 0);
        if ($unassigned > 0) {
            $sections[] = ['code' => null, 'label' => 'No shelf assigned', 'item_count' => $unassigned];
        }

        return $sections;
    }

    /** @return list<string> */
    private function aliases(SupplyItem $item): array
    {
        $names = [$item->bag_alias_text, $item->catalogItem?->name, ...($item->catalogItem->aliases ?? [])];

        return collect($names)->map(fn ($name): string => trim((string) $name))
            ->filter(fn (string $name): bool => $name !== '' && mb_strtolower($name) !== mb_strtolower($item->name))
            ->unique(fn (string $name): string => mb_strtolower($name))->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function changes(SupplyItem $item, array $data): array
    {
        $changes = [];
        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new EmsException('Give the item a name.', 'name_required', 422, ['name' => ['Give the item a name.']]);
            }
            $changes['name'] = $name;
        }
        if (array_key_exists('location_code', $data) || array_key_exists('new_location_label', $data)) {
            $changes['location_id'] = $this->resolveLocation($data)?->getKey();
        }
        if (array_key_exists('active', $data)) {
            $changes['active'] = (bool) $data['active'];
        }

        $unit = (string) ($data['count_unit'] ?? $item->count_unit);
        $perPackage = array_key_exists('units_per_package', $data)
            ? ($data['units_per_package'] !== null ? (int) $data['units_per_package'] : null)
            : ($unit === $item->package_unit ? $item->units_per_package : null);
        if (array_key_exists('count_unit', $data) || array_key_exists('units_per_package', $data)) {
            $this->assertConvertible($unit, $perPackage);
            $this->assertNotInOpenCount($item, $unit, $perPackage);
            $changes['count_unit'] = $unit;
            if ($unit !== 'each') {
                $changes['package_unit'] = $unit;
                $changes['units_per_package'] = $perPackage;
            }
        }

        if (array_key_exists('threshold', $data)) {
            $probe = (clone $item)->forceFill(['count_unit' => $unit, 'units_per_package' => $changes['units_per_package'] ?? $item->units_per_package]);
            $changes += $data['threshold'] === null
                ? ['threshold_qty_base' => null, 'threshold_source_text' => null]
                : $this->thresholdColumns($probe, (int) $data['threshold']);
            // The order level is now a person's answer, so the older question about it is settled.
            $reasons = array_values(array_filter($item->review_reasons ?? [], fn ($reason): bool => stripos((string) $reason, 'order level') === false));
            $changes['review_reasons'] = $reasons;
            $changes['needs_review'] = $reasons !== [];
        }

        return $changes + $this->levelChanges($item, $data, $unit, $changes);
    }

    /**
     * The yellow and red lines a person typed, in the item's count unit; blank returns a line to its preset.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $changes  the other changes in this save, so the check sees the item as it will be
     * @return array<string, int|null>
     */
    private function levelChanges(SupplyItem $item, array $data, string $unit, array $changes): array
    {
        if (! array_key_exists('reorder_at', $data) && ! array_key_exists('critical_at', $data)) {
            return [];
        }
        $probe = (clone $item)->forceFill(['count_unit' => $unit] + array_intersect_key($changes, array_flip(['units_per_package', 'threshold_qty_base'])));
        $out = [];
        foreach (['reorder_at' => 'reorder_at_qty_base', 'critical_at' => 'critical_at_qty_base'] as $field => $column) {
            if (array_key_exists($field, $data)) {
                $out[$column] = $data[$field] === null ? null : CountMath::toBase($probe, (int) $data[$field]);
                if ($out[$column] !== null) {
                    StockLedger::assertWithinLimit($out[$column]);
                }
                $probe->setAttribute($column, $out[$column]);
            }
        }
        $lines = StockLevel::lines($probe);
        if ($lines === null) {
            throw new EmsException('Set the expected amount before the yellow and red lines.', 'level_needs_expected', 422, [
                (array_key_exists('reorder_at', $data) ? 'reorder_at' : 'critical_at') => ['Set the expected amount first.'],
            ]);
        }
        $per = CountMath::perCountUnit($probe);
        if ($probe->reorder_at_qty_base !== null && intdiv($probe->reorder_at_qty_base, $per) > $lines['expected']) {
            throw new EmsException('Yellow at cannot be above the expected amount.', 'level_order', 422, ['reorder_at' => ['Yellow at cannot be above the expected amount.']]);
        }
        if ($probe->critical_at_qty_base !== null && intdiv($probe->critical_at_qty_base, $per) > $lines['reorder_at']) {
            throw new EmsException('Red at cannot be above Yellow at.', 'level_order', 422, ['critical_at' => ['Red at cannot be above Yellow at.']]);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function thresholdColumns(SupplyItem $item, int $threshold): array
    {
        $base = CountMath::toBase($item, $threshold);
        StockLedger::assertWithinLimit($base);

        return ['threshold_qty_base' => $base, 'threshold_source_text' => "< {$threshold} ".CountMath::label($item->count_unit, $threshold)];
    }

    /** @param array<string, mixed> $data */
    private function resolveLocation(array $data): ?StorageLocation
    {
        $label = trim((string) ($data['new_location_label'] ?? ''));
        if ($label !== '') {
            $code = mb_strtoupper(mb_substr($label, 0, 24));

            return StorageLocation::query()->where('code', $code)->first()
                ?? StorageLocation::query()->create(['code' => $code, 'label' => $label, 'sort' => (int) StorageLocation::query()->max('sort') + 10, 'active' => true]);
        }
        $code = trim((string) ($data['location_code'] ?? ''));
        if ($code === '') {
            return null;
        }

        return StorageLocation::query()->where('code', $code)->first()
            ?? throw new EmsException('That shelf does not exist.', 'unknown_location', 422, ['location_code' => ['That shelf does not exist.']]);
    }

    private function assertConvertible(string $unit, ?int $perPackage): void
    {
        if ($unit !== 'each' && ($perPackage === null || $perPackage < 1)) {
            $label = CountMath::LABELS[$unit][0] ?? $unit;
            throw new EmsException("Enter how many units are in one {$label}.", 'package_size_needed', 422, ['units_per_package' => ["Enter how many units are in one {$label}."]]);
        }
    }

    /** A number already typed was converted with the old size, so its meaning would change underneath the count. */
    private function assertNotInOpenCount(SupplyItem $item, string $unit, ?int $perPackage): void
    {
        $changes = $unit !== $item->count_unit || ($unit !== 'each' && $perPackage !== $item->units_per_package);
        if ($changes && InventorySnapshotLine::query()->where('supply_item_id', $item->getKey())
            ->whereIn('snapshot_id', InventorySnapshot::query()->where('status', InventorySnapshot::DRAFT)->select('id'))->exists()) {
            throw EmsException::conflict('This item already has a number in the count in progress. Clear that number or submit the count, then change how it is counted.', 'item_in_open_count');
        }
    }

    private function audit(SupplyItem $item, string $field, mixed $before, mixed $after, EmsActor $actor): void
    {
        SupplyItemEvent::query()->create([
            'supply_item_id' => $item->getKey(), 'user_id' => $actor->userId, 'actor_name' => $actor->name, 'field' => $field,
            'before' => ['value' => $before], 'after' => ['value' => $after], 'reason' => self::EDIT_REASON,
        ]);
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'location_code' => ['sometimes', 'nullable', 'string', 'max:24'],
            'new_location_label' => ['sometimes', 'nullable', 'string', 'max:60'],
            'count_unit' => ['sometimes', 'in:'.implode(',', SupplyItem::COUNT_UNITS)],
            'units_per_package' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'threshold' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'reorder_at' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'critical_at' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'name.max' => 'Keep the name to 160 characters or fewer.',
            'count_unit.in' => 'Choose how this item is counted from the list.',
            'units_per_package.integer' => 'Enter a whole number of units.', 'units_per_package.min' => 'A package holds at least 1 unit.',
            'threshold.integer' => 'Enter a whole number.', 'threshold.min' => 'The order level cannot be negative.',
            'reorder_at.integer' => 'Enter a whole number.', 'reorder_at.min' => 'Yellow at cannot be negative.',
            'critical_at.integer' => 'Enter a whole number.', 'critical_at.min' => 'Red at cannot be negative.',
        ];
    }

    private function authorize(User $user): void
    {
        if (! $this->access->canEditInventory($user)) {
            throw EmsException::forbidden();
        }
    }
}
