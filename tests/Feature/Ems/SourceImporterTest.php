<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\CatalogItem;
use App\Models\Ems\SourceForm;
use App\Models\Ems\SourceFormItem;
use App\Models\Ems\SupplyItem;
use App\Models\Ems\SupplyItemEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class SourceImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            File::deleteDirectory($this->tmp);
        }
        parent::tearDown();
    }

    private function realPath(): string
    {
        return base_path('database/data/ems');
    }

    /** A private copy of the three source files that a test may edit. */
    private function copyOfSources(): string
    {
        $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ems-src-'.bin2hex(random_bytes(4));
        File::makeDirectory($this->tmp);
        foreach (['airway_bag_inventory.json', 'als_med_bag_inventory.json', 'captain5_supply_room_inventory.json'] as $file) {
            File::copy($this->realPath().DIRECTORY_SEPARATOR.$file, $this->tmp.DIRECTORY_SEPARATOR.$file);
        }

        return $this->tmp;
    }

    public function test_imports_the_tracked_extractions_with_verbatim_source_text(): void
    {
        $this->artisan('ems:import-sources')->assertSuccessful();

        $airway = SourceForm::query()->where('key', 'airway-bag')->where('active', true)->sole();
        $als = SourceForm::query()->where('key', 'als-med-bag')->where('active', true)->sole();
        $this->assertSame(90, $airway->items()->count());
        $this->assertSame(106, $als->items()->count());
        $this->assertSame(295, SupplyItem::query()->count());
        $this->assertSame(64, strlen($airway->sha256));

        $gloves = SourceFormItem::query()->where('item_key', 'airway.top.gloves')->sole();
        $this->assertSame('3 SETS', $gloves->min_text);
        $this->assertSame('GLOVES', $gloves->source_label);
        $this->assertSame(3, $gloves->min_qty);

        $source = json_decode((string) file_get_contents($this->realPath().'/captain5_supply_room_inventory.json'), true);
        $first = $source['rows'][0];
        $row = SupplyItem::query()->where('source_row_key', $first['row_key'])->sole();
        $this->assertSame($first['order_level_text'], $row->threshold_source_text);
        $this->assertSame($first['order_amount_text'], $row->order_amount_text);
        $this->assertSame($first['qty_per_package_text'], $row->qty_per_package_text);
        $this->assertSame($first, $row->source);
        $this->assertSame($first['parsed']['suppliers'], $row->suppliers);
    }

    public function test_variants_stay_separate_items_that_share_a_variant_group(): void
    {
        $this->artisan('ems:import-sources')->assertSuccessful();

        $iv = SourceFormItem::query()->whereIn('label', ['IV 14G', 'IV 16G'])->get();
        $this->assertCount(2, $iv);
        $this->assertCount(1, $iv->pluck('variant_group')->unique());
        $this->assertNotSame($iv[0]->item_key, $iv[1]->item_key);
    }

    public function test_reimport_of_the_same_files_changes_nothing(): void
    {
        $this->artisan('ems:import-sources')->assertSuccessful();
        $counts = [SourceForm::query()->count(), SourceFormItem::query()->count(), SupplyItem::query()->count(), CatalogItem::query()->count()];

        $result = app(\App\Services\Ems\Import\SourceImporter::class)->import($this->realPath());

        $this->assertSame($counts, [SourceForm::query()->count(), SourceFormItem::query()->count(), SupplyItem::query()->count(), CatalogItem::query()->count()]);
        $this->assertSame(['forms.airway-bag.unchanged', 'forms.als-med-bag.unchanged', 'supply_source.unchanged'], array_keys($result['counts']));
    }

    public function test_dry_run_saves_nothing(): void
    {
        $this->artisan('ems:import-sources --dry-run')->assertSuccessful();

        $this->assertSame(0, SourceForm::query()->count());
        $this->assertSame(0, SupplyItem::query()->count());
        $this->assertSame(0, CatalogItem::query()->count());
    }

    public function test_thresholds_convert_only_with_explicit_factors_and_unknowns_are_flagged(): void
    {
        $this->artisan('ems:import-sources')->assertSuccessful();

        $each = SupplyItem::query()->where('source_row_key', 'sr.p1.r01')->sole(); // "< 15U", box of 60U
        $this->assertSame(15, $each->threshold_qty_base);
        $this->assertSame(60, $each->units_per_package);

        // "< 20Pks" on a case row whose pack size is not stated: never guessed.
        $packs = SupplyItem::query()->where('source_row_key', 'sr.p1.r73')->sole();
        $this->assertNull($packs->threshold_qty_base);
        $this->assertSame('< 20Pks', $packs->threshold_source_text);
        $this->assertTrue($packs->needs_review);
        $this->assertNotEmpty($packs->review_reasons);

        $this->assertGreaterThanOrEqual(56, SupplyItem::query()->where('needs_review', true)->count());
        $this->assertSame(0, SupplyItem::query()->whereNull('threshold_qty_base')->whereNotNull('threshold_source_text')->where('needs_review', false)->count());
    }

    public function test_controlled_substances_are_flagged_and_other_narcotics_section_rows_are_not(): void
    {
        $this->artisan('ems:import-sources')->assertSuccessful();

        $controlled = SupplyItem::query()->where('controlled_substance', true)->pluck('name')->all();
        $this->assertCount(5, $controlled);
        $this->assertContains('Fentanyl (Narcotics Locker)', $controlled);

        $lock = SupplyItem::query()->where('name', 'like', 'Lock, Drug Cynch%')->first();
        $this->assertNotNull($lock);
        $this->assertFalse($lock->controlled_substance);
        $this->assertFalse($lock->fresh()->tracksStock() === false);
    }

    public function test_a_new_source_sha_becomes_a_new_active_version_and_keeps_the_old_one(): void
    {
        $dir = $this->copyOfSources();
        app(\App\Services\Ems\Import\SourceImporter::class)->import($dir);
        $old = SourceForm::query()->where('key', 'airway-bag')->sole();
        $oldItemId = SourceFormItem::query()->where('source_form_id', $old->id)->where('item_key', 'airway.top.gloves')->value('id');

        $path = $dir.'/airway_bag_inventory.json';
        $document = json_decode((string) file_get_contents($path), true);
        $document['source']['sha256'] = str_repeat('a', 64);
        $document['source']['version_label'] = 'rev B';
        file_put_contents($path, json_encode($document, JSON_UNESCAPED_SLASHES));
        app(\App\Services\Ems\Import\SourceImporter::class)->import($dir);

        $this->assertSame(2, SourceForm::query()->where('key', 'airway-bag')->count());
        $this->assertFalse($old->fresh()->active);
        $this->assertSame('rev B', SourceForm::query()->where('key', 'airway-bag')->where('active', true)->sole()->version_label);
        $this->assertNotNull(SourceFormItem::query()->find($oldItemId), 'The old version keeps its items.');
        $this->assertSame(180, SourceFormItem::query()->whereIn('source_form_id', SourceForm::query()->where('key', 'airway-bag')->pluck('id'))->count());
    }

    public function test_corrected_extraction_of_the_same_source_updates_rows_but_not_person_edited_fields(): void
    {
        $dir = $this->copyOfSources();
        app(\App\Services\Ems\Import\SourceImporter::class)->import($dir);

        $item = SupplyItem::query()->where('source_row_key', 'sr.p1.r01')->sole();
        $item->update(['threshold_qty_base' => 40]);
        SupplyItemEvent::query()->create([
            'supply_item_id' => $item->id, 'field' => 'threshold_qty_base', 'before' => ['value' => 15], 'after' => ['value' => 40],
            'reason' => 'Counted usage', 'created_at' => now(),
        ]);

        $path = $dir.'/captain5_supply_room_inventory.json';
        $document = json_decode((string) file_get_contents($path), true);
        $document['rows'][0]['item_text'] = 'Burn Gel 4" x 4" Dressing (corrected)';
        file_put_contents($path, json_encode($document, JSON_UNESCAPED_SLASHES));
        $result = app(\App\Services\Ems\Import\SourceImporter::class)->import($dir);

        $this->assertArrayHasKey('supply_rows.updated', $result['counts']);
        $item->refresh();
        $this->assertSame('Burn Gel 4" x 4" Dressing (corrected)', $item->name);
        $this->assertSame(40, $item->threshold_qty_base, 'A threshold an authorized person set is never overwritten.');
        $this->assertSame(1, SourceForm::query()->where('key', 'captain5-supply-room')->count());
    }

    public function test_unknown_schema_is_refused(): void
    {
        $dir = $this->copyOfSources();
        file_put_contents($dir.'/airway_bag_inventory.json', json_encode(['schema' => 'something.else']));

        $this->expectException(RuntimeException::class);
        app(\App\Services\Ems\Import\SourceImporter::class)->import($dir);
    }

    private function editSupplyDocument(string $dir, \Closure $edit): void
    {
        $path = $dir.'/captain5_supply_room_inventory.json';
        $document = json_decode((string) file_get_contents($path), true);
        $document = $edit($document);
        file_put_contents($path, json_encode($document, JSON_UNESCAPED_SLASHES));
    }

    public function test_a_duplicate_key_in_one_document_fails_the_import_and_changes_nothing(): void
    {
        $dir = $this->copyOfSources();
        $this->editSupplyDocument($dir, function (array $d): array {
            $d['rows'][1]['row_key'] = $d['rows'][0]['row_key'];

            return $d;
        });

        try {
            app(\App\Services\Ems\Import\SourceImporter::class)->import($dir);
            $this->fail('A duplicate row_key was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('the row_key "sr.p1.r01" more than once', $exception->getMessage());
            $this->assertStringContainsString('nothing was changed', $exception->getMessage());
        }
        $this->assertSame(0, SupplyItem::query()->count());
        $this->assertSame(0, SourceForm::query()->count());

        $dir2 = $this->copyOfSources();
        $path = $dir2.'/airway_bag_inventory.json';
        $document = json_decode((string) file_get_contents($path), true);
        $document['sections'][1]['items'][0]['item_ref'] = $document['sections'][0]['items'][0]['item_ref'];
        file_put_contents($path, json_encode($document, JSON_UNESCAPED_SLASHES));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('more than once');
        app(\App\Services\Ems\Import\SourceImporter::class)->import($dir2);
    }

    public function test_rows_missing_from_a_newer_extraction_are_flagged_for_review_and_never_deleted(): void
    {
        $dir = $this->copyOfSources();
        app(\App\Services\Ems\Import\SourceImporter::class)->import($dir);
        $count = SupplyItem::query()->count();

        $dropped = null;
        $this->editSupplyDocument($dir, function (array $d) use (&$dropped): array {
            $dropped = array_pop($d['rows'])['row_key'];

            return $d;
        });
        $result = app(\App\Services\Ems\Import\SourceImporter::class)->import($dir);

        $this->assertSame($count, SupplyItem::query()->count(), 'Nothing is deleted.');
        $item = SupplyItem::query()->where('source_row_key', $dropped)->sole();
        $this->assertTrue($item->needs_review);
        $this->assertContains('Not in the latest source document', $item->review_reasons);
        $this->assertSame(1, $result['counts']['supply_rows.flagged_absent']);

        // Importing the same thing again does not stack the reason.
        $this->editSupplyDocument($dir, function (array $d): array {
            $d['source']['notes'][] = 'touched';

            return $d;
        });
        app(\App\Services\Ems\Import\SourceImporter::class)->import($dir);
        $this->assertSame(1, count(array_keys($item->fresh()->review_reasons, 'Not in the latest source document', true)));
    }

    public function test_a_corrected_extraction_that_moves_a_conversion_leaves_an_audit_row_and_keeps_updating(): void
    {
        $dir = $this->copyOfSources();
        app(\App\Services\Ems\Import\SourceImporter::class)->import($dir);
        $item = SupplyItem::query()->where('source_row_key', 'sr.p1.r01')->sole();
        $this->assertSame(60, $item->units_per_package);

        foreach ([50, 40] as $size) {
            $this->editSupplyDocument($dir, function (array $d) use ($size): array {
                $d['rows'][0]['parsed']['package']['base_units_per_package'] = $size;

                return $d;
            });
            app(\App\Services\Ems\Import\SourceImporter::class)->import($dir);
        }

        $this->assertSame(40, $item->fresh()->units_per_package, 'Importer audit rows are not mistaken for a person edit.');
        $events = SupplyItemEvent::query()->where('supply_item_id', $item->id)->where('field', 'units_per_package')->orderBy('id')->get();
        $this->assertSame([[60, 50], [50, 40]], $events->map(fn ($e): array => [$e->before['value'], $e->after['value']])->all());
        $this->assertSame(['Source import'], $events->pluck('actor_name')->unique()->all());
        $this->assertNull($events->first()->user_id);
        $this->assertStringContainsString('Corrected source extraction', $events->first()->reason);
    }

    public function test_every_imported_text_fits_its_column(): void
    {
        $this->artisan('ems:import-sources')->assertSuccessful();

        $this->assertSame(0, SourceFormItem::query()->whereRaw('length(label) > 255 or length(coalesce(min_text, \'\')) > 120 or length(item_key) > 160 or length(coalesce(variant_group, \'\')) > 160')->count());
        $this->assertSame(0, SupplyItem::query()->whereRaw('length(coalesce(threshold_source_text, \'\')) > 120 or length(name) > 255 or length(coalesce(order_amount_text, \'\')) > 160 or length(coalesce(qty_per_package_text, \'\')) > 160')->count());
    }
}
