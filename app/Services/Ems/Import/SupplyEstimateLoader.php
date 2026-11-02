<?php

declare(strict_types=1);

namespace App\Services\Ems\Import;

use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\InventorySnapshotLine;
use App\Models\Ems\StorageLocation;
use App\Models\Ems\SupplyItem;
use App\Models\Ems\SupplyItemEvent;
use App\Services\Ems\CountMath;
use App\Services\Ems\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Best guesses for items the workbook left without a usable number (docs/ems/captain5-medical-inventory-workflow.md
 * section 9). They are written as one final baseline snapshot with origin = estimate, so the on-hand number and the
 * red deduction work as usual, the screen can say "Estimate", and the next real count of an item simply outranks it.
 * An item somebody has already counted keeps its number and its settings. A second run of the same file changes nothing.
 */
final class SupplyEstimateLoader
{
    public const REASON = 'Best estimate 2026-11-01';

    public const ACTOR = 'System';

    public const SNAPSHOT_NAME = 'Best estimate (not a physical count)';

    private const ITEM_FIELDS = ['name', 'count_unit', 'package_unit', 'units_per_package', 'threshold_qty_base', 'threshold_source_text', 'section_key', 'review_reasons'];

    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * @return array{sha256: string, noop: bool, applied: bool, loaded: list<array{name: string, shows: string, base: int}>, kept: list<string>, moved: list<string>}
     */
    public function run(string $path, bool $apply): array
    {
        $doc = $this->load($path);
        $sha = hash_file('sha256', $path) ?: throw new RuntimeException("Could not read {$path}.");
        $key = 'estimate:'.$sha;
        $report = ['sha256' => $sha, 'noop' => false, 'applied' => false, 'loaded' => [], 'kept' => [], 'moved' => []];

        if (InventorySnapshot::query()->where('idempotency_key', $key)->exists()) {
            return ['noop' => true] + $report;
        }

        DB::beginTransaction();
        try {
            $this->ledger->lock();
            $this->createLocations($doc['locations'] ?? []);
            $through = $this->ledger->maxMovementId();
            $lines = [];
            foreach ($doc['estimates'] ?? [] as $entry) {
                $item = $this->item((string) $entry['name']);
                if ($this->alreadyHasNumber($item)) {
                    $report['kept'][] = $item->name;

                    continue;
                }
                $this->applyChanges($item, $entry);
                CountMath::assertConvertible($item);
                $base = CountMath::toBase($item, (int) $entry['typed']);
                StockLedger::assertWithinLimit($base);
                $lines[] = [$item, (int) $entry['typed'], $base];
                $report['loaded'][] = ['name' => $item->name, 'shows' => $entry['typed'].' '.CountMath::label($item->count_unit, (int) $entry['typed']), 'base' => $base];
            }
            foreach ($doc['moves'] ?? [] as $entry) {
                $item = $this->item((string) $entry['name']);
                $this->applyChanges($item, $entry);
                $report['moved'][] = $item->name;
            }
            if ($lines !== []) {
                $this->writeSnapshot($key, (string) ($doc['note'] ?? ''), $lines, $through);
            }
            if ($apply) {
                DB::commit();
                $report['applied'] = true;
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private function load(string $path): array
    {
        $doc = json_decode((string) file_get_contents($path), true);
        if (! is_array($doc) || ! isset($doc['estimates']) || ! is_array($doc['estimates'])) {
            throw new RuntimeException("{$path} is not an estimates file.");
        }

        return $doc;
    }

    /** @param list<array{code: string, label: string, sort: int}> $locations */
    private function createLocations(array $locations): void
    {
        foreach ($locations as $location) {
            StorageLocation::query()->firstOrCreate(['code' => $location['code']], ['label' => $location['label'], 'sort' => $location['sort'], 'active' => true]);
        }
    }

    private function item(string $name): SupplyItem
    {
        $found = SupplyItem::query()->where('active', true)->where('controlled_substance', false)->where('name', $name)->get();
        if ($found->count() !== 1) {
            throw new RuntimeException("Expected one active item named \"{$name}\", found {$found->count()}. Nothing was changed.");
        }

        return $found->first();
    }

    private function alreadyHasNumber(SupplyItem $item): bool
    {
        if (($this->ledger->current(collect([$item]))[$item->getKey()]['quantity'] ?? null) !== null) {
            return true;
        }

        return InventorySnapshotLine::query()->where('supply_item_id', $item->getKey())->whereNotNull('quantity_base')
            ->whereIn('snapshot_id', InventorySnapshot::query()->where('status', InventorySnapshot::DRAFT)->select('id'))->exists();
    }

    /** @param array<string, mixed> $entry */
    private function applyChanges(SupplyItem $item, array $entry): void
    {
        $changes = array_intersect_key($entry['set'] ?? [], array_flip(self::ITEM_FIELDS));
        if (isset($entry['location_code'])) {
            $changes['location_id'] = StorageLocation::query()->where('code', $entry['location_code'])->value('id')
                ?? throw new RuntimeException("There is no shelf {$entry['location_code']}.");
        }
        if (array_key_exists('review_reasons', $changes)) {
            $changes['needs_review'] = $changes['review_reasons'] !== [];
        }
        foreach ($changes as $field => $after) {
            $before = $item->getAttribute($field);
            if ($before === $after) {
                continue;
            }
            SupplyItemEvent::query()->create([
                'supply_item_id' => $item->getKey(), 'user_id' => null, 'actor_name' => self::ACTOR, 'field' => $field,
                'before' => ['value' => $before], 'after' => ['value' => $after], 'reason' => self::REASON,
            ]);
            $item->setAttribute($field, $after);
        }
        $item->save();
    }

    /** @param list<array{0: SupplyItem, 1: int, 2: int}> $lines */
    private function writeSnapshot(string $key, string $note, array $lines, int $through): void
    {
        $snapshot = InventorySnapshot::query()->create([
            'uuid' => (string) Str::uuid(), 'kind' => InventorySnapshot::KIND_BASELINE, 'origin' => InventorySnapshot::ORIGIN_ESTIMATE,
            'status' => InventorySnapshot::DRAFT, 'started_at' => now(), 'cutoff_at' => now(), 'through_movement_id' => $through,
            'previous_snapshot_id' => InventorySnapshot::query()->where('status', InventorySnapshot::FINAL)->orderByDesc('version')->value('id'),
            'idempotency_key' => $key, 'notes' => $note,
        ]);
        foreach ($lines as [$item, $typed, $base]) {
            $item->load('location:id,code');
            InventorySnapshotLine::query()->create(CountMath::stamp($item, $base, null) + [
                'snapshot_id' => $snapshot->getKey(), 'supply_item_id' => $item->getKey(), 'location_code' => $item->location?->code,
                'item_name' => $item->name, 'quantity_base' => $base, 'entry' => CountMath::entryFor($item, $typed),
                'value_source' => InventorySnapshotLine::COUNTED, 'warnings' => [], 'through_movement_id' => $through,
            ]);
        }
        $tracked = SupplyItem::query()->where('active', true)->where('controlled_substance', false)->count();
        $snapshot->update([
            'status' => InventorySnapshot::FINAL, 'version' => (int) InventorySnapshot::query()->max('version') + 1, 'finalized_at' => now(),
            'finalized_name' => self::SNAPSHOT_NAME, 'counted_items' => count($lines), 'uncounted_items' => max(0, $tracked - count($lines)),
        ]);
    }
}
