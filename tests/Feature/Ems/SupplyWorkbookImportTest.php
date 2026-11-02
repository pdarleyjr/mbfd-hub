<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\CatalogItem;
use App\Models\Ems\CatalogSupplyLink;
use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\InventorySnapshotLine;
use App\Models\Ems\StockMovement;
use App\Models\Ems\StorageLocation;
use App\Models\Ems\SupplyItem;
use App\Models\Ems\SupplyItemEvent;
use App\Services\Ems\EmsActor;
use App\Services\Ems\Import\SupplyWorkbookCounts;
use App\Services\Ems\Import\SupplyWorkbookImporter;
use App\Services\Ems\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/**
 * The workbook import (docs/ems/captain5-medical-inventory-workflow.md section 9) on a small fixture, plus a
 * structure check on the real tracked extraction. The fixture rows are the BuildsEmsWorld supply items:
 *   sr.t.01 gauze (box of 200, A2)   sr.t.02 tape (box of 24, A2)   sr.t.03 stethoscope (each, B1)
 *   sr.t.04 gloves (unknown pack)    sr.t.05 cardizem (box of 5)     sr.t.06 fentanyl (controlled)
 *   sr.t.07 gauze sterile (box of 50, A3)
 */
final class SupplyWorkbookImportTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private const SHA = '1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ems-workbook-'.bin2hex(random_bytes(4));
        File::makeDirectory($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ fixture helpers

    /** @param array<string, mixed> $over */
    private function entry(string $key, array $over = []): array
    {
        return array_replace_recursive([
            'source_row_key' => $key, 'new' => false, 'count_row' => 3, 'inventory_row' => 3, 'item_text' => $key,
            'shelf' => ['code' => null, 'source' => null, 'inventory_loc' => null, 'count_loc' => null, 'conflict' => false],
            'threshold' => ['text' => null, 'qty_base' => null, 'status' => 'none', 'class' => 'none', 'unit' => null, 'reason' => null],
            'package' => ['unit' => null, 'units_per_package' => null, 'text' => null],
            'count' => null, 'count_unit' => 'each',
        ], $over);
    }

    /** @return array<string, mixed> */
    private function counted(string $kind, ?int $value, ?string $unit = null, ?int $baseline = null, ?string $countUnit = 'each', ?int $note = null): array
    {
        return ['kind' => $kind, 'value' => $value, 'unit' => $unit, 'units_note' => $note, 'count_unit' => $countUnit, 'baseline_base' => $baseline, 'exception' => null, 'message' => null, 'action' => null];
    }

    /** @return array<string, mixed> */
    private function each(int $qty, string $status = 'converted'): array
    {
        return ['text' => "< {$qty}U", 'qty_base' => $qty, 'status' => $status, 'class' => 'each', 'unit' => 'each', 'reason' => null];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $links
     */
    private function write(array $items, array $links = []): string
    {
        $baselines = count(array_filter($items, fn (array $i): bool => ($i['count']['baseline_base'] ?? null) !== null));
        $doc = [
            'schema' => SupplyWorkbookImporter::SCHEMA,
            'source' => ['file_name' => 'fixture.xlsx', 'sha256' => self::SHA, 'row_counts' => ['new_items' => count(array_filter($items, fn (array $i): bool => $i['new'])), 'baselines' => $baselines]],
            'items' => $items, 'exceptions' => [],
        ];
        file_put_contents($this->dir.'/supply_room_workbook_2026.json', json_encode($doc));
        file_put_contents($this->dir.'/catalog_supply_links.json', json_encode($links + [
            'schema' => SupplyWorkbookImporter::LINKS_SCHEMA, 'links' => [], 'not_stocked' => [], 'open' => [],
        ]));

        return $this->dir.'/supply_room_workbook_2026.json';
    }

    /** @return list<array<string, mixed>> */
    private function standardItems(): array
    {
        return [
            $this->entry('sr.t.01', [
                'shelf' => ['code' => 'A2'], 'threshold' => ['text' => '< 50U', 'qty_base' => 50, 'status' => 'converted', 'class' => 'each', 'unit' => 'each'],
                'package' => ['unit' => 'box', 'units_per_package' => 200],
                'count' => $this->counted('unit', 3, 'box', 600, 'box'), 'count_unit' => 'box',
            ]),
            $this->entry('sr.t.02', [
                'shelf' => ['code' => 'A3'], 'threshold' => $this->each(12), 'package' => ['unit' => 'box', 'units_per_package' => 24],
                'count' => $this->counted('bare', 30, null, 30),
            ]),
            $this->entry('sr.t.03', ['shelf' => ['code' => 'B1'], 'threshold' => $this->each(3), 'package' => ['unit' => 'each'], 'count' => $this->counted('bare', 0, null, 0)]),
            $this->entry('sr.t.04', ['shelf' => ['code' => 'C1'], 'count' => $this->counted('blank', null, null, null, null)]),
            $this->entry('sr.t.05', [
                'shelf' => ['code' => 'D1'], 'package' => ['unit' => 'box', 'units_per_package' => 5],
                'threshold' => ['text' => '< 5BX', 'qty_base' => 25, 'status' => 'converted', 'class' => 'package', 'unit' => 'box'],
                'count' => $this->counted('bare', 12, null, null, 'box'), 'count_unit' => 'box',
            ]),
            // A stale extraction that (wrongly) planned a count for a controlled item: the importer must still refuse.
            $this->entry('sr.t.06', ['shelf' => ['code' => 'LOCK'], 'count' => $this->counted('bare', 5, null, 5)]),
            $this->entry('sr.t.07', ['shelf' => ['code' => 'A9'], 'threshold' => $this->each(77), 'package' => ['unit' => 'box', 'units_per_package' => 50]]),
            $this->entry('wb.c901', [
                'new' => true, 'count_row' => 58, 'inventory_row' => null, 'item_text' => 'Vial Adapters',
                'shelf' => ['code' => 'B4', 'source' => 'count', 'count_loc' => 'B4'], 'count' => $this->counted('bare', 27, null, 27),
                'new_item' => [
                    'name' => 'Vial Adapters', 'bag_alias_text' => null, 'section_key' => 'supplies', 'catalog_ref' => 'wb-vial-adapters', 'refrigerated' => false,
                    'controlled_substance' => false, 'item_numbers' => [], 'qty_per_package_text' => null, 'order_amount_text' => null,
                    'needs_review' => true, 'review_reasons' => ['Added from the supply room workbook.'], 'reason' => 'test',
                ],
            ]),
        ];
    }

    private function importer(): SupplyWorkbookImporter
    {
        return app(SupplyWorkbookImporter::class);
    }

    /** @return array<string, mixed> */
    private function apply(string $path, bool $apply = true): array
    {
        return $this->importer()->run($path, dirname($path).'/catalog_supply_links.json', $apply);
    }

    /** Give one item an existing counted line, as a finished physical count would. */
    private function countedAlready(string $rowKey, int $quantity): void
    {
        $snapshot = InventorySnapshot::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'kind' => InventorySnapshot::KIND_COUNT, 'status' => InventorySnapshot::DRAFT, 'started_at' => now(),
        ]);
        InventorySnapshotLine::query()->create([
            'snapshot_id' => $snapshot->id, 'supply_item_id' => $this->supplyItem($rowKey)->id, 'location_code' => 'A2', 'item_name' => 'x',
            'quantity_base' => $quantity, 'entry' => ['each' => $quantity], 'value_source' => InventorySnapshotLine::COUNTED, 'through_movement_id' => 0,
        ]);
        $snapshot->update(['status' => InventorySnapshot::FINAL, 'version' => 1, 'finalized_at' => now(), 'through_movement_id' => 0]);
    }

    private function line(string $rowKey): ?InventorySnapshotLine
    {
        return InventorySnapshotLine::query()->where('supply_item_id', $this->supplyItem($rowKey)->id)->first();
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        return [
            'snapshots' => InventorySnapshot::query()->count(), 'lines' => InventorySnapshotLine::query()->count(), 'events' => SupplyItemEvent::query()->count(),
            'items' => SupplyItem::query()->count(), 'locations' => StorageLocation::query()->count(), 'catalog' => CatalogItem::query()->count(),
            'links' => CatalogSupplyLink::query()->count(),
        ];
    }

    // ------------------------------------------------------------------ the import

    public function test_a_dry_run_writes_nothing(): void
    {
        $before = $this->tableCounts();
        $items = SupplyItem::query()->orderBy('id')->get()->map->only(['id', 'location_id', 'threshold_qty_base', 'count_unit'])->all();

        $report = $this->apply($this->write($this->standardItems()), false);

        $this->assertTrue($report['dry_run']);
        $this->assertFalse($report['applied']);
        $this->assertSame(4, $report['counts']['starting_counts']);
        $this->assertSame(1, $report['counts']['items_to_create']);
        $this->assertSame($before, $this->tableCounts());
        $this->assertSame($items, SupplyItem::query()->orderBy('id')->get()->map->only(['id', 'location_id', 'threshold_qty_base', 'count_unit'])->all());
    }

    public function test_apply_writes_one_final_baseline_snapshot_and_a_second_apply_does_nothing(): void
    {
        $path = $this->write($this->standardItems());
        $report = $this->apply($path);

        $this->assertTrue($report['applied']);
        $snapshot = InventorySnapshot::query()->where('idempotency_key', 'workbook:'.self::SHA)->sole();
        $this->assertSame(InventorySnapshot::KIND_BASELINE, $snapshot->kind);
        $this->assertSame(InventorySnapshot::ORIGIN_WORKBOOK, $snapshot->origin);
        $this->assertSame(InventorySnapshot::FINAL, $snapshot->status);
        $this->assertSame(1, $snapshot->version);
        $this->assertSame('Supply Room Count workbook', $snapshot->finalized_name);
        $this->assertSame((new StockLedger)->maxMovementId(), (int) $snapshot->through_movement_id);
        $this->assertStringContainsString(self::SHA, (string) $snapshot->notes);
        $this->assertSame(4, $snapshot->lines()->count());
        $this->assertSame(4, $snapshot->counted_items);

        $after = $this->tableCounts();
        $again = $this->apply($path);
        $this->assertTrue($again['noop']);
        $this->assertFalse($again['applied']);
        $this->assertSame($after, $this->tableCounts());
    }

    public function test_blank_is_never_zero_and_zero_stays_zero(): void
    {
        $this->apply($this->write($this->standardItems()));

        $this->assertNull($this->line('sr.t.04'), 'A blank quantity writes no line at all.');
        $zero = $this->line('sr.t.03');
        $this->assertSame(0, $zero->quantity_base);
        $this->assertSame('counted', $zero->value_source);
        $this->assertSame(['each' => 0], $zero->entry);
    }

    public function test_a_box_count_converts_with_the_explicit_package_size_and_stamps_the_unit(): void
    {
        $this->apply($this->write($this->standardItems()));

        $line = $this->line('sr.t.01');
        $this->assertSame(600, $line->quantity_base);
        $this->assertSame(['boxes' => 3], $line->entry);
        $this->assertSame('box', $line->count_unit);
        $this->assertSame(200, $line->units_per_count_unit);
        $this->assertSame(3, $line->counted_value);
        $this->assertSame('A2', $line->location_code);
        $this->assertSame('box', $this->supplyItem('sr.t.01')->count_unit);
    }

    public function test_an_ambiguous_bare_number_gets_no_baseline_but_the_count_unit_is_set(): void
    {
        $this->apply($this->write($this->standardItems()));

        $this->assertNull($this->line('sr.t.05'));
        $this->assertSame('box', $this->supplyItem('sr.t.05')->count_unit, "Monday's count is typed in the same unit as the order level.");
    }

    public function test_a_controlled_item_never_gets_a_baseline(): void
    {
        $report = $this->apply($this->write($this->standardItems()));

        $this->assertNull($this->line('sr.t.06'));
        $this->assertContains('config_changed', array_column($report['exceptions'], 'group'), 'The extraction said 5; the importer still refused.');
    }

    public function test_movements_after_the_baseline_subtract_but_earlier_ones_do_not(): void
    {
        $ledger = new StockLedger;
        $tape = $this->supplyItem('sr.t.02');
        $ledger->record($tape, StockMovement::ISSUED, -5, EmsActor::system(), 'before-workbook-0001');

        $this->apply($this->write($this->standardItems()));
        $this->assertSame(30, $ledger->current(collect([$tape]))[$tape->id]['quantity'], 'The earlier issue stays in history and is not subtracted.');

        $ledger->record($tape, StockMovement::ISSUED, -4, EmsActor::system(), 'after-workbook-0001');
        $this->assertSame(26, $ledger->current(collect([$tape->fresh()]))[$tape->id]['quantity']);
    }

    public function test_an_item_that_already_has_a_counted_line_is_not_overwritten(): void
    {
        $this->countedAlready('sr.t.02', 99);

        $report = $this->apply($this->write($this->standardItems()));

        $this->assertSame(1, $report['counts']['already_counted_skipped']);
        $this->assertSame([99], InventorySnapshotLine::query()->where('supply_item_id', $this->supplyItem('sr.t.02')->id)->pluck('quantity_base')->all());
        $this->assertSame(3, InventorySnapshot::query()->where('kind', InventorySnapshot::KIND_BASELINE)->sole()->lines()->count());
        $this->assertContains('already_counted', array_column($report['exceptions'], 'group'));
    }

    public function test_a_field_a_person_changed_is_kept_and_other_changes_are_audited(): void
    {
        $person = $this->emsCaptain('Cap Tain');
        $gauze = $this->supplyItem('sr.t.07');
        $gauze->update(['threshold_qty_base' => 20, 'threshold_source_text' => '20 boxes']);
        SupplyItemEvent::query()->create([
            'supply_item_id' => $gauze->id, 'user_id' => $person->id, 'actor_name' => 'Cap Tain', 'field' => 'threshold_qty_base',
            'before' => ['value' => 20], 'after' => ['value' => 20], 'reason' => 'Set by captain',
        ]);

        $report = $this->apply($this->write($this->standardItems()));

        $this->assertSame(20, $gauze->fresh()->threshold_qty_base, "A person's order level is not overwritten.");
        $this->assertSame('20 boxes', $gauze->fresh()->threshold_source_text);
        $this->assertSame('A9', StorageLocation::query()->find($gauze->fresh()->location_id)?->code, 'The shelf was not edited, so it follows the workbook.');
        $this->assertGreaterThanOrEqual(1, $report['counts']['person_edits_respected']);

        $event = SupplyItemEvent::query()->where('supply_item_id', $gauze->id)->where('field', 'location_id')->sole();
        $this->assertSame('System', $event->actor_name);
        $this->assertNull($event->user_id);
        $this->assertSame('Workbook import 2026', $event->reason);
    }

    public function test_a_person_made_link_is_never_replaced_and_reviewed_links_are_written(): void
    {
        $person = $this->emsCaptain();
        $mine = CatalogItem::query()->create(['key' => 'test-mine', 'name' => 'Mine']);
        $theirs = CatalogItem::query()->create(['key' => 'test-reviewed', 'name' => 'Reviewed']);
        $gone = CatalogItem::query()->create(['key' => 'test-not-stocked', 'name' => 'Not stocked']);
        CatalogSupplyLink::query()->create(['catalog_item_id' => $mine->id, 'supply_item_id' => $this->supplyItem('sr.t.03')->id, 'not_stocked' => false, 'source' => 'person', 'decided_by_user_id' => $person->id, 'decided_name' => 'Cap Tain']);

        $path = $this->write($this->standardItems(), ['links' => [
            ['catalog_ref' => 'test-mine', 'source_row_key' => 'sr.t.01', 'reason' => 'x'],
            ['catalog_ref' => 'test-reviewed', 'source_row_key' => 'wb.c901', 'reason' => 'A new item can be a target.'],
        ], 'not_stocked' => [['catalog_ref' => 'test-not-stocked', 'reason' => 'Vehicle equipment.']]]);
        $report = $this->apply($path);

        $this->assertSame(1, $report['links']['kept_person']);
        $this->assertSame($this->supplyItem('sr.t.03')->id, CatalogSupplyLink::query()->where('catalog_item_id', $mine->id)->value('supply_item_id'));
        $this->assertSame('person', CatalogSupplyLink::query()->where('catalog_item_id', $mine->id)->value('source'));
        $reviewed = CatalogSupplyLink::query()->where('catalog_item_id', $theirs->id)->sole();
        $this->assertSame('reviewed', $reviewed->source);
        $this->assertSame($this->supplyItem('wb.c901')->id, $reviewed->supply_item_id);
        $stocked = CatalogSupplyLink::query()->where('catalog_item_id', $gone->id)->sole();
        $this->assertTrue($stocked->not_stocked);
        $this->assertNull($stocked->supply_item_id);

        $this->apply($path); // a repeat leaves the same rows
        $this->assertSame(3, CatalogSupplyLink::query()->count());
    }

    public function test_new_items_get_a_shelf_a_catalog_identity_and_a_starting_count(): void
    {
        $this->apply($this->write($this->standardItems()));

        $item = $this->supplyItem('wb.c901');
        $this->assertSame('Vial Adapters', $item->name);
        $this->assertSame('B4', StorageLocation::query()->find($item->location_id)?->code);
        $this->assertSame('wb-vial-adapters', $item->catalogItem->key);
        $this->assertTrue($item->needs_review);
        $this->assertSame('each', $item->count_unit);
        $this->assertSame(27, $this->line('wb.c901')->quantity_base);
        $this->assertGreaterThan(StorageLocation::query()->whereIn('code', ['A2', 'A3', 'B1', 'C1', 'D1', 'LOCK'])->max('sort'), (int) StorageLocation::query()->where('code', 'B4')->value('sort'));
    }

    public function test_every_count_unit_after_the_import_is_each_or_the_package_unit_with_a_known_size(): void
    {
        $this->apply($this->write($this->standardItems()));

        foreach (SupplyItem::query()->get() as $item) {
            $this->assertTrue(
                SupplyWorkbookCounts::valid($item->count_unit, $item->package_unit, $item->units_per_package),
                "{$item->name} has count unit {$item->count_unit} but package {$item->package_unit} x {$item->units_per_package}",
            );
        }
    }

    public function test_a_file_with_the_wrong_schema_or_edited_totals_is_refused_and_changes_nothing(): void
    {
        $path = $this->write($this->standardItems());
        $doc = json_decode((string) file_get_contents($path), true);
        $doc['source']['row_counts']['baselines'] = 99;
        file_put_contents($path, json_encode($doc));
        $before = $this->tableCounts();

        try {
            $this->apply($path);
            $this->fail('A file whose totals do not match its items must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('totals', $e->getMessage());
        }
        $this->assertSame($before, $this->tableCounts());

        $doc['schema'] = 'something.else';
        file_put_contents($path, json_encode($doc));
        $this->expectException(\RuntimeException::class);
        $this->apply($path);
    }

    public function test_the_artisan_command_is_a_dry_run_unless_apply_is_given(): void
    {
        $path = $this->write($this->standardItems());

        $this->artisan('ems:apply-supply-workbook', ['--file' => $path])->expectsOutputToContain('Dry run only')->assertExitCode(0);
        $this->assertSame(0, InventorySnapshot::query()->count());

        $this->artisan('ems:apply-supply-workbook', ['--file' => $path, '--apply' => true])->expectsOutputToContain('Applied.')->assertExitCode(0);
        $this->assertSame(1, InventorySnapshot::query()->count());

        $this->artisan('ems:apply-supply-workbook', ['--file' => $path, '--apply' => true])->expectsOutputToContain('already applied')->assertExitCode(0);
        $this->assertSame(1, InventorySnapshot::query()->count());
    }

    // ------------------------------------------------------------------ the real tracked files

    public function test_the_tracked_workbook_extraction_is_well_formed_and_its_counts_follow_the_rules(): void
    {
        $workbook = $this->importer()->loadWorkbook(base_path('database/data/ems/supply_room_workbook_2026.json'));
        $tracked = json_decode((string) file_get_contents(base_path('database/data/ems/captain5_supply_room_inventory.json')), true);
        $rows = collect($tracked['rows'])->keyBy('row_key');

        $this->assertSame('783ff238c884de9f3244099668865a1b9f8077e813c4e541c86663eb768c5da5', $workbook['source']['sha256']);
        $this->assertSame($tracked['source']['sha256'], $workbook['source']['tracked_supply_source_sha256']);
        $this->assertSame(295, collect($workbook['items'])->where('new', false)->count(), 'Every Hub supply item has an Inventory sheet row.');
        $this->assertSame(7, collect($workbook['items'])->where('new', true)->count());

        foreach ($workbook['items'] as $entry) {
            $key = $entry['source_row_key'];
            if ($entry['new']) {
                $this->assertStringStartsWith('wb.c', $key);

                continue;
            }
            $this->assertTrue($rows->has($key), "{$key} is not a row of the tracked supply room extraction");
            if ($entry['count'] === null) {
                continue;
            }
            // The importer's rules, on the workbook's own package data (or the Hub's when the workbook has none), give the extraction's answer.
            $row = $rows[$key];
            $unit = $entry['package']['unit'] ?? $row['parsed']['package']['unit'];
            $per = $entry['package']['units_per_package'] ?? $row['parsed']['package']['base_units_per_package'];
            $resolved = SupplyWorkbookCounts::resolve($entry['count'], $entry['threshold'], $unit, $per, (bool) $row['flags']['controlled_substance']);
            $this->assertSame($entry['count']['baseline_base'], $resolved['baseline_base'], "{$key} baseline");
            $this->assertSame($entry['count_unit'], SupplyWorkbookCounts::countUnit($resolved['count_unit'], $entry['threshold'], $unit, $per), "{$key} count unit");
            if ($row['flags']['controlled_substance']) {
                $this->assertNull($entry['count']['baseline_base'], "{$key} is controlled and must never get a baseline");
            }
            if ($entry['count']['kind'] === 'blank') {
                $this->assertNull($entry['count']['baseline_base'], "{$key} has a blank quantity");
            }
        }
    }

    public function test_the_real_extraction_applies_cleanly_to_the_real_supply_room_list(): void
    {
        app(\App\Services\Ems\Import\SourceImporter::class)->import(base_path('database/data/ems'));
        $path = base_path('database/data/ems/supply_room_workbook_2026.json');
        $links = base_path('database/data/ems/catalog_supply_links.json');
        $doc = json_decode((string) file_get_contents($path), true);
        $baselines = collect($doc['items'])->filter(fn (array $i): bool => ($i['count']['baseline_base'] ?? null) !== null)->count();
        $linkFile = json_decode((string) file_get_contents($links), true);

        $dry = $this->importer()->run($path, $links, false);
        $groups = array_count_values(array_column($dry['exceptions'], 'group'));
        $this->assertSame(0, $dry['counts']['items_missing']);
        $this->assertSame(7, $dry['counts']['items_to_create']);
        $this->assertSame($baselines, $dry['counts']['starting_counts']);
        foreach (['config_changed', 'item_missing', 'person_edit', 'already_counted', 'link_skipped', 'row_key_collision'] as $unexpected) {
            $this->assertArrayNotHasKey($unexpected, $groups, "{$unexpected} should not occur on the real data");
        }
        $this->assertSame(0, InventorySnapshot::query()->count(), 'The dry run wrote nothing.');

        $applied = $this->importer()->run($path, $links, true);
        $this->assertSame($baselines, $applied['snapshot']['lines']);
        $this->assertSame($baselines, InventorySnapshotLine::query()->where('value_source', 'counted')->count());
        $this->assertSame(7, SupplyItem::query()->where('source_row_key', 'like', 'wb.c%')->count());
        $this->assertSame(count($linkFile['links']) + count($linkFile['not_stocked']), CatalogSupplyLink::query()->count());
        $this->assertSame(0, SupplyItem::query()->where('controlled_substance', true)->whereIn('id', InventorySnapshotLine::query()->pluck('supply_item_id'))->count(), 'No controlled item has a count.');
        foreach (SupplyItem::query()->where('source_row_key', 'like', 'sr.p%')->orWhere('source_row_key', 'like', 'wb.%')->get() as $item) {
            $this->assertTrue(SupplyWorkbookCounts::valid($item->count_unit, $item->package_unit, $item->units_per_package), "{$item->name}: count unit {$item->count_unit}");
        }

        $again = $this->importer()->run($path, $links, true);
        $this->assertTrue($again['noop']);
        $this->assertSame(1, InventorySnapshot::query()->count());

        $covers = SupplyItem::query()->where('source_row_key', 'sr.p1.r06')->sole();
        $this->assertSame('box', $covers->count_unit);
        $this->assertSame(69750, InventorySnapshotLine::query()->where('supply_item_id', $covers->id)->value('quantity_base'), '279 boxes of 250.');
        $this->assertNull(InventorySnapshotLine::query()->where('supply_item_id', SupplyItem::query()->where('source_row_key', 'sr.p4.r66')->value('id'))->first(), 'AAA Batteries is ambiguous.');
    }

    public function test_the_tracked_link_mapping_points_only_at_rows_and_form_items_that_exist(): void
    {
        $links = $this->importer()->loadLinks(base_path('database/data/ems/catalog_supply_links.json'));
        $workbook = json_decode((string) file_get_contents(base_path('database/data/ems/supply_room_workbook_2026.json')), true);
        $tracked = json_decode((string) file_get_contents(base_path('database/data/ems/captain5_supply_room_inventory.json')), true);
        $rows = collect($tracked['rows'])->pluck('row_key')->merge(collect($workbook['items'])->where('new', true)->pluck('source_row_key'))->flip();

        $this->assertSame(158, $links['summary']['catalog_items_without_link']);
        $this->assertSame(158, count($links['links']) + count($links['not_stocked']) + count($links['open']));
        foreach ($links['links'] as $link) {
            $this->assertTrue($rows->has($link['source_row_key']), "{$link['catalog_ref']} points at a missing row");
            $this->assertNotSame('', trim($link['reason']));
        }
        $this->assertSame(count($links['links']), collect($links['links'])->pluck('catalog_ref')->unique()->count(), 'One decision per form item.');
    }
}
