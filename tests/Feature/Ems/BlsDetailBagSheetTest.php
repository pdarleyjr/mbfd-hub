<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\SourceForm;
use App\Models\Ems\SourceFormItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\Feature\Ems\Concerns\ImportsTrackedList;
use Tests\TestCase;

/**
 * The paper "BLS DETAIL BAG" (REV 2020) against the BLS Detail Bag list.
 * SHEET is the transcription in sheet reading order: section key | printed text | label | AMOUNT | AMOUNT number | AMOUNT unit |
 * "Fill if <" | "Fill if <" number | response type | extras (loc, qual, band, cad, rev, vg, vl) | catalog ref. "~" is empty.
 * Every printed row has to be on the form, in this order, with the values as printed.
 */
final class BlsDetailBagSheetTest extends TestCase
{
    use BuildsEmsWorld;
    use ImportsTrackedList;
    use RefreshDatabase;

    private const KEY = 'bls-detail-bag';

    private const FILE = 'bls_detail_bag_inventory.json';

    private const SHA256 = '7dba064af14b43d107d4c5d00fae6341346ba2167a5f52db5611983b542cbbc4';

    private const ITEM_PREFIX = 'bls.';

    /** The sections as the form shows them: key => [label, item count]. */
    private const SECTIONS = [
        'bls-front-pouch' => ['Front Pouch', 3],
        'bls-left-side' => ['Left Side (6)', 4],
        'bls-right-side' => ['Right Side (5)', 4],
        'bls-pouch-4' => ['Pouch 4', 8],
        'bls-pouch-3' => ['Pouch 3', 3],
        'bls-pouch-2' => ['Pouch 2', 5],
        'bls-compartment-1' => ['Compartment 1', 9],
        'bls-main-compartment' => ['Main Compartment', 8],
    ];

    /** Catalog keys this list shares with the three older lists / keys only this and the other new lists use. */
    private const CATALOG_REUSED = 26;

    private const CATALOG_NEW = 18;

    private const SHEET = <<<'SHEET'
bls-front-pouch|AED|AED|1|1|~|~|~|quantity|~|aed
bls-front-pouch|PEDI PADS|Pedi pads|1|1|~|~|~|quantity|~|aed-pads-pedi
bls-front-pouch|ADULT PADS|Adult pads|1|1|~|~|~|quantity|~|aed-pads-adult
bls-left-side|ICE PACKS|Ice packs|4|4|~|~|~|quantity|~|ice-pack
bls-left-side|BAND AIDES 1X3"|Band aids 1x3"|1 BOX|1|box|~|~|quantity|~|tv-band-aids-1-x-3
bls-left-side|BAND AIDES 2X3"|Band aids 2x3"|10|10|~|~|~|quantity|~|band-aids-2x3
bls-left-side|VOMEX BAG|Vomex bag|2|2|~|~|~|quantity|~|vomex-bag
bls-right-side|GLOVES|Gloves|5|5|~|~|~|quantity|~|gloves-bls-detail-bag
bls-right-side|N95 MASKS|N95 masks|2|2|~|~|~|quantity|~|n95-respirator
bls-right-side|GOGGLES|Goggles|1|1|~|~|~|quantity|~|goggles
bls-right-side|TYVEX SLEEVES|Tyvek sleeves|4|4|~|~|~|quantity|~|tv-tyvek-sleeves
bls-pouch-4|ACE BANDAGE 4"|Ace bandage 4"|2|2|~|~|~|quantity|~|ace-bandage-4in
bls-pouch-4|ACE BANDAGE 6"|Ace bandage 6"|2|2|~|~|~|quantity|~|ace-bandage-6in
bls-pouch-4|KLING 6"|Kling 6"|2|2|~|~|~|quantity|~|kling-6in
bls-pouch-4|KLING 4"|Kling 4"|2|2|~|~|~|quantity|~|kling-4in
bls-pouch-4|KLING 2"|Kling 2"|2|2|~|~|~|quantity|~|kling-2in
bls-pouch-4|COMBI PAD 5X9"|Combi pad 5x9"|2|2|~|~|~|quantity|~|combi-pad-5x9
bls-pouch-4|TRIANGLE BANDAGE|Triangle bandage|2|2|~|~|~|quantity|~|triangle-bandage
bls-pouch-4|GAUZE 4X4"|Gauze 4x4"|8|8|~|~|~|quantity|~|gauze-4x4
bls-pouch-3|BURN GEL SMALL|Burn gel small|4|4|~|~|~|quantity|~|burn-gel-small
bls-pouch-3|BURN GEL 2X6"|Burn gel 2x6"|4|4|~|~|~|quantity|~|tv-burn-gel-2-x-6-dressing
bls-pouch-3|BURN GEL 4X4|Burn gel 4x4|4|4|~|~|~|quantity|~|tv-burn-gel-4-x-4-dressing
bls-pouch-2|BVM ADULT|BVM adult|1|1|~|~|~|quantity|~|bvm-adult
bls-pouch-2|BVM PEDI|BVM pedi|1|1|~|~|~|quantity|~|tv-bvm-pedi
bls-pouch-2|OPAS 2 ADULT|OPAs 2 adult|2|2|~|~|~|quantity|~|opas-adult
bls-pouch-2|OPAS 1 CHILD|OPAs 1 child|1|1|~|~|~|quantity|~|opas-child
bls-pouch-2|OPAS 1 INF|OPAs 1 infant|1|1|~|~|~|quantity|~|opas-infant
bls-compartment-1|CAT TOURNIQUET|CAT tourniquet|1|1|~|~|~|quantity|~|cat-tourniquet
bls-compartment-1|TRAMUA SHEARS|Trauma shears|1|1|~|~|~|quantity|~|trauma-shears
bls-compartment-1|PEN LIGHT|Pen light|1|1|~|~|~|quantity|~|pen-light
bls-compartment-1|PEROXIDE|Peroxide|1|1|~|~|~|quantity|~|peroxide
bls-compartment-1|TAPE 1"|Tape 1"|1|1|~|~|~|quantity|~|tape-1in
bls-compartment-1|TAPE 3"|Tape 3"|1|1|~|~|~|quantity|~|tape-3in
bls-compartment-1|ASHERMAN CHEST SEALS|Asherman chest seals|2|2|~|~|~|quantity|~|asherman-chest-seal
bls-compartment-1|ORAL GLUCTOSE|Oral glucose|2|2|~|~|~|quantity|~|oral-glucose
bls-compartment-1|QUICK CLOT|Quick clot|1|1|~|~|~|quantity|~|quikclot-gauze
bls-main-compartment|ARM BOARD LONG|Arm board long|1|1|~|~|~|quantity|~|arm-board-long
bls-main-compartment|ARM BOARD SHORT|Arm board short|1|1|~|~|~|quantity|~|arm-board-short
bls-main-compartment|BP CUFF|BP cuff|1|1|~|~|~|quantity|~|bp-cuff
bls-main-compartment|STETHESCOPE|Stethoscope|1|1|~|~|~|quantity|~|stethoscope
bls-main-compartment|RED BAG|Red bag|1|1|~|~|~|quantity|~|red-bag-als-bag
bls-main-compartment|GLUCOMETER|Glucometer|1|1|~|~|~|quantity|~|glucometer
bls-main-compartment|NARCAN|Narcan|2|2|~|~|~|quantity|~|narcan
bls-main-compartment|MADDETT|Maddett|1|1|~|~|~|quantity|~|maddett
SHEET;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
        // The tracked extractions as released: the fixture world only carries tiny bag forms.
        $this->importTrackedList(self::FILE);
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    /** @return list<list<string>> */
    private function sheet(): array
    {
        return array_map(static fn (string $line): array => explode('|', $line), explode("\n", self::SHEET));
    }

    private static function cell(string $value): ?string
    {
        return $value === '~' ? null : $value;
    }

    /** @return array<string, string> */
    private static function extras(string $cell): array
    {
        $out = [];
        foreach ($cell === '~' ? [] : explode(';', $cell) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $out[$name] = $value;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        $raw = (string) file_get_contents(base_path('database/data/ems/'.self::FILE));
        $document = json_decode($raw, true);
        $this->assertIsArray($document);

        return $document;
    }

    private function form(): SourceForm
    {
        return SourceForm::query()->where('key', self::KEY)->where('active', true)->sole();
    }

    /** @return \Illuminate\Support\Collection<int, SourceFormItem> */
    private function items(): \Illuminate\Support\Collection
    {
        return $this->form()->items()->get()->values();
    }

    private function item(string $sourceLabel, ?string $section = null, ?string $variant = null): SourceFormItem
    {
        $matches = $this->items()->filter(static fn (SourceFormItem $i): bool => $i->source_label === $sourceLabel
            && ($section === null || $i->section_key === $section)
            && ($variant === null || $i->variant_label === $variant));
        $this->assertCount(1, $matches, "Exactly one item is printed as {$sourceLabel}.");

        return $matches->first();
    }

    public function test_the_list_is_pinned_to_the_printed_pdf(): void
    {
        $source = $this->document()['source'];
        $this->assertSame('mbfd.ems.source_form.v1', $this->document()['schema']);
        $this->assertSame(self::KEY, $source['key']);
        $this->assertSame(self::SHA256, $source['sha256']);
        $this->assertSame(1, $source['pages']);
        $this->assertSame('BLS DETAIL BAG', $source['title']);
        $this->assertSame('REV 2020', $source['version_label']);
        $this->assertSame('bls_detail_bag_medical_inventory.pdf', $source['file_name']);
        $this->assertSame('BLS DETAIL BAG REV 2020', $source['printed_header']);

        $form = $this->form();
        $this->assertSame(self::SHA256, $form->sha256);
        $this->assertSame($source['title'], $form->title);
        $this->assertSame($source['version_label'], $form->version_label);
        $this->assertSame($source['printed_header'], $form->notes['printed_header']);
        $this->assertSame($source['review_notes'], $form->notes['review_notes']);
    }

    public function test_sections_follow_the_sheet_in_order(): void
    {
        $sections = $this->items()->groupBy('section_key')
            ->map(fn ($group): array => [$group->first()->section_label, $group->count()])->all();

        $this->assertSame(self::SECTIONS, $sections);
        $this->assertSame(array_keys(self::SECTIONS), array_column($this->document()['sections'], 'key'));
        $this->assertSame(array_sum(array_column(self::SECTIONS, 1)), count($this->sheet()));
    }

    public function test_the_form_has_every_row_of_the_paper_sheet_in_sheet_order(): void
    {
        $items = $this->items();
        $sheet = $this->sheet();

        $this->assertCount(count($sheet), $items, 'No row of the sheet is missing and nothing extra was added.');
        foreach ($sheet as $index => [$section, $printed, $label, $amount, $amountQty, $amountUnit, $fillIf, $fillIfQty, $type, $extras, $catalog]) {
            $item = $items[$index];
            $at = "Row {$index} ({$printed})";
            $this->assertSame($section, $item->section_key, "{$at}: section or order");
            $this->assertSame($printed, $item->source_label, "{$at}: as printed");
            $this->assertSame($label, $item->label, "{$at}: label");
            $this->assertSame(self::cell($amount), $item->min_text, "{$at}: AMOUNT");
            $this->assertSame(self::cell($amountQty) === null ? null : (int) $amountQty, $item->min_qty, "{$at}: AMOUNT number");
            $this->assertSame(self::cell($amountUnit), $item->min_unit, "{$at}: AMOUNT unit");
            $this->assertSame(self::cell($fillIf), $item->flags['fill_if_text'] ?? null, "{$at}: Fill if <");
            $this->assertSame(self::cell($fillIfQty) === null ? null : (int) $fillIfQty, $item->flags['fill_if_qty'] ?? null, "{$at}: Fill if < number");
            $this->assertSame($type, $item->response_type, "{$at}: response type");

            $extra = self::extras($extras);
            $this->assertSame($extra['loc'] ?? null, $item->flags['location_text'] ?? null, "{$at}: location");
            $this->assertSame($extra['qual'] ?? null, $item->flags['qualifier_text'] ?? null, "{$at}: qualifier");
            $this->assertSame($extra['band'] ?? null, $item->flags['color_band'] ?? null, "{$at}: color band");
            $this->assertSame($extra['cad'] ?? null, $item->flags['cadence'] ?? null, "{$at}: cadence");
            $this->assertSame(isset($extra['rev']), $item->needs_review, "{$at}: needs review");
            $this->assertSame($extra['vg'] ?? null, $item->variant_group, "{$at}: variant group");
            $this->assertSame($extra['vl'] ?? null, $item->variant_label, "{$at}: variant label");
            $this->assertSame(self::cell($catalog), $item->catalogItem?->key, "{$at}: catalog item");
            foreach (['detail_airway_bag_only', 'rescue_only', 'refrigerated', 'controlled_substance'] as $flag) {
                $this->assertFalse($item->flag($flag), "{$at}: {$flag} is not printed on the sheet");
            }
            $this->assertStringStartsWith(self::ITEM_PREFIX, $item->item_key);
        }
    }

    public function test_item_refs_are_unique_stable_and_every_printed_row_has_a_source_position(): void
    {
        $refs = [];
        foreach ($this->document()['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $this->assertMatchesRegularExpression('/^'.preg_quote(self::ITEM_PREFIX, '/').'[a-z0-9]+(?:[.-][a-z0-9]+)*$/', $item['item_ref']);
                $refs[] = $item['item_ref'];
                $this->assertSame(1, $item['source_ref']['page']);
                $this->assertContains($item['source_ref']['column'], ['left', 'middle', 'right']);
                $this->assertGreaterThan(0, $item['source_ref']['row']);
                $this->assertNotSame('', trim($item['source_label']));
            }
        }
        $this->assertCount(count($this->sheet()), $refs);
        $this->assertSame($refs, array_values(array_unique($refs)), 'No item_ref is used twice.');
        $this->assertSame($refs, $this->items()->pluck('item_key')->all());
    }

    public function test_catalog_refs_are_reused_only_where_the_older_lists_already_have_them(): void
    {
        $older = [];
        foreach (['airway_bag_inventory.json', 'als_med_bag_inventory.json', 'als_transport_vehicle_inventory.json'] as $file) {
            foreach (json_decode((string) file_get_contents(base_path('database/data/ems/'.$file)), true)['sections'] as $section) {
                foreach ($section['items'] as $item) {
                    $older[$item['catalog_ref']] = true;
                }
            }
        }
        $reused = [];
        $new = [];
        foreach ($this->document()['sections'] as $section) {
            foreach ($section['items'] as $item) {
                if ($item['catalog_ref'] === null) {
                    continue;
                }
                isset($older[$item['catalog_ref']]) ? $reused[$item['catalog_ref']] = true : $new[$item['catalog_ref']] = true;
            }
        }

        $this->assertSame(self::CATALOG_REUSED, count($reused));
        $this->assertSame(self::CATALOG_NEW, count($new));
    }

    public function test_reimporting_the_list_changes_nothing(): void
    {
        $before = SourceFormItem::query()->count();
        $result = $this->importTrackedList(self::FILE);

        $this->assertSame($before, SourceFormItem::query()->count());
        $this->assertSame(1, $result['counts']['forms.'.self::KEY.'.unchanged'] ?? null);
        $this->assertArrayNotHasKey('form_items.created', $result['counts']);
        $this->assertArrayNotHasKey('form_items.updated', $result['counts']);
    }

    public function test_the_importer_created_one_form_row_per_printed_row_and_expanded_variant(): void
    {
        $this->assertSame(1, SourceForm::query()->where('key', self::KEY)->count());
        $this->assertSame(count($this->sheet()), SourceFormItem::query()->where('source_form_id', $this->form()->getKey())->count());
    }

    public function test_the_sheet_prints_one_quantity_per_item_and_no_fill_if_level(): void
    {
        foreach ($this->items() as $item) {
            $this->assertNotNull($item->min_text, $item->source_label);
            $this->assertArrayNotHasKey('fill_if_text', $item->flags, 'The BLS sheet prints no Fill if column.');
            $this->assertSame('quantity', $item->response_type);
        }
        $box = $this->item('BAND AIDES 1X3"');
        $this->assertSame(['1 BOX', 1, 'box'], [$box->min_text, $box->min_qty, $box->min_unit]);
        $this->assertSame(['10', 10, null], [$this->item('BAND AIDES 2X3"')->min_text, $this->item('BAND AIDES 2X3"')->min_qty, $this->item('BAND AIDES 2X3"')->min_unit]);
        $this->assertSame(['8', '5', '4'], [$this->item('GAUZE 4X4"')->min_text, $this->item('GLOVES')->min_text, $this->item('ICE PACKS')->min_text]);
    }

    public function test_headings_pouches_and_compartments_are_kept_as_printed(): void
    {
        $sections = array_column($this->document()['sections'], 'source_label', 'key');
        $this->assertSame([
            'bls-front-pouch' => 'FRONT POUCH', 'bls-left-side' => 'LEFT SIDE (6)', 'bls-right-side' => 'RIGHT SIDE (5)',
            'bls-pouch-4' => 'POUCH 4', 'bls-pouch-3' => 'POUCH 3', 'bls-pouch-2' => 'POUCH 2',
            'bls-compartment-1' => 'COMPARTMENT 1', 'bls-main-compartment' => 'MAIN COMPARTMENT',
        ], $sections);
        $this->assertSame('Left Side (6)', $this->item('ICE PACKS')->section_label);
        $this->assertSame('Right Side (5)', $this->item('GLOVES')->section_label);
        $this->assertSame('Main Compartment', $this->item('NARCAN')->section_label);
    }

    public function test_misspellings_stay_in_the_source_label_and_are_fixed_only_in_the_label(): void
    {
        $fixed = [
            'TRAMUA SHEARS' => 'Trauma shears', 'ORAL GLUCTOSE' => 'Oral glucose', 'TYVEX SLEEVES' => 'Tyvek sleeves',
            'STETHESCOPE' => 'Stethoscope', 'ASHERMAN CHEST SEALS' => 'Asherman chest seals', 'BAND AIDES 1X3"' => 'Band aids 1x3"',
        ];
        foreach ($fixed as $printed => $label) {
            $this->assertSame($label, $this->item($printed)->label, $printed);
        }
        $this->assertSame('Maddett', $this->item('MADDETT')->label, 'Not an obvious spelling of another product: kept.');
        $this->assertContains('tramua shears', $this->item('TRAMUA SHEARS')->flags['search_terms']);
        $this->assertContains('gluctose', $this->item('ORAL GLUCTOSE')->flags['search_terms']);
    }

    public function test_opas_rows_print_no_sizes_and_keep_the_printed_counts(): void
    {
        $this->assertSame(['2', '1', '1'], [$this->item('OPAS 2 ADULT')->min_text, $this->item('OPAS 1 CHILD')->min_text, $this->item('OPAS 1 INF')->min_text]);
        $this->assertSame(['2', '2'], [$this->item('NARCAN')->min_text, $this->item('ASHERMAN CHEST SEALS')->min_text]);
        $this->assertSame(['TAPE 1"', 'TAPE 3"'], $this->items()->whereIn('source_label', ['TAPE 1"', 'TAPE 3"'])->pluck('source_label')->all());
        $this->assertFalse($this->item('NARCAN')->flag('controlled_substance'), 'Controlled status is not printed on the sheet.');
    }

    public function test_the_detail_bag_has_no_cadence_location_or_color_notes(): void
    {
        foreach ($this->items() as $item) {
            foreach (['location_text', 'qualifier_text', 'color_band', 'cadence'] as $flag) {
                $this->assertArrayNotHasKey($flag, $item->flags, $item->source_label);
            }
            $this->assertFalse($item->needs_review, $item->source_label);
        }
    }
}
