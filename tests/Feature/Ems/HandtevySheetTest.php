<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\SourceForm;
use App\Models\Ems\SourceFormItem;
use App\Services\Ems\Import\SourceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/**
 * The paper "Handtevy" inventory sheet (no title, no revision printed; left half Top Insert .. 1 year, right half 2 year .. 9 - 13 year)
 * against the Handtevy list. The sheet's Amount column is empty; its printed rule "Quantity 1 unless indicated in ( )" gives the per-item quantity.
 * SHEET is the transcription in sheet reading order: section key | printed text | label | AMOUNT | AMOUNT number | AMOUNT unit |
 * "Fill if <" | "Fill if <" number | response type | extras (loc, qual, band, cad, rev, vg, vl) | catalog ref. "~" is empty.
 * Every printed row has to be on the form, in this order, with the values as printed.
 */
final class HandtevySheetTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private const KEY = 'handtevy';

    private const FILE = 'handtevy_inventory.json';

    private const SHA256 = 'fe5a47d26489b061be52a03dd0609ceeea1836eddd15e7e3ed218c8ad6acd43c';

    private const ITEM_PREFIX = 'ht.';

    /** The sections as the form shows them: key => [label, item count]. */
    private const SECTIONS = [
        'ht-top-insert' => ['Top Insert', 15],
        'ht-bottom-bin' => ['Bottom Bin', 13],
        'ht-under-1' => ['< 1 Y/O (Grey / Pink / Red)', 13],
        'ht-1-year' => ['1 year (Purple)', 10],
        'ht-2-year' => ['2 year (Yellow)', 11],
        'ht-3-4-years' => ['3 & 4 years (White)', 11],
        'ht-5-6-years' => ['5 & 6 year (Blue)', 12],
        'ht-7-8-years' => ['7 & 8 year (Orange)', 11],
        'ht-9-13-years' => ['9 - 13 year (Green)', 13],
    ];

    /** Catalog keys this list shares with the three older lists / keys only this and the other new lists use. */
    private const CATALOG_REUSED = 39;

    private const CATALOG_NEW = 22;

    private const SHEET = <<<'SHEET'
ht-top-insert|Ace 3"|Ace bandage 3"|1|1|~|~|~|quantity|~|ace-bandage-3in
ht-top-insert|Kling 3"|Kling 3"|1|1|~|~|~|quantity|~|kling-3in
ht-top-insert|Bite Stick|Bite stick|1|1|~|~|~|quantity|~|bite-stick
ht-top-insert|Laryngoscope Handle|Laryngoscope handle|1|1|~|~|~|quantity|~|laryngoscope-handle
ht-top-insert|Meconium Aspirator/ MAD Device|Meconium aspirator / MAD device|1|1|~|~|~|quantity|~|meconium-aspirator-mad-device
ht-top-insert|Needles - 21Ga 1"(2), 25Ga 1" (1 each)|Needle 21Ga 1"|2|2|~|~|~|quantity|vg=needles;vl=21Ga 1"|needle-21ga-1in
ht-top-insert|Needles - 21Ga 1"(2), 25Ga 1" (1 each)|Needle 25Ga 1"|1|1|~|~|~|quantity|vg=needles;vl=25Ga 1"|needle-25ga-1in
ht-top-insert|Penlight|Penlight|1|1|~|~|~|quantity|~|pen-light
ht-top-insert|Trauma Shears|Trauma shears|1|1|~|~|~|quantity|~|trauma-shears
ht-top-insert|Syringes- 1cc(2), 3cc(2), 10cc(2)|Syringe 1cc|2|2|~|~|~|quantity|vg=syringes;vl=1cc|syringe-1cc
ht-top-insert|Syringes- 1cc(2), 3cc(2), 10cc(2)|Syringe 3cc|2|2|~|~|~|quantity|vg=syringes;vl=3cc|syringe-3cc
ht-top-insert|Syringes- 1cc(2), 3cc(2), 10cc(2)|Syringe 10cc|2|2|~|~|~|quantity|vg=syringes;vl=10cc|tv-syringe-10-cc
ht-top-insert|Tape- 1"(1roll)|Tape 1"|1 roll|1|roll|~|~|quantity|~|tape-1in
ht-top-insert|Three-way stopcock(2)|Three-way stopcock|2|2|~|~|~|quantity|~|three-way-stopcock
ht-top-insert|IV Start Kit(located in each pouch)|IV start kit|1|1|~|~|~|quantity|loc=located in each pouch|iv-start-kit
ht-bottom-bin|Arm Board 9"(1)|Arm board 9"|1|1|~|~|~|quantity|~|arm-board-9in
ht-bottom-bin|Bulb Syringe(1)|Bulb syringe|1|1|~|~|~|quantity|~|bulb-syringe
ht-bottom-bin|Infant / Small Child Size BVM (1)|Infant / small child size BVM|1|1|~|~|~|quantity|~|bvm-infant-small-child
ht-bottom-bin|Pedi Tube Holder|Pedi tube holder|1|1|~|~|~|quantity|~|tube-holder-pedi
ht-bottom-bin|Infant & Child BP Cuff|Infant & child BP cuff|1|1|~|~|~|quantity|~|bp-cuff-infant-child
ht-bottom-bin|Pediatric Capnoline/Nasal Cannula(1)|Pediatric Capnoline / nasal cannula|1|1|~|~|~|quantity|~|pediatric-capnoline-nasal-cannula
ht-bottom-bin|Safety Glasses(2)|Safety glasses|2|2|~|~|~|quantity|~|safety-glasses
ht-bottom-bin|Stethoscope|Stethoscope|1|1|~|~|~|quantity|~|stethoscope
ht-bottom-bin|Trauma Sleevs(3 sets)|Trauma sleeves|3 sets|3|sets|~|~|quantity|~|trauma-sleeves
ht-bottom-bin|iGels 1.0 / 1.5 / 2.0 / 2.5|i-gel 1.0|1|1|~|~|~|quantity|vg=igel;vl=1.0|tv-i-gel-size-1-0
ht-bottom-bin|iGels 1.0 / 1.5 / 2.0 / 2.5|i-gel 1.5|1|1|~|~|~|quantity|vg=igel;vl=1.5|tv-i-gel-size-1-5
ht-bottom-bin|iGels 1.0 / 1.5 / 2.0 / 2.5|i-gel 2.0|1|1|~|~|~|quantity|vg=igel;vl=2.0|tv-i-gel-size-2-0
ht-bottom-bin|iGels 1.0 / 1.5 / 2.0 / 2.5|i-gel 2.5|1|1|~|~|~|quantity|vg=igel;vl=2.5|tv-i-gel-size-2-5
ht-under-1|Blade- 1 Straight|Blade 1 straight|1|1|~|~|~|quantity|band=Grey / Pink / Red (< 1 year);rev=1|blade-1-straight
ht-under-1|ET Tube- 2.5mm Cuffed|ET tube 2.5mm cuffed|1|1|~|~|~|quantity|band=Grey / Pink / Red (< 1 year);rev=1|et-tube-2-5-cuffed
ht-under-1|ET Tube- 3.0mm Cuffed|ET tube 3.0mm cuffed|1|1|~|~|~|quantity|band=Grey / Pink / Red (< 1 year);rev=1|et-tube-3-0-cuffed
ht-under-1|ET Tube- 3.5mm Cuffed|ET tube 3.5mm cuffed|1|1|~|~|~|quantity|band=Grey / Pink / Red (< 1 year);rev=1|et-tube-3-5-cuffed
ht-under-1|IV Catheter- 22Ga(2 each), 24Ga(2 each)|IV catheter 22Ga|2|2|~|~|~|quantity|band=Grey / Pink / Red (< 1 year);rev=1;vg=iv-catheter;vl=22Ga|iv-catheter-22g
ht-under-1|IV Catheter- 22Ga(2 each), 24Ga(2 each)|IV catheter 24Ga|2|2|~|~|~|quantity|band=Grey / Pink / Red (< 1 year);rev=1;vg=iv-catheter;vl=24Ga|iv-catheter-24g
ht-under-1|OPA- 50mm|OPA 50mm|1|1|~|~|~|quantity|band=Grey / Pink / Red (< 1 year);rev=1|opa-50mm
ht-under-1|NPA- 14F|NPA 14F|1|1|~|~|~|quantity|band=Grey / Pink / Red (< 1 year);rev=1|npa-14
ht-under-1|French Catheter- 6F,8F|French catheter 6F|1|1|~|~|~|quantity|band=Grey / Pink / Red (< 1 year);rev=1;vg=french-catheter;vl=6F|french-catheter-6f
ht-under-1|French Catheter- 6F,8F|French catheter 8F|1|1|~|~|~|quantity|band=Grey / Pink / Red (< 1 year);rev=1;vg=french-catheter;vl=8F|french-catheter-8f
ht-under-1|Syringes- 1cc, 3cc, 10cc|Syringe 1cc|1|1|~|~|~|quantity|band=Grey / Pink / Red (< 1 year);rev=1;vg=syringes;vl=1cc|syringe-1cc
ht-under-1|Syringes- 1cc, 3cc, 10cc|Syringe 3cc|1|1|~|~|~|quantity|band=Grey / Pink / Red (< 1 year);rev=1;vg=syringes;vl=3cc|syringe-3cc
ht-under-1|Syringes- 1cc, 3cc, 10cc|Syringe 10cc|1|1|~|~|~|quantity|band=Grey / Pink / Red (< 1 year);rev=1;vg=syringes;vl=10cc|tv-syringe-10-cc
ht-1-year|Blade- 1 Straight|Blade 1 straight|1|1|~|~|~|quantity|band=Purple (1 year)|blade-1-straight
ht-1-year|ET Tube- 4.0mm Cuffed|ET tube 4.0mm cuffed|1|1|~|~|~|quantity|band=Purple (1 year)|et-tube-4-0-cuffed
ht-1-year|IV Catheter- 22Ga(2 each), 24Ga(2 each)|IV catheter 22Ga|2|2|~|~|~|quantity|band=Purple (1 year);vg=iv-catheter;vl=22Ga|iv-catheter-22g
ht-1-year|IV Catheter- 22Ga(2 each), 24Ga(2 each)|IV catheter 24Ga|2|2|~|~|~|quantity|band=Purple (1 year);vg=iv-catheter;vl=24Ga|iv-catheter-24g
ht-1-year|OPA - 60mm|OPA 60mm|1|1|~|~|~|quantity|band=Purple (1 year)|opa-60mm
ht-1-year|NPA - 18F|NPA 18F|1|1|~|~|~|quantity|band=Purple (1 year)|npa-18
ht-1-year|French Catheter - 10F|French catheter 10F|1|1|~|~|~|quantity|band=Purple (1 year)|french-catheter-10f
ht-1-year|Syringes- 1cc, 3cc, 10cc|Syringe 1cc|1|1|~|~|~|quantity|band=Purple (1 year);vg=syringes;vl=1cc|syringe-1cc
ht-1-year|Syringes- 1cc, 3cc, 10cc|Syringe 3cc|1|1|~|~|~|quantity|band=Purple (1 year);vg=syringes;vl=3cc|syringe-3cc
ht-1-year|Syringes- 1cc, 3cc, 10cc|Syringe 10cc|1|1|~|~|~|quantity|band=Purple (1 year);vg=syringes;vl=10cc|tv-syringe-10-cc
ht-2-year|Blade - 2 Straight|Blade 2 straight|1|1|~|~|~|quantity|band=Yellow (2 years)|blade-2-straight
ht-2-year|ET TUBE - 4.5mm Cuffed|ET tube 4.5mm cuffed|1|1|~|~|~|quantity|band=Yellow (2 years)|et-tube-4-5-cuffed
ht-2-year|IV Catheter - 18Ga, 20Ga, 22Ga(2 each)|IV catheter 18Ga|2|2|~|~|~|quantity|band=Yellow (2 years);vg=iv-catheter;vl=18Ga|iv-catheter-18g
ht-2-year|IV Catheter - 18Ga, 20Ga, 22Ga(2 each)|IV catheter 20Ga|2|2|~|~|~|quantity|band=Yellow (2 years);vg=iv-catheter;vl=20Ga|iv-catheter-20g
ht-2-year|IV Catheter - 18Ga, 20Ga, 22Ga(2 each)|IV catheter 22Ga|2|2|~|~|~|quantity|band=Yellow (2 years);vg=iv-catheter;vl=22Ga|iv-catheter-22g
ht-2-year|OPA - 60mm|OPA 60mm|1|1|~|~|~|quantity|band=Yellow (2 years)|opa-60mm
ht-2-year|NPA - 20F|NPA 20F|1|1|~|~|~|quantity|band=Yellow (2 years)|npa-20
ht-2-year|French Catheter - 10F|French catheter 10F|1|1|~|~|~|quantity|band=Yellow (2 years)|french-catheter-10f
ht-2-year|Syringes - 1cc, 3cc, 10cc|Syringe 1cc|1|1|~|~|~|quantity|band=Yellow (2 years);vg=syringes;vl=1cc|syringe-1cc
ht-2-year|Syringes - 1cc, 3cc, 10cc|Syringe 3cc|1|1|~|~|~|quantity|band=Yellow (2 years);vg=syringes;vl=3cc|syringe-3cc
ht-2-year|Syringes - 1cc, 3cc, 10cc|Syringe 10cc|1|1|~|~|~|quantity|band=Yellow (2 years);vg=syringes;vl=10cc|tv-syringe-10-cc
ht-3-4-years|Blade - 2 Straight|Blade 2 straight|1|1|~|~|~|quantity|band=White (3 & 4 years)|blade-2-straight
ht-3-4-years|ET TUBE - 5.0mm Cuffed|ET tube 5.0mm cuffed|1|1|~|~|~|quantity|band=White (3 & 4 years)|et-tube-5-0-cuffed
ht-3-4-years|IV Catheter - 18Ga, 20Ga, 22Ga(2 each)|IV catheter 18Ga|2|2|~|~|~|quantity|band=White (3 & 4 years);vg=iv-catheter;vl=18Ga|iv-catheter-18g
ht-3-4-years|IV Catheter - 18Ga, 20Ga, 22Ga(2 each)|IV catheter 20Ga|2|2|~|~|~|quantity|band=White (3 & 4 years);vg=iv-catheter;vl=20Ga|iv-catheter-20g
ht-3-4-years|IV Catheter - 18Ga, 20Ga, 22Ga(2 each)|IV catheter 22Ga|2|2|~|~|~|quantity|band=White (3 & 4 years);vg=iv-catheter;vl=22Ga|iv-catheter-22g
ht-3-4-years|OPA - 60mm|OPA 60mm|1|1|~|~|~|quantity|band=White (3 & 4 years)|opa-60mm
ht-3-4-years|NPA - 22F|NPA 22F|1|1|~|~|~|quantity|band=White (3 & 4 years)|npa-22
ht-3-4-years|French Catheter - 10F|French catheter 10F|1|1|~|~|~|quantity|band=White (3 & 4 years)|french-catheter-10f
ht-3-4-years|Syringes - 1cc, 3cc, 10cc|Syringe 1cc|1|1|~|~|~|quantity|band=White (3 & 4 years);vg=syringes;vl=1cc|syringe-1cc
ht-3-4-years|Syringes - 1cc, 3cc, 10cc|Syringe 3cc|1|1|~|~|~|quantity|band=White (3 & 4 years);vg=syringes;vl=3cc|syringe-3cc
ht-3-4-years|Syringes - 1cc, 3cc, 10cc|Syringe 10cc|1|1|~|~|~|quantity|band=White (3 & 4 years);vg=syringes;vl=10cc|tv-syringe-10-cc
ht-5-6-years|Blade - 2 Straight & Curved|Blade 2 straight|1|1|~|~|~|quantity|band=Blue (5 & 6 years);vg=blade-2;vl=Straight|blade-2-straight
ht-5-6-years|Blade - 2 Straight & Curved|Blade 2 curved|1|1|~|~|~|quantity|band=Blue (5 & 6 years);vg=blade-2;vl=Curved|blade-2-curved
ht-5-6-years|ET Tube - 5.5mm Cuffed|ET tube 5.5mm cuffed|1|1|~|~|~|quantity|band=Blue (5 & 6 years)|et-tube-5-5-cuffed
ht-5-6-years|IV Catheter - 18Ga, 20Ga, 22Ga(2 each)|IV catheter 18Ga|2|2|~|~|~|quantity|band=Blue (5 & 6 years);vg=iv-catheter;vl=18Ga|iv-catheter-18g
ht-5-6-years|IV Catheter - 18Ga, 20Ga, 22Ga(2 each)|IV catheter 20Ga|2|2|~|~|~|quantity|band=Blue (5 & 6 years);vg=iv-catheter;vl=20Ga|iv-catheter-20g
ht-5-6-years|IV Catheter - 18Ga, 20Ga, 22Ga(2 each)|IV catheter 22Ga|2|2|~|~|~|quantity|band=Blue (5 & 6 years);vg=iv-catheter;vl=22Ga|iv-catheter-22g
ht-5-6-years|OPA - 70mm|OPA 70mm|1|1|~|~|~|quantity|band=Blue (5 & 6 years)|opa-70mm
ht-5-6-years|NPA - 24F|NPA 24F|1|1|~|~|~|quantity|band=Blue (5 & 6 years)|npa-24
ht-5-6-years|French Catheter - 10F|French catheter 10F|1|1|~|~|~|quantity|band=Blue (5 & 6 years)|french-catheter-10f
ht-5-6-years|Syringes - 1cc, 3cc, 10cc|Syringe 1cc|1|1|~|~|~|quantity|band=Blue (5 & 6 years);vg=syringes;vl=1cc|syringe-1cc
ht-5-6-years|Syringes - 1cc, 3cc, 10cc|Syringe 3cc|1|1|~|~|~|quantity|band=Blue (5 & 6 years);vg=syringes;vl=3cc|syringe-3cc
ht-5-6-years|Syringes - 1cc, 3cc, 10cc|Syringe 10cc|1|1|~|~|~|quantity|band=Blue (5 & 6 years);vg=syringes;vl=10cc|tv-syringe-10-cc
ht-7-8-years|Blade - 2 Straight & Curved|Blade 2 straight|1|1|~|~|~|quantity|band=Orange (7 & 8 years);vg=blade-2;vl=Straight|blade-2-straight
ht-7-8-years|Blade - 2 Straight & Curved|Blade 2 curved|1|1|~|~|~|quantity|band=Orange (7 & 8 years);vg=blade-2;vl=Curved|blade-2-curved
ht-7-8-years|ET Tube - 6.0mm Cuffed|ET tube 6.0mm cuffed|1|1|~|~|~|quantity|band=Orange (7 & 8 years)|et-tube-6-0-cuffed
ht-7-8-years|IV Catheter - 18Ga, 20Ga(2 each)|IV catheter 18Ga|2|2|~|~|~|quantity|band=Orange (7 & 8 years);vg=iv-catheter;vl=18Ga|iv-catheter-18g
ht-7-8-years|IV Catheter - 18Ga, 20Ga(2 each)|IV catheter 20Ga|2|2|~|~|~|quantity|band=Orange (7 & 8 years);vg=iv-catheter;vl=20Ga|iv-catheter-20g
ht-7-8-years|OPA - 80mm|OPA 80mm|1|1|~|~|~|quantity|band=Orange (7 & 8 years)|opa-80mm
ht-7-8-years|NPA - 26F|NPA 26F|1|1|~|~|~|quantity|band=Orange (7 & 8 years)|npa-26
ht-7-8-years|French Catheter - 10F|French catheter 10F|1|1|~|~|~|quantity|band=Orange (7 & 8 years)|french-catheter-10f
ht-7-8-years|Syringes - 1cc, 3cc, 10cc|Syringe 1cc|1|1|~|~|~|quantity|band=Orange (7 & 8 years);vg=syringes;vl=1cc|syringe-1cc
ht-7-8-years|Syringes - 1cc, 3cc, 10cc|Syringe 3cc|1|1|~|~|~|quantity|band=Orange (7 & 8 years);vg=syringes;vl=3cc|syringe-3cc
ht-7-8-years|Syringes - 1cc, 3cc, 10cc|Syringe 10cc|1|1|~|~|~|quantity|band=Orange (7 & 8 years);vg=syringes;vl=10cc|tv-syringe-10-cc
ht-9-13-years|Blade - 3 Straight & Curved|Blade 3 straight|1|1|~|~|~|quantity|band=Green (9 - 13 years);vg=blade-3;vl=Straight|blade-3-straight
ht-9-13-years|Blade - 3 Straight & Curved|Blade 3 curved|1|1|~|~|~|quantity|band=Green (9 - 13 years);vg=blade-3;vl=Curved|blade-3-curved
ht-9-13-years|ET Tube - 6.5mm, 7.0mm Cuffed(1 each)|ET tube 6.5mm cuffed|1|1|~|~|~|quantity|band=Green (9 - 13 years);vg=et-tube;vl=6.5mm|et-tube-6-5-cuffed
ht-9-13-years|ET Tube - 6.5mm, 7.0mm Cuffed(1 each)|ET tube 7.0mm cuffed|1|1|~|~|~|quantity|band=Green (9 - 13 years);vg=et-tube;vl=7.0mm|et-tube-7-0-cuffed
ht-9-13-years|IV Catheter - 18Ga, 20Ga(2 each)|IV catheter 18Ga|2|2|~|~|~|quantity|band=Green (9 - 13 years);vg=iv-catheter;vl=18Ga|iv-catheter-18g
ht-9-13-years|IV Catheter - 18Ga, 20Ga(2 each)|IV catheter 20Ga|2|2|~|~|~|quantity|band=Green (9 - 13 years);vg=iv-catheter;vl=20Ga|iv-catheter-20g
ht-9-13-years|OPA - 80mm|OPA 80mm|1|1|~|~|~|quantity|band=Green (9 - 13 years)|opa-80mm
ht-9-13-years|NPA - 26F|NPA 26F|1|1|~|~|~|quantity|band=Green (9 - 13 years)|npa-26
ht-9-13-years|French Catheter - 10F, 12F|French catheter 10F|1|1|~|~|~|quantity|band=Green (9 - 13 years);vg=french-catheter;vl=10F|french-catheter-10f
ht-9-13-years|French Catheter - 10F, 12F|French catheter 12F|1|1|~|~|~|quantity|band=Green (9 - 13 years);vg=french-catheter;vl=12F|french-catheter-12f
ht-9-13-years|Syringes - 1cc, 3cc, 10cc(1 each)|Syringe 1cc|1|1|~|~|~|quantity|band=Green (9 - 13 years);vg=syringes;vl=1cc|syringe-1cc
ht-9-13-years|Syringes - 1cc, 3cc, 10cc(1 each)|Syringe 3cc|1|1|~|~|~|quantity|band=Green (9 - 13 years);vg=syringes;vl=3cc|syringe-3cc
ht-9-13-years|Syringes - 1cc, 3cc, 10cc(1 each)|Syringe 10cc|1|1|~|~|~|quantity|band=Green (9 - 13 years);vg=syringes;vl=10cc|tv-syringe-10-cc
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
        $this->assertSame('Handtevy Inventory', $source['title']);
        $this->assertSame('undated', $source['version_label']);
        $this->assertSame('handtevy_inventory.pdf', $source['file_name']);
        $this->assertSame('Top Insert | Amount | Need | 2 year (Yellow) | Amount | Need', $source['printed_header']);

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
        $result = app(SourceImporter::class)->import(base_path('database/data/ems'));

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

    public function test_the_printed_quantity_rule_is_recorded_and_every_item_has_a_quantity_from_it(): void
    {
        $notes = implode(' ', $this->form()->notes['source_notes']);
        $this->assertStringContainsString('Quantity 1 unless indicated in ( )', $notes);
        $this->assertStringContainsString('1 of each size', $notes);

        foreach ($this->items() as $item) {
            $this->assertNotNull($item->min_text, "{$item->source_label}: the per-item quantity is recorded");
            $this->assertIsInt($item->min_qty);
            $this->assertArrayNotHasKey('fill_if_text', $item->flags, 'The Handtevy sheet prints no Fill if column.');
            $this->assertSame('quantity', $item->response_type);
        }
    }

    public function test_parenthesised_quantities_and_grouped_sizes_are_read_as_printed(): void
    {
        $needles = 'Needles - 21Ga 1"(2), 25Ga 1" (1 each)';
        $this->assertSame(['2', 2], [$this->item($needles, 'ht-top-insert', '21Ga 1"')->min_text, $this->item($needles, 'ht-top-insert', '21Ga 1"')->min_qty]);
        $this->assertSame(['1', 1], [$this->item($needles, 'ht-top-insert', '25Ga 1"')->min_text, $this->item($needles, 'ht-top-insert', '25Ga 1"')->min_qty]);
        $this->assertSame(['needle-21ga-1in', 'needle-25ga-1in'], [
            $this->item($needles, 'ht-top-insert', '21Ga 1"')->catalogItem->key, $this->item($needles, 'ht-top-insert', '25Ga 1"')->catalogItem->key,
        ]);

        $top = $this->items()->where('section_key', 'ht-top-insert');
        $this->assertSame(['2', '2', '2'], $top->where('source_label', 'Syringes- 1cc(2), 3cc(2), 10cc(2)')->pluck('min_text')->all());
        $this->assertSame(['1cc', '3cc', '10cc'], $top->where('source_label', 'Syringes- 1cc(2), 3cc(2), 10cc(2)')->pluck('variant_label')->all());
        $this->assertSame(['1 roll', 1, 'roll'], [$this->item('Tape- 1"(1roll)')->min_text, $this->item('Tape- 1"(1roll)')->min_qty, $this->item('Tape- 1"(1roll)')->min_unit]);
        $this->assertSame('2', $this->item('Three-way stopcock(2)')->min_text);
        $this->assertSame('located in each pouch', $this->item('IV Start Kit(located in each pouch)')->flags['location_text']);
        $this->assertSame('1', $this->item('IV Start Kit(located in each pouch)')->min_text);

        $bottom = $this->items()->where('section_key', 'ht-bottom-bin');
        $this->assertSame(['1.0', '1.5', '2.0', '2.5'], $bottom->where('source_label', 'iGels 1.0 / 1.5 / 2.0 / 2.5')->pluck('variant_label')->all());
        $this->assertSame(['1', '1', '1', '1'], $bottom->where('source_label', 'iGels 1.0 / 1.5 / 2.0 / 2.5')->pluck('min_text')->all());
        $this->assertSame(['3 sets', 3, 'sets'], [$this->item('Trauma Sleevs(3 sets)')->min_text, $this->item('Trauma Sleevs(3 sets)')->min_qty, $this->item('Trauma Sleevs(3 sets)')->min_unit]);
        $this->assertSame('Trauma sleeves', $this->item('Trauma Sleevs(3 sets)')->label);
    }

    public function test_iv_catheters_and_the_9_to_13_year_rows_keep_their_own_printed_quantities(): void
    {
        $perAge = [
            'ht-under-1' => ['22Ga', '24Ga'],
            'ht-1-year' => ['22Ga', '24Ga'],
            'ht-2-year' => ['18Ga', '20Ga', '22Ga'],
            'ht-3-4-years' => ['18Ga', '20Ga', '22Ga'],
            'ht-5-6-years' => ['18Ga', '20Ga', '22Ga'],
            'ht-7-8-years' => ['18Ga', '20Ga'],
            'ht-9-13-years' => ['18Ga', '20Ga'],
        ];
        foreach ($perAge as $section => $gauges) {
            $catheters = $this->items()->where('section_key', $section)->where('variant_group', 'iv-catheter');
            $this->assertSame($gauges, $catheters->pluck('variant_label')->all(), $section);
            $this->assertSame(array_fill(0, count($gauges), '2'), $catheters->pluck('min_text')->all(), "{$section}: (2 each)");
        }

        $green = $this->items()->where('section_key', 'ht-9-13-years');
        $this->assertSame(['6.5mm', '7.0mm'], $green->where('variant_group', 'et-tube')->pluck('variant_label')->all());
        $this->assertSame(['1', '1'], $green->where('variant_group', 'et-tube')->pluck('min_text')->all());
        $this->assertSame(['10F', '12F'], $green->where('variant_group', 'french-catheter')->pluck('variant_label')->all());
        $this->assertSame(['1cc', '3cc', '10cc'], $green->where('variant_group', 'syringes')->pluck('variant_label')->all());
        $this->assertSame(['Straight', 'Curved'], $green->where('variant_group', 'blade-3')->pluck('variant_label')->all());
        $this->assertSame(['5 & 6 year (Blue)' => ['Straight', 'Curved']], [
            '5 & 6 year (Blue)' => $this->items()->where('section_key', 'ht-5-6-years')->where('variant_group', 'blade-2')->pluck('variant_label')->all(),
        ]);
    }

    public function test_the_under_1_block_is_kept_as_printed_and_flagged_for_a_person(): void
    {
        $block = $this->items()->where('section_key', 'ht-under-1');
        $this->assertCount(13, $block);
        foreach ($block as $item) {
            $this->assertTrue($item->needs_review, $item->source_label);
            $this->assertSame('Grey / Pink / Red (< 1 year)', $item->flags['color_band']);
            $this->assertStringContainsString('does not say which size belongs to which color', (string) $item->review_reason);
        }
        $this->assertSame(['ET Tube- 2.5mm Cuffed', 'ET Tube- 3.0mm Cuffed', 'ET Tube- 3.5mm Cuffed'], $block->whereIn('source_label', ['ET Tube- 2.5mm Cuffed', 'ET Tube- 3.0mm Cuffed', 'ET Tube- 3.5mm Cuffed'])->pluck('source_label')->all());
        $this->assertCount(0, $this->items()->where('section_key', '!=', 'ht-under-1')->where('needs_review', true));
    }

    public function test_each_age_block_carries_its_printed_color_and_age_and_the_top_blocks_carry_none(): void
    {
        $bands = $this->items()->groupBy('section_key')->map(fn ($group) => $group->pluck('flags')->pluck('color_band')->unique()->values()->all())->all();

        $this->assertSame([
            'ht-top-insert' => [null],
            'ht-bottom-bin' => [null],
            'ht-under-1' => ['Grey / Pink / Red (< 1 year)'],
            'ht-1-year' => ['Purple (1 year)'],
            'ht-2-year' => ['Yellow (2 years)'],
            'ht-3-4-years' => ['White (3 & 4 years)'],
            'ht-5-6-years' => ['Blue (5 & 6 years)'],
            'ht-7-8-years' => ['Orange (7 & 8 years)'],
            'ht-9-13-years' => ['Green (9 - 13 years)'],
        ], $bands);
    }

    public function test_the_sheet_has_no_amount_numbers_and_no_unit_other_than_what_is_printed(): void
    {
        $this->assertEqualsCanonicalizing([null, 'roll', 'sets'], $this->items()->pluck('min_unit')->unique()->values()->all());
    }

}
