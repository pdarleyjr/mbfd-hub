<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\InventoryRequest;
use App\Models\Ems\SourceForm;
use App\Models\Ems\SourceFormItem;
use App\Services\Ems\Import\SourceImporter;
use App\Services\Ems\RequestFormDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/**
 * The paper "M.B.F.D. A.L.S. TRANSPORT VEHICLE WEEKLY INVENTORY" (Revised April 2021) against the Medical Inventory Request.
 * SHEET is the sheet read column by column, top to bottom: section key | printed item | AMOUNT | "Fill if <".
 * Every line has to be on the form, in this order, with the values as printed.
 */
final class TransportVehicleSheetTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    /** The sections as the form shows them: key => [label, item count]. The two "Cont." headings are merged into the section they continue. */
    private const SECTIONS = [
        'tv-drugs' => ['Drugs', 27],
        'tv-iv-needles' => ['I.V. / Needles', 33],
        'tv-airways-oxygen' => ['Airways / Oxygen', 31],
        'tv-intubation-suction' => ['Intubation / Suction', 46],
        'tv-trauma-supplies' => ['Trauma Supplies', 35],
        'tv-ppe-decon' => ['PPE / Decon', 24],
        'tv-lifepack-15' => ['LifePack 15 Supplies', 7],
        'tv-miscellaneous' => ['Miscellaneous', 19],
    ];

    private const SHEET = <<<'SHEET'
tv-drugs|Adenocard 12mg|4|2
tv-drugs|Amiodarone|6|3
tv-drugs|Atropine|5|3
tv-drugs|Baby Aspirin|1|1
tv-drugs|Benadryl|2|2
tv-drugs|Bicarb 8.4%|2|2
tv-drugs|Bicarb 4.2%|2|2
tv-drugs|Calcium chloride|1|1
tv-drugs|Dextrose 50%|5|3
tv-drugs|Dextrose 25%|2|2
tv-drugs|Dopamine drip|1|1
tv-drugs|Duoneb|10|5
tv-drugs|Epi 1:10,000|5|3
tv-drugs|Epi 1:1,000|5|3
tv-drugs|Etomidate|2|2
tv-drugs|Glucose oral gel|2|2
tv-drugs|Glucagon|1|1
tv-drugs|Lasix|5|3
tv-drugs|Lidocaine|4|2
tv-drugs|Cardizem|1|1
tv-drugs|Cardene|1|1
tv-drugs|Magnesium sulf.|1|1
tv-drugs|Narcan|5|3
tv-drugs|Nitromist Spray|1|1
tv-drugs|Racemic Epi|4|2
tv-drugs|Tetracaine drops|1|1
tv-drugs|Zofran|10|5
tv-iv-needles|Mini drips|5|3
tv-iv-needles|Maxi drips|10|5
tv-iv-needles|Extension sets|15|5
tv-iv-needles|3 way stopcock|2|1
tv-iv-needles|Veniguards|15|5
tv-iv-needles|Adult EZ-IO Blue|2|2
tv-iv-needles|Pedi EZ-IO Pink|2|2
tv-iv-needles|Lg Adult EZ-IO Yellow|2|2
tv-iv-needles|EZ IO Stabilzer|2|2
tv-iv-needles|IV cath 24ga|5|3
tv-iv-needles|IV cath 22ga|10|5
tv-iv-needles|IV cath 20ga|20|10
tv-iv-needles|IV cath 18ga|20|10
tv-iv-needles|IV cath 16ga|10|5
tv-iv-needles|IV cath 14ga|10|5
tv-iv-needles|Decomp 14ga 3"|2|2
tv-iv-needles|EZ set 21ga|2|2
tv-iv-needles|Needles 19ga|5|3
tv-iv-needles|Needles 23 ga|5|3
tv-iv-needles|Needles 25 ga.|3|2
tv-iv-needles|Saline flush 10cc|20|10
tv-iv-needles|Syringe 1cc|5|3
tv-iv-needles|Syringe 3cc|5|3
tv-iv-needles|Syringe 10cc|5|3
tv-iv-needles|Syringe 12cc|5|3
tv-iv-needles|Syringe 60cc|4|2
tv-iv-needles|Tourniquet Latex Free|15|5
tv-iv-needles|Betadine preps|1 box|1 box
tv-iv-needles|Alcohol preps|1 box|1 box
tv-iv-needles|N. Saline 1000cc|10|5
tv-iv-needles|D5w 50cc|4|2
tv-iv-needles|Pressure Infuser|1|1
tv-iv-needles|Nasal MADD|2|2
tv-airways-oxygen|OPA #0 40mm|2|2
tv-airways-oxygen|OPA #1 50mm|1|1
tv-airways-oxygen|OPA #2 60mm|1|1
tv-airways-oxygen|OPA #3 80mm|1|1
tv-airways-oxygen|OPA #4 90mm|2|2
tv-airways-oxygen|OPA #5 100mm|2|2
tv-airways-oxygen|OPA #6 110mm|2|2
tv-airways-oxygen|NPA #30 FR|1|1
tv-airways-oxygen|NPA #28 FR|1|1
tv-airways-oxygen|NPA #26 FR|1|1
tv-airways-oxygen|NPA #24 FR|1|1
tv-airways-oxygen|NPA #22 FR|1|1
tv-airways-oxygen|NPA #20 FR|1|1
tv-airways-oxygen|NPA #18 FR|1|1
tv-airways-oxygen|NPA #16 FR|1|1
tv-airways-oxygen|NPA #14 FR|1|1
tv-airways-oxygen|NPA #12 FR|1|1
tv-airways-oxygen|Non-rebreather Adult|10|5
tv-airways-oxygen|Nasal cannula Adult|10|5
tv-airways-oxygen|Nebulizer Adult|5|3
tv-airways-oxygen|T-nebulizer Adult|2|2
tv-airways-oxygen|Nasal Prong Capnoline Adult|3|2
tv-airways-oxygen|Non-rebreather/Pedi|5|3
tv-airways-oxygen|Nasal cannula Pedi|2|2
tv-airways-oxygen|Nebulizer Pedi|5|3
tv-airways-oxygen|Nasal Prong Capnoline Pedi|3|2
tv-airways-oxygen|Non-rebreather/Infant|2|2
tv-airways-oxygen|CPAP tube/mask set|2|1
tv-airways-oxygen|BVM Pedi|2|1
tv-airways-oxygen|BVM Infant|1|1
tv-airways-oxygen|BVM Capnoline|2|2
tv-intubation-suction|Quick-trach / Adult|1|1
tv-intubation-suction|Quick-trach / Pedi|1|1
tv-intubation-suction|ET MADD|2|2
tv-intubation-suction|ET Tube 2.5|1|1
tv-intubation-suction|ET Tube 3.0|1|1
tv-intubation-suction|ET Tube 3.5|1|1
tv-intubation-suction|ET Tube 4.0|1|1
tv-intubation-suction|ET Tube 4.5|1|1
tv-intubation-suction|ET Tube 5.0|1|1
tv-intubation-suction|ET Tube 5.5|1|1
tv-intubation-suction|ET Tube 6.0|2|2
tv-intubation-suction|ET Tube 6.5|2|2
tv-intubation-suction|ET Tube 7.5|2|2
tv-intubation-suction|ET Tube 8.0|2|2
tv-intubation-suction|ET Tube 8.5|2|2
tv-intubation-suction|ET Tube 9.0|2|2
tv-intubation-suction|Adult Bougie 15FR|1|1
tv-intubation-suction|Pedi Bougie 10FR|1|1
tv-intubation-suction|Tube-lock / Adult|2|2
tv-intubation-suction|Tube-lock / Pedi|1|1
tv-intubation-suction|Suction cath 6Fr|1|1
tv-intubation-suction|Suction cath 8Fr|1|1
tv-intubation-suction|Suction cath 10Fr|1|1
tv-intubation-suction|Suction cath 12Fr|1|1
tv-intubation-suction|Suction cath 14Fr|1|1
tv-intubation-suction|Suction cath 18Fr|2|2
tv-intubation-suction|Suction yankauer|2|2
tv-intubation-suction|Meconium aspirators|1|1
tv-intubation-suction|SSCOR Disp. Canister|1|1
tv-intubation-suction|Portable suction canister|2|2
tv-intubation-suction|Portable suction tubing|2|2
tv-intubation-suction|Lubricating gel|10|5
tv-intubation-suction|i-gel 5|1|1
tv-intubation-suction|i-gel 4|2|2
tv-intubation-suction|i-gel 3|1|1
tv-intubation-suction|i-gel 2.5|1|1
tv-intubation-suction|i-gel 2.0|1|1
tv-intubation-suction|i-gel 1.5|1|1
tv-intubation-suction|i-gel 1.0|1|1
tv-intubation-suction|MAC #4|2|1
tv-intubation-suction|MAC #3|1|1
tv-intubation-suction|MAC #2|1|1
tv-intubation-suction|MILLER #3|2|2
tv-intubation-suction|MILLER #2|1|1
tv-intubation-suction|MILLER #1|1|1
tv-intubation-suction|MILLER #0|1|1
tv-trauma-supplies|500 ml irrigation water|2|2
tv-trauma-supplies|Head immobilizers|5|3
tv-trauma-supplies|C-collar-adjustable pedi|3|2
tv-trauma-supplies|C-collar/adult adjustable|3|2
tv-trauma-supplies|C-collar/adult no-neck|3|2
tv-trauma-supplies|Cardboard splints short|5|3
tv-trauma-supplies|Cardboard splints long|5|3
tv-trauma-supplies|Burn sheets|3|2
tv-trauma-supplies|Burn Gel 8 x 18 Dressing|2|2
tv-trauma-supplies|Burn Gel Face Mask|2|2
tv-trauma-supplies|Burn Gel 2x6 Dressing|2|2
tv-trauma-supplies|Burn Gel 4x4 Dressing|2|2
tv-trauma-supplies|Burn Gel 3.5g Packets|4|2
tv-trauma-supplies|1/2" tape|2|2
tv-trauma-supplies|1" tape|2|2
tv-trauma-supplies|3" tape|2|2
tv-trauma-supplies|Combat App. Tourniquet CAT|1|1
tv-trauma-supplies|Kling 2"|5|3
tv-trauma-supplies|Kling 4"|10|5
tv-trauma-supplies|Kling 6"|5|3
tv-trauma-supplies|4" ace bandage|6|3
tv-trauma-supplies|6" ace bandage|6|3
tv-trauma-supplies|T-bandages|5|3
tv-trauma-supplies|Armboards 9"|5|3
tv-trauma-supplies|Armboards 18"|5|3
tv-trauma-supplies|Steri pads 2x2"|1 box|1box
tv-trauma-supplies|Steri pads 4x4"|1 box|1 box
tv-trauma-supplies|Surgi pads 5x9"|1 box|1 box
tv-trauma-supplies|Trauma dressing 12x30"|6|2
tv-trauma-supplies|Band aids 1 x 3"|1 box|1 box
tv-trauma-supplies|Band aids 4 1/2" x 2"|1 box|1 box
tv-trauma-supplies|Quick-clot|2|1
tv-trauma-supplies|Ascherman chest seals|3|2
tv-trauma-supplies|Ocular Patch|3|2
tv-trauma-supplies|Cold packs|10|5
tv-ppe-decon|Disposable gloves XXL|1 box|1 box
tv-ppe-decon|Disposable gloves XL|1 box|1 box
tv-ppe-decon|Disposable gloves L|1 box|1 box
tv-ppe-decon|Disposable gloves M|1 box|1 box
tv-ppe-decon|Disposable gloves S|1 box|1 box
tv-ppe-decon|Tyvek suits 2XL|2|2
tv-ppe-decon|Tyvek suits XL|2|2
tv-ppe-decon|Tyvek suits L|2|2
tv-ppe-decon|Shoe covers|3 pair|3 pair
tv-ppe-decon|Tyvek sleeves|24|12
tv-ppe-decon|N95 Masks|10|5
tv-ppe-decon|Patient Surgical Mask (COVID)|10|5
tv-ppe-decon|3/4 Face shields|3|3
tv-ppe-decon|Face shields w/ mask|3|3
tv-ppe-decon|Safety Glasses (Clear)|3|3
tv-ppe-decon|Sanizide|2|2
tv-ppe-decon|Convenience bags|5|3
tv-ppe-decon|Peroxide bottle|2|2
tv-ppe-decon|Alcohol bottle|2|2
tv-ppe-decon|Red biohazard bags|10|5
tv-ppe-decon|Cida hand gel|2|1
tv-ppe-decon|Sharps container Large|1|1
tv-ppe-decon|Sharps container Small|1|1
tv-ppe-decon|Sharps shuttle|2|2
tv-lifepack-15|EKG electrodes|4 bags|2 bags
tv-lifepack-15|Combi pads-adult|2|2
tv-lifepack-15|Combi pads-pedi|1|1
tv-lifepack-15|EKG paper lifepack 15|1 pack|1 Roll
tv-lifepack-15|Disposable razors|1 pack|2 Razors
tv-lifepack-15|Pedi Pulse Ox Sensor|1|1
tv-lifepack-15|Infant Pulse Ox Sensor|1|1
tv-miscellaneous|Dispo. Thermometer|5|3
tv-miscellaneous|Carpujet|1|1
tv-miscellaneous|Pen Lights|2|1
tv-miscellaneous|Yellow foam blanket|2|1
tv-miscellaneous|Foil rescue blanket|1|1
tv-miscellaneous|Ammonia inhalants|1 box|1 box
tv-miscellaneous|Vinegar bottle|1|1
tv-miscellaneous|Padded restraints|2 pair|2 pair
tv-miscellaneous|OB kit|2|1
tv-miscellaneous|Auto BP cuff adult XL|2|2
tv-miscellaneous|Auto BP cuff adult LG|2|2
tv-miscellaneous|Auto BP cuff adult REG|2|2
tv-miscellaneous|Auto BP cuff PEDI|2|2
tv-miscellaneous|Auto BP cuff INFANT|2|2
tv-miscellaneous|Disposable straps|8 pair|4 pair
tv-miscellaneous|Glucometer|1|1
tv-miscellaneous|Glucometer strips|50|5
tv-miscellaneous|Gluco lancettes|10|5
tv-miscellaneous|Bite Stick|1|1
SHEET;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
        // The tracked extractions as released: the fixture world only carries tiny bag forms.
        app(SourceImporter::class)->import(base_path('database/data/ems'));
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> */
    private function sheet(): array
    {
        return array_map(static fn (string $line): array => explode('|', $line), explode("\n", self::SHEET));
    }

    private function transportForm(): SourceForm
    {
        return SourceForm::query()->where('key', SourceForm::TRANSPORT)->where('active', true)->sole();
    }

    public function test_the_form_has_every_line_of_the_paper_sheet_in_sheet_order(): void
    {
        $items = $this->transportForm()->items()->get()->values();
        $sheet = $this->sheet();

        $this->assertCount(array_sum(array_column(self::SECTIONS, 1)), $sheet);
        $this->assertCount(count($sheet), $items, 'No line of the sheet is missing and nothing extra was added.');
        foreach ($sheet as $index => [$section, $printed, $amount, $fillIf]) {
            $item = $items[$index];
            $this->assertSame($section, $item->section_key, "Line {$index} ({$printed}) is in the wrong section or out of order.");
            $this->assertSame($printed, $item->source_label, "Line {$index} was not transcribed as printed.");
            $this->assertSame($amount, $item->min_text, "{$printed}: AMOUNT");
            $this->assertSame($fillIf, $item->flags['fill_if_text'] ?? null, "{$printed}: Fill if <");
            $this->assertSame('quantity', $item->response_type);
            $this->assertIsInt($item->min_qty, "{$printed} has a numeric par.");
            $this->assertIsInt($item->flags['fill_if_qty'] ?? null, "{$printed} has a numeric fill-if.");
        }
    }

    public function test_sections_follow_the_sheet_and_the_cont_headings_are_merged(): void
    {
        $sections = $this->transportForm()->items()->get()->groupBy('section_key')
            ->map(fn ($group): array => [$group->first()->section_label, $group->count()])->all();

        $this->assertSame(self::SECTIONS, $sections);
        $this->assertSame(
            ['tv-drugs', 'tv-iv-needles', 'tv-airways-oxygen', 'tv-intubation-suction', 'tv-trauma-supplies', 'tv-ppe-decon', 'tv-lifepack-15', 'tv-miscellaneous'],
            array_keys($sections),
        );
    }

    public function test_units_printed_on_the_sheet_are_kept_and_numbers_are_read_from_them(): void
    {
        $byLabel = $this->transportForm()->items()->get()->keyBy('source_label');

        $this->assertSame(['1 box', 1, 'box'], [$byLabel['Betadine preps']->min_text, $byLabel['Betadine preps']->min_qty, $byLabel['Betadine preps']->min_unit]);
        $this->assertSame(['3 pair', 3, 'pair'], [$byLabel['Shoe covers']->min_text, $byLabel['Shoe covers']->min_qty, $byLabel['Shoe covers']->min_unit]);
        $electrodes = $byLabel['EKG electrodes'];
        $this->assertSame(['4 bags', 4, '2 bags', 2], [$electrodes->min_text, $electrodes->min_qty, $electrodes->flags['fill_if_text'], $electrodes->flags['fill_if_qty']]);
        $this->assertSame(['1 pack', '1 Roll'], [$byLabel['EKG paper lifepack 15']->min_text, $byLabel['EKG paper lifepack 15']->flags['fill_if_text']]);
        $this->assertSame(['1 pack', '2 Razors'], [$byLabel['Disposable razors']->min_text, $byLabel['Disposable razors']->flags['fill_if_text']]);
        $this->assertNull($byLabel->get('ET Tube 7.0'), 'The sheet has no 7.0 ET tube line.');
        $this->assertSame(['2', '2'], [$byLabel['ET Tube 7.5']->min_text, $byLabel['ET Tube 7.5']->flags['fill_if_text']]);
    }

    public function test_the_form_definition_lists_three_forms_with_par_and_fill_if(): void
    {
        $forms = collect(app(RequestFormDefinition::class)->definition()['forms']);

        $this->assertSame(['airway-bag', 'als-med-bag', 'als-transport-vehicle'], $forms->pluck('key')->all());
        $vehicle = $forms->firstWhere('key', 'als-transport-vehicle');
        $this->assertSame(array_keys(self::SECTIONS), collect($vehicle['sections'])->pluck('key')->all());
        $first = $vehicle['sections'][0]['items'][0];
        $this->assertSame(['Adenocard 12 mg', '4', '2'], [$first['label'], $first['min_text'], $first['flags']['fill_if_text']]);
        $this->assertSame('Revised April 2021', $vehicle['version_label']);

        $bagItem = $forms->firstWhere('key', 'airway-bag')['sections'][0]['items'][0];
        $this->assertArrayNotHasKey('fill_if_text', $bagItem['flags'], 'The bag forms print no fill-if level.');
    }

    public function test_reimporting_the_sheet_changes_nothing(): void
    {
        $before = SourceFormItem::query()->count();
        $result = app(SourceImporter::class)->import(base_path('database/data/ems'));

        $this->assertSame($before, SourceFormItem::query()->count());
        $this->assertSame(1, $result['counts']['forms.als-transport-vehicle.unchanged'] ?? null);
        $this->assertArrayNotHasKey('form_items.created', $result['counts']);
        $this->assertArrayNotHasKey('form_items.updated', $result['counts']);
    }

    public function test_a_member_can_request_vehicle_items_and_the_request_keeps_the_par(): void
    {
        $this->actingAs($this->emsMember('Paramedic', 'Pat Medic'));
        $narcan = $this->transportForm()->items()->where('source_label', 'Narcan')->sole();
        $gloves = $this->transportForm()->items()->where('source_label', 'Disposable gloves L')->sole();

        $response = $this->postJson('/employee/forms/api/ems/requests', [
            'client_submission_id' => (string) Str::uuid(),
            'target' => ['kind' => 'rescue_unit', 'apparatus_id' => $this->rescue2->id],
            'lines' => [
                ['form' => 'als-transport-vehicle', 'item_key' => $narcan->item_key, 'quantity' => 3],
                ['form' => 'als-transport-vehicle', 'item_key' => $gloves->item_key, 'quantity' => 1],
            ],
        ]);
        $response->assertSuccessful();

        $request = InventoryRequest::query()->latest('id')->firstOrFail();
        $lines = $request->lines()->orderBy('id')->get();
        $this->assertSame(['als-transport-vehicle', 'als-transport-vehicle'], $lines->pluck('bag_key')->all());
        $this->assertSame(['5', '1 box'], $lines->pluck('min_text')->all());
        $this->assertSame([3, 1], $lines->pluck('requested_qty')->all());
    }
}
