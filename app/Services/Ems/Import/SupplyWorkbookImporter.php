<?php

declare(strict_types=1);

namespace App\Services\Ems\Import;

use App\Models\Ems\CatalogItem;
use App\Models\Ems\CatalogSupplyLink;
use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\InventorySnapshotLine;
use App\Models\Ems\StorageLocation;
use App\Models\Ems\SupplyItem;
use App\Models\Ems\SupplyItemEvent;
use App\Services\Ems\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Applies the supply room workbook (database/data/ems/supply_room_workbook_2026.json, written by
 * scripts/ems/extract_supply_workbook.py) to the Hub: shelves, order levels and package data, new items,
 * ONE final starting-count snapshot, and the reviewed form-item links.
 *
 * Safe to repeat:
 *  - a final snapshot with idempotency key workbook:<sha256> makes a second run do nothing;
 *  - a field a person changed (a supply item event that is not from "Source import" or "System") is never overwritten,
 *    and shelf, order level and package settings are each left alone as a group;
 *  - an item that already has a counted snapshot line is never given a starting count;
 *  - a person's catalog link is never replaced.
 * A blank count is never zero, controlled items never get a count, and a bare number whose order level is written in
 * boxes or cases is ambiguous and gets no starting count (see SupplyWorkbookCounts).
 * Movements recorded before the snapshot stay in history and are not subtracted from the supplied counts.
 * The dry run plans with the same code and writes nothing.
 */
final class SupplyWorkbookImporter
{
    public const SCHEMA = 'mbfd.ems.supply_room_workbook.v1';

    public const LINKS_SCHEMA = 'mbfd.ems.catalog_supply_links.v1';

    public const REASON = 'Workbook import 2026';

    public const ACTOR = 'System';

    public const SNAPSHOT_NAME = 'Supply Room Count workbook';

    private const SYSTEM_ACTORS = [self::ACTOR, 'Source import'];

    /** A person who changed any field of a group keeps the whole group. */
    private const GROUPS = [
        'shelf' => ['location_id', 'location_code'],
        'threshold' => ['threshold_qty_base', 'threshold_source_text'],
        'package' => ['package_unit', 'units_per_package', 'count_unit'],
    ];

    private const UNITS = ['each', 'box', 'case', 'pack', 'bag'];

    private const KINDS = ['blank', 'bare', 'unit', 'invalid'];

    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * @return array<string, mixed>
     */
    public function run(string $workbookPath, ?string $linksPath, bool $apply): array
    {
        $doc = $this->loadWorkbook($workbookPath);
        $links = $linksPath !== null && is_file($linksPath) ? $this->loadLinks($linksPath) : null;

        if (! $apply) {
            return $this->execute($doc, $links, false);
        }

        return DB::transaction(function () use ($doc, $links): array {
            $this->ledger->lock();
            if ($this->alreadyApplied($doc)) {
                // Nothing to do: report what a run would find, but write nothing.
                return ['dry_run' => false, 'applied' => false, 'noop' => true] + $this->execute($doc, $links, false);
            }

            return $this->execute($doc, $links, true);
        });
    }

    // ------------------------------------------------------------------ loading and checking

    /** @return array<string, mixed> */
    public function loadWorkbook(string $path): array
    {
        $doc = $this->readJson($path);
        if (($doc['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('That file is not a '.self::SCHEMA.' document.');
        }
        $sha = (string) ($doc['source']['sha256'] ?? '');
        if (preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
            throw new RuntimeException('The workbook extraction has no valid sha256.');
        }
        if (! is_array($doc['items'] ?? null) || ! array_is_list($doc['items'])) {
            throw new RuntimeException('The workbook extraction has no item list.');
        }
        $seen = [];
        $new = 0;
        $baselines = 0;
        foreach ($doc['items'] as $i => $entry) {
            $key = $this->checkItem($entry, (int) $i);
            if (isset($seen[$key])) {
                throw new RuntimeException("The workbook extraction names {$key} more than once.");
            }
            $seen[$key] = true;
            $new += ($entry['new'] ?? false) ? 1 : 0;
            $baselines += isset($entry['count']['baseline_base']) ? 1 : 0;
        }
        $counts = (array) ($doc['source']['row_counts'] ?? []);
        if (($counts['new_items'] ?? null) !== $new || ($counts['baselines'] ?? null) !== $baselines) {
            throw new RuntimeException('The workbook extraction totals do not match its items; the file was edited by hand or is damaged.');
        }

        return $doc;
    }

    /** @return array<string, mixed> */
    public function loadLinks(string $path): array
    {
        $doc = $this->readJson($path);
        if (($doc['schema'] ?? null) !== self::LINKS_SCHEMA) {
            throw new RuntimeException('That file is not a '.self::LINKS_SCHEMA.' document.');
        }
        foreach (['links', 'not_stocked'] as $list) {
            if (! is_array($doc[$list] ?? null)) {
                throw new RuntimeException("The link file has no {$list} list.");
            }
            foreach ($doc[$list] as $link) {
                if (! is_string($link['catalog_ref'] ?? null) || ($list === 'links' && ! is_string($link['source_row_key'] ?? null))) {
                    throw new RuntimeException("A {$list} entry in the link file is incomplete.");
                }
            }
        }

        return $doc;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }
        $doc = json_decode((string) file_get_contents($path), true);
        if (! is_array($doc)) {
            throw new RuntimeException("{$path} is not valid JSON.");
        }

        return $doc;
    }

    /** @param  mixed  $entry */
    private function checkItem($entry, int $index): string
    {
        $key = is_array($entry) ? ($entry['source_row_key'] ?? null) : null;
        if (! is_string($key) || $key === '') {
            throw new RuntimeException("Workbook item {$index} has no source_row_key.");
        }
        foreach (['shelf', 'threshold', 'package'] as $part) {
            if (! is_array($entry[$part] ?? null)) {
                throw new RuntimeException("{$key} is missing its {$part} section.");
            }
        }
        if (! in_array($entry['count_unit'] ?? null, self::UNITS, true)) {
            throw new RuntimeException("{$key} has an unknown count unit.");
        }
        $packageUnit = $entry['package']['unit'] ?? null;
        if ($packageUnit !== null && ! in_array($packageUnit, self::UNITS, true)) {
            throw new RuntimeException("{$key} has an unknown package unit.");
        }
        if (($entry['new'] ?? false) && ! is_array($entry['new_item'] ?? null)) {
            throw new RuntimeException("{$key} is a new item without its details.");
        }
        $count = $entry['count'] ?? null;
        if ($count !== null) {
            $unit = $count['unit'] ?? null;
            if (! in_array($count['kind'] ?? null, self::KINDS, true) || ($unit !== null && ! in_array($unit, self::UNITS, true))) {
                throw new RuntimeException("{$key} has an unreadable count.");
            }
        }

        return $key;
    }

    private function alreadyApplied(array $doc): bool
    {
        return InventorySnapshot::query()->where('idempotency_key', 'workbook:'.$doc['source']['sha256'])->where('status', InventorySnapshot::FINAL)->exists();
    }

    // ------------------------------------------------------------------ plan and write

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>|null  $links
     * @return array<string, mixed>
     */
    private function execute(array $doc, ?array $links, bool $write): array
    {
        $keys = array_column($doc['items'], 'source_row_key');
        $existing = SupplyItem::query()->whereIn('source_row_key', $keys)->get()->keyBy('source_row_key');
        $ids = $existing->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $edited = $this->personEdits($ids);
        $counted = array_flip($this->countedItemIds($ids));
        $locations = StorageLocation::query()->get();
        $codes = $locations->pluck('code', 'id')->all();

        $plans = [];
        foreach ($doc['items'] as $entry) {
            $item = $existing->get($entry['source_row_key']);
            $plans[] = $this->planItem(
                $entry, $item, $item !== null ? ($edited[(int) $item->getKey()] ?? []) : [], $item !== null && isset($counted[(int) $item->getKey()]), $codes,
            );
        }

        $report = $this->summary($doc, $plans);
        $report['already_applied'] = $this->alreadyApplied($doc);
        $report['dry_run'] = ! $write;
        $report['applied'] = $write;

        if ($write) {
            $report['snapshot'] = $this->writeAll($doc, $plans, $locations);
        }
        $report['links'] = $links === null ? null : $this->applyLinks($links, $write, $plans);
        $report['exceptions'] = $this->exceptions($doc, $plans, $report['links']);

        return $report;
    }

    /**
     * What the workbook means for one item, given the item as it is now.
     *
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $edited  fields a person has changed on this item
     * @param  array<int, string>  $codes  location id => code
     * @return array<string, mixed>
     */
    private function planItem(array $entry, ?SupplyItem $item, array $edited, bool $alreadyCounted, array $codes): array
    {
        $key = (string) $entry['source_row_key'];
        $plan = ['entry' => $entry, 'item' => $item, 'create' => false, 'changes' => [], 'notes' => [], 'baseline' => null, 'effective' => null];
        $name = (string) ($entry['new_item']['name'] ?? $entry['item_text']);

        if ($item === null && ! ($entry['new'] ?? false)) {
            $plan['notes'][] = $this->note('item_missing', $name, "{$key} is not in the supply room list in the Hub.", 'Skipped.');

            return $plan;
        }
        if ($item !== null && ($entry['new'] ?? false) && $item->name !== $entry['new_item']['name']) {
            $plan['notes'][] = $this->note('row_key_collision', $name, "{$key} already belongs to a different item ({$item->name}).", 'Skipped.');

            return $plan;
        }
        $plan['create'] = $item === null;

        $locked = fn (string $group): bool => array_intersect(self::GROUPS[$group], $edited) !== [];
        $current = [
            'location_code' => $item !== null ? ($codes[$item->location_id] ?? null) : null,
            'threshold_qty_base' => $item?->threshold_qty_base,
            'threshold_source_text' => $item?->threshold_source_text,
            'package_unit' => $item?->package_unit,
            'units_per_package' => $item?->units_per_package,
            'count_unit' => $item->count_unit ?? 'each',
        ];
        $controlled = (bool) ($item->controlled_substance ?? false);
        $effective = $current;

        // Shelf: the Inventory sheet's shelf (or the Count sheet's when the Inventory sheet has none).
        $shelf = $entry['shelf']['code'] ?? null;
        if ($shelf !== null && $shelf !== $current['location_code']) {
            if ($locked('shelf')) {
                $plan['notes'][] = $this->note('person_edit', $name, 'The shelf was changed by a person.', 'Shelf left as it is.');
            } else {
                $plan['changes']['location_id'] = [$current['location_code'], $shelf];
                $effective['location_code'] = $shelf;
            }
        }

        // Order level.
        $threshold = (array) $entry['threshold'];
        if (! $controlled && in_array($threshold['status'] ?? '', ['converted', 'assumed_each', 'explicit_equivalent'], true) && isset($threshold['qty_base'])) {
            $differs = (int) $threshold['qty_base'] !== $current['threshold_qty_base'] || ($threshold['text'] ?? null) !== $current['threshold_source_text'];
            if ($differs && $locked('threshold')) {
                $plan['notes'][] = $this->note('person_edit', $name, 'The order level was changed by a person.', 'Order level left as it is.');
            } elseif ($differs) {
                foreach (['threshold_qty_base' => (int) $threshold['qty_base'], 'threshold_source_text' => $threshold['text'] ?? null] as $field => $after) {
                    if ($after !== $current[$field]) {
                        $plan['changes'][$field] = [$current[$field], $after];
                        $effective[$field] = $after;
                    }
                }
            }
        }

        // Package data and the count unit.
        $package = (array) $entry['package'];
        if (! $locked('package')) {
            foreach (['package_unit' => $package['unit'] ?? null, 'units_per_package' => $package['units_per_package'] ?? null] as $field => $after) {
                if ($after !== null && $after !== $current[$field]) {
                    $plan['changes'][$field] = [$current[$field], $after];
                    $effective[$field] = $after;
                }
            }
        } elseif ($package['unit'] !== null || $package['units_per_package'] !== null) {
            $plan['notes'][] = $this->note('person_edit', $name, 'The package settings were changed by a person.', 'Package settings and count unit left as they are.');
        }

        $count = $entry['count'] ?? null;
        $resolved = $count === null ? null : SupplyWorkbookCounts::resolve($count, $threshold, $effective['package_unit'], $effective['units_per_package'], $controlled);
        if (! $locked('package')) {
            $unit = SupplyWorkbookCounts::countUnit($resolved['count_unit'] ?? null, $threshold, $effective['package_unit'], $effective['units_per_package']);
            if ($unit !== $current['count_unit']) {
                $plan['changes']['count_unit'] = [$current['count_unit'], $unit];
            }
            $effective['count_unit'] = $unit;
        }
        $plan['effective'] = $effective;

        if ($resolved !== null) {
            $planned = $count['baseline_base'] ?? null;
            if ($resolved['baseline_base'] !== $planned) {
                $plan['notes'][] = $this->note('config_changed', $name, 'The package data now held differs from the data the extraction used. '.(string) $resolved['message'],
                    $resolved['baseline_base'] === null ? 'No starting count applied.' : 'Starting count worked out from the current package data.');
            }
            if ($resolved['baseline_base'] !== null && $alreadyCounted) {
                $plan['notes'][] = $this->note('already_counted', $name, 'This item already has a counted snapshot line.', 'Starting count not applied; the existing count stands.');
            } elseif ($resolved['baseline_base'] !== null) {
                $plan['baseline'] = ['value' => (int) $count['value'], 'typed_unit' => (string) $resolved['count_unit'], 'base' => (int) $resolved['baseline_base']];
            }
        }

        return $plan;
    }

    /** @return array{group: string, item: string, detail: string, action: string} */
    private function note(string $group, string $item, string $detail, string $action): array
    {
        return ['group' => $group, 'item' => $item, 'detail' => $detail, 'action' => $action];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, list<string>> supply item id => fields a person changed
     */
    private function personEdits(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            $rows = SupplyItemEvent::query()->whereIn('supply_item_id', $chunk)
                ->where(fn ($q) => $q->whereNotNull('user_id')->orWhereNull('actor_name')->orWhereNotIn('actor_name', self::SYSTEM_ACTORS))
                ->get(['supply_item_id', 'field']);
            foreach ($rows as $row) {
                $out[(int) $row->supply_item_id][] = (string) $row->field;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function countedItemIds(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            $out = [...$out, ...InventorySnapshotLine::query()->where('value_source', InventorySnapshotLine::COUNTED)->whereIn('supply_item_id', $chunk)->distinct()->pluck('supply_item_id')->map(fn ($id): int => (int) $id)->all()];
        }

        return $out;
    }

    // ------------------------------------------------------------------ writing

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<array<string, mixed>>  $plans
     * @param  \Illuminate\Support\Collection<int, StorageLocation>  $locations
     * @return array<string, mixed>
     */
    private function writeAll(array $doc, array $plans, $locations): array
    {
        $ids = $locations->pluck('id', 'code')->all();
        $next = (int) $locations->max('sort');
        foreach ($plans as $plan) {
            $code = $plan['effective']['location_code'] ?? null;
            if ($code !== null && ! isset($ids[$code])) {
                $next += 10;
                $ids[$code] = StorageLocation::query()->create(['code' => $code, 'label' => $code, 'sort' => $next, 'active' => true])->getKey();
            }
        }

        $lines = [];
        foreach ($plans as $plan) {
            if ($plan['effective'] === null) {
                continue;
            }
            $item = $plan['create'] ? $this->createItem($plan, $ids, $doc) : $this->updateItem($plan, $ids);
            if ($plan['baseline'] !== null) {
                $lines[] = [$item, $plan];
            }
        }

        return $this->writeSnapshot($doc, $lines);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, int>  $locationIds
     */
    private function updateItem(array $plan, array $locationIds): SupplyItem
    {
        /** @var SupplyItem $item */
        $item = $plan['item'];
        foreach ($plan['changes'] as $field => [$before, $after]) {
            $beforeValue = $field === 'location_id' ? $item->location_id : $before;
            $afterValue = $field === 'location_id' ? $locationIds[$after] : $after;
            SupplyItemEvent::query()->create([
                'supply_item_id' => $item->getKey(), 'user_id' => null, 'actor_name' => self::ACTOR, 'field' => $field,
                'before' => ['value' => $beforeValue], 'after' => ['value' => $afterValue], 'reason' => self::REASON,
            ]);
            $item->setAttribute($field, $afterValue);
        }
        if ($plan['changes'] !== []) {
            $item->save();
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, int>  $locationIds
     * @param  array<string, mixed>  $doc
     */
    private function createItem(array $plan, array $locationIds, array $doc): SupplyItem
    {
        $entry = $plan['entry'];
        $spec = (array) $entry['new_item'];
        $effective = (array) $plan['effective'];
        $catalog = CatalogItem::query()->firstOrCreate(['key' => (string) $spec['catalog_ref']], [
            'name' => (string) $spec['name'], 'aliases' => $spec['bag_alias_text'] !== null ? [(string) $spec['bag_alias_text']] : null,
            'refrigerated' => (bool) $spec['refrigerated'], 'controlled_substance' => false, 'active' => true,
        ]);

        return SupplyItem::query()->create([
            'catalog_item_id' => $catalog->getKey(), 'location_id' => $effective['location_code'] !== null ? $locationIds[$effective['location_code']] : null,
            'source_row_key' => (string) $entry['source_row_key'], 'section_key' => $spec['section_key'], 'name' => (string) $spec['name'],
            'bag_alias_text' => $spec['bag_alias_text'], 'base_unit' => 'each', 'count_unit' => $effective['count_unit'],
            'package_unit' => $effective['package_unit'], 'units_per_package' => $effective['units_per_package'],
            'threshold_qty_base' => $effective['threshold_qty_base'], 'threshold_source_text' => $effective['threshold_source_text'],
            'qty_per_package_text' => $spec['qty_per_package_text'], 'order_amount_text' => $spec['order_amount_text'],
            'suppliers' => [], 'item_numbers' => $spec['item_numbers'], 'refrigerated' => (bool) $spec['refrigerated'],
            'controlled_substance' => false, 'has_expiration' => null, 'needs_review' => true, 'review_reasons' => $spec['review_reasons'],
            'source' => ['workbook' => ['count_row' => $entry['count_row'], 'inventory_row' => $entry['inventory_row'], 'item_text' => $entry['item_text'], 'file_sha256' => $doc['source']['sha256']]],
            'active' => true,
        ]);
    }

    /**
     * One final baseline snapshot. Lines are written while it is a draft; a final snapshot refuses new lines.
     *
     * @param  array<string, mixed>  $doc
     * @param  list<array{0: SupplyItem, 1: array<string, mixed>}>  $lines
     * @return array<string, mixed>
     */
    private function writeSnapshot(array $doc, array $lines): array
    {
        $sha = (string) $doc['source']['sha256'];
        $through = $this->ledger->maxMovementId();
        $snapshot = InventorySnapshot::query()->create([
            'uuid' => (string) Str::uuid(), 'kind' => InventorySnapshot::KIND_BASELINE, 'origin' => InventorySnapshot::ORIGIN_WORKBOOK,
            'status' => InventorySnapshot::DRAFT, 'started_at' => now(), 'cutoff_at' => now(), 'through_movement_id' => $through,
            'previous_snapshot_id' => InventorySnapshot::query()->where('status', InventorySnapshot::FINAL)->orderByDesc('version')->value('id'),
            'idempotency_key' => 'workbook:'.$sha,
            'notes' => "Starting counts from the supply room workbook (sha256 {$sha}). Movements recorded before this snapshot stay in history and are not subtracted from these counts.",
        ]);

        foreach ($lines as [$item, $plan]) {
            $effective = (array) $plan['effective'];
            $unit = (string) $effective['count_unit'];
            $per = $unit === 'each' ? 1 : max(1, (int) $effective['units_per_package']);
            $baseline = (array) $plan['baseline'];
            InventorySnapshotLine::query()->create([
                'snapshot_id' => $snapshot->getKey(), 'supply_item_id' => $item->getKey(), 'location_code' => $effective['location_code'],
                'item_name' => $item->name, 'quantity_base' => $baseline['base'], 'entry' => [SupplyWorkbookCounts::entryKey($baseline['typed_unit']) => $baseline['value']],
                'value_source' => InventorySnapshotLine::COUNTED, 'warnings' => [], 'through_movement_id' => $through,
                'section_key' => $item->section_key, 'count_unit' => $unit, 'units_per_count_unit' => $per,
                'counted_value' => (int) ceil($baseline['base'] / $per),
            ]);
        }

        $tracked = SupplyItem::query()->where('active', true)->where('controlled_substance', false)->count();
        $version = (int) InventorySnapshot::query()->max('version') + 1;
        $snapshot->update([
            'status' => InventorySnapshot::FINAL, 'version' => $version, 'finalized_at' => now(), 'finalized_name' => self::SNAPSHOT_NAME,
            'counted_items' => count($lines), 'uncounted_items' => max(0, $tracked - count($lines)),
        ]);

        return ['uuid' => $snapshot->uuid, 'version' => $version, 'lines' => count($lines), 'through_movement_id' => $through];
    }

    // ------------------------------------------------------------------ links

    /**
     * @param  array<string, mixed>  $links
     * @param  list<array<string, mixed>>  $plans
     * @return array<string, mixed>
     */
    private function applyLinks(array $links, bool $write, array $plans): array
    {
        $pending = collect($plans)->filter(fn (array $p): bool => $p['create'])->map(fn (array $p): string => (string) $p['entry']['source_row_key'])->flip()->all();
        $result = ['written' => 0, 'unchanged' => 0, 'kept_person' => 0, 'skipped' => [], 'not_stocked_written' => 0];
        $decisions = [
            ...array_map(fn (array $l): array => $l + ['not_stocked' => false], $links['links']),
            ...array_map(fn (array $l): array => $l + ['not_stocked' => true, 'source_row_key' => null], $links['not_stocked']),
        ];

        foreach ($decisions as $link) {
            $catalog = CatalogItem::query()->where('key', $link['catalog_ref'])->first();
            if ($catalog === null) {
                $result['skipped'][] = "{$link['catalog_ref']}: the form item is not in the Hub yet.";

                continue;
            }
            $supply = $link['source_row_key'] !== null ? SupplyItem::query()->where('source_row_key', $link['source_row_key'])->first() : null;
            if ($link['source_row_key'] !== null && $supply === null && ! isset($pending[$link['source_row_key']])) {
                $result['skipped'][] = "{$link['catalog_ref']}: supply row {$link['source_row_key']} is not in the Hub.";

                continue;
            }
            if ($supply?->controlled_substance) {
                $result['skipped'][] = "{$link['catalog_ref']}: controlled items are never linked to stock.";

                continue;
            }
            $row = CatalogSupplyLink::query()->where('catalog_item_id', $catalog->getKey())->first();
            if ($row !== null && $row->source === CatalogSupplyLink::SOURCE_PERSON) {
                $result['kept_person']++;

                continue;
            }
            $targetId = $supply !== null ? (int) $supply->getKey() : null;
            $same = $row !== null && (bool) $row->not_stocked === $link['not_stocked'] && ($row->supply_item_id === null ? null : (int) $row->supply_item_id) === $targetId;
            if ($same) {
                $result['unchanged']++;

                continue;
            }
            $result[$link['not_stocked'] ? 'not_stocked_written' : 'written']++;
            if ($write) {
                CatalogSupplyLink::query()->updateOrCreate(['catalog_item_id' => $catalog->getKey()], [
                    'supply_item_id' => $supply?->getKey(), 'not_stocked' => $link['not_stocked'], 'source' => CatalogSupplyLink::SOURCE_REVIEWED,
                    'decided_by_user_id' => null, 'decided_name' => self::SNAPSHOT_NAME.' review', 'note' => mb_substr((string) ($link['reason'] ?? ''), 0, 500),
                ]);
            }
        }

        return $result;
    }

    // ------------------------------------------------------------------ report

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<array<string, mixed>>  $plans
     * @return array<string, mixed>
     */
    private function summary(array $doc, array $plans): array
    {
        $byField = [];
        foreach ($plans as $plan) {
            if ($plan['create']) {
                continue; // a new item is created with its values; nothing about it is a change to audit
            }
            foreach (array_keys($plan['changes']) as $field) {
                $byField[$field] = ($byField[$field] ?? 0) + 1;
            }
        }
        ksort($byField);
        $locations = StorageLocation::query()->pluck('code')->flip()->all();
        $newCodes = collect($plans)->map(fn (array $p) => $p['effective']['location_code'] ?? null)->filter(fn ($c): bool => $c !== null && ! isset($locations[$c]))->unique()->count();

        return [
            'sha256' => $doc['source']['sha256'],
            'counts' => [
                'items_in_file' => count($plans),
                'items_found' => count(array_filter($plans, fn (array $p): bool => $p['item'] !== null)),
                'items_missing' => count(array_filter($plans, fn (array $p): bool => $p['effective'] === null)),
                'items_to_create' => count(array_filter($plans, fn (array $p): bool => $p['create'])),
                'shelves_to_create' => $newCodes,
                'field_changes' => array_sum($byField),
                'starting_counts' => count(array_filter($plans, fn (array $p): bool => $p['baseline'] !== null)),
                'already_counted_skipped' => count(array_filter($plans, fn (array $p): bool => collect($p['notes'])->contains('group', 'already_counted'))),
                'person_edits_respected' => count(array_filter($plans, fn (array $p): bool => collect($p['notes'])->contains('group', 'person_edit'))),
            ],
            'field_changes_by_field' => $byField,
        ];
    }

    /**
     * Every exception the extraction found, then the ones found while applying to this database.
     *
     * @param  array<string, mixed>  $doc
     * @param  list<array<string, mixed>>  $plans
     * @param  array<string, mixed>|null  $links
     * @return list<array<string, mixed>>
     */
    private function exceptions(array $doc, array $plans, ?array $links): array
    {
        $out = [];
        foreach ((array) ($doc['exceptions'] ?? []) as $e) {
            $out[] = ['group' => (string) $e['group'], 'where' => trim(($e['sheet'] ?? '').' '.($e['row'] ?? '')), 'item' => (string) ($e['item_text'] ?? ''),
                'detail' => (string) $e['detail'], 'action' => (string) $e['action']];
        }
        foreach ($plans as $plan) {
            foreach ($plan['notes'] as $note) {
                $out[] = ['group' => $note['group'], 'where' => (string) $plan['entry']['source_row_key'], 'item' => $note['item'], 'detail' => $note['detail'], 'action' => $note['action']];
            }
        }
        foreach ((array) ($links['skipped'] ?? []) as $skipped) {
            $out[] = ['group' => 'link_skipped', 'where' => 'links', 'item' => '', 'detail' => (string) $skipped, 'action' => 'No link written.'];
        }

        return $out;
    }
}
