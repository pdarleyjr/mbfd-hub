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
 * The paper "M.B.F.D. A.L.S. NON-TRANSPORT UNIT WEEKLY INVENTORY" (Revised 1-2024) against the ALS Non Transport list.
 * SHEET is the transcription in sheet reading order: section key | printed text | label | AMOUNT | AMOUNT number | AMOUNT unit |
 * "Fill if <" | "Fill if <" number | response type | extras (loc, qual, band, cad, rev, vg, vl) | catalog ref. "~" is empty.
 * Every printed row has to be on the form, in this order, with the values as printed.
 */
final class NonTransportSheetTest extends TestCase
{
    use BuildsEmsWorld;
    use ImportsTrackedList;
    use RefreshDatabase;

    private const KEY = 'als-non-transport';

    private const FILE = 'als_non_transport_inventory.json';

    private const SHA256 = 'fc57800508326768cee8724a304b337c5588f8ea00a6dffba2a70a68e66cd625';

    private const ITEM_PREFIX = 'nt.';

    /** The sections as the form shows them: key => [label, item count]. */
    private const SECTIONS = [
        'nt-handtevy' => ['Handtevy', 2],
        'nt-medications' => ['Medications', 28],
        'nt-iv-needles' => ['I.V. / Needles', 33],
        'nt-airway-left' => ['Airway (left column)', 17],
        'nt-airway-middle' => ['Airway (middle column)', 12],
        'nt-airway-bvm-bag' => ['Airway / BVM Bag', 52],
        'nt-trauma-supplies' => ['Trauma Supplies', 34],
        'nt-ppe-decon' => ['PPE - Decon', 30],
        'nt-ekg-supplies' => ['EKG Supplies', 5],
        'nt-miscellaneous' => ['Miscellaneous', 12],
        'nt-life-pack-inventory' => ['Life Pack Inventory', 21],
    ];

    /** Catalog keys this list shares with the three older lists / keys only this and the other new lists use. */
    private const CATALOG_REUSED = 193;

    private const CATALOG_NEW = 44;

    private const SHEET = <<<'SHEET'
nt-handtevy|HANDTEVY|Handtevy|1|1|~|1|1|quantity|~|handtevy-kit
nt-handtevy|HANDTEVY 1st Tuesday-Monthly Inventory-Inspection completed|Handtevy monthly inventory-inspection completed|~|~|~|~|~|yes_no|cad=monthly_first_tuesday|~
nt-medications|Adenocard 12mg|Adenocard 12 mg|2|2|~|2|2|quantity|~|adenocard-12mg
nt-medications|Amiodarone|Amiodarone|4|4|~|4|4|quantity|~|amiodarone
nt-medications|Atropine|Atropine|3|3|~|3|3|quantity|~|atropine
nt-medications|Baby Aspirin|Baby Aspirin|1|1|~|1|1|quantity|~|tv-baby-aspirin
nt-medications|Benadryl|Benadryl|2|2|~|2|2|quantity|~|benadryl
nt-medications|Bicarb 8.4%|Bicarb 8.4%|2|2|~|2|2|quantity|~|sodium-bicarb-8-4
nt-medications|Bicarb 4.2%|Bicarb 4.2%|1|1|~|1|1|quantity|~|sodium-bicarb-4-2
nt-medications|Calcium chloride|Calcium chloride|1|1|~|1|1|quantity|~|calcium-chloride
nt-medications|Cardene|Cardene|1|1|~|1|1|quantity|~|cardene
nt-medications|Dextrose 50%|Dextrose 50%|2|2|~|2|2|quantity|~|dextrose-50
nt-medications|Dextrose 25%|Dextrose 25%|1|1|~|1|1|quantity|~|dextrose-25
nt-medications|Dopamine drip|Dopamine drip|1|1|~|1|1|quantity|~|dopamine
nt-medications|Duoneb|Duoneb|4|4|~|4|4|quantity|~|duoneb-als-bag
nt-medications|Epi 1:10,000|Epi 1:10,000|5|5|~|5|5|quantity|~|epi-1-10000
nt-medications|Epi 1:1,000|Epi 1:1,000|4|4|~|4|4|quantity|~|epi-1-1000
nt-medications|Etomidate|Etomidate|1|1|~|1|1|quantity|~|etomidate
nt-medications|Glucose oral gel|Glucose oral gel|2|2|~|2|2|quantity|~|glucose-gel
nt-medications|Glucagon|Glucagon|1|1|~|1|1|quantity|~|glucagon
nt-medications|Lasix|Lasix|2|2|~|2|2|quantity|~|lasix
nt-medications|Lidocaine|Lidocaine|2|2|~|2|2|quantity|~|lidocaine
nt-medications|Magnesium sulf.|Magnesium sulf.|1|1|~|1|1|quantity|~|mag-sulfate
nt-medications|Narcan|Narcan|2|2|~|2|2|quantity|~|narcan
nt-medications|Nitro Spray|Nitro Spray|1|1|~|1|1|quantity|~|nitro-spray-als-bag
nt-medications|Racemic Epi|Racemic Epi|2|2|~|2|2|quantity|~|racemic-epi
nt-medications|Solu-Mederol|Solu-Medrol|1|1|~|1|1|quantity|~|solu-medrol
nt-medications|Tetracaine drops|Tetracaine drops|1|1|~|1|1|quantity|~|tetracaine-drops
nt-medications|Zofran Oral|Zofran Oral|6|6|~|6|6|quantity|~|zofran-oral
nt-medications|Zofran IV|Zofran IV|2|2|~|2|2|quantity|~|zofran-iv
nt-iv-needles|Glucometer strips|Glucometer strips|25|25|~|25|25|quantity|~|test-strips
nt-iv-needles|Gluco lancettes|Gluco lancets|25|25|~|25|25|quantity|~|lancets
nt-iv-needles|Mini drips|Mini drips|2|2|~|2|2|quantity|~|mini-drip-set
nt-iv-needles|Maxi drips|Maxi drips|2|2|~|2|2|quantity|~|maxi-drip-set
nt-iv-needles|Extension sets|Extension sets|5|5|~|5|5|quantity|~|tv-extension-sets
nt-iv-needles|3 way stopcock|3 way stopcock|1|1|~|1|1|quantity|~|three-way-stopcock
nt-iv-needles|Veniguards|Veniguards|5|5|~|5|5|quantity|~|veniguard
nt-iv-needles|Adult EZ-IO (BLUE)|Adult EZ-IO (BLUE)|2 IN BOX|2|in box|2|2|quantity|~|tv-adult-ez-io-blue
nt-iv-needles|Pedi EZ-IO (PINK)|Pedi EZ-IO (PINK)|1 IN BOX|1|in box|1|1|quantity|~|tv-pedi-ez-io-pink
nt-iv-needles|Lg Adult EZ-IO (YELLOW)|Lg Adult EZ-IO (YELLOW)|1 IN BOX|1|in box|1|1|quantity|~|tv-large-adult-ez-io-yellow
nt-iv-needles|IV cath 24ga|IV cath 24ga|4|4|~|4|4|quantity|~|iv-catheter-24g
nt-iv-needles|IV cath 22ga|IV cath 22ga|4|4|~|4|4|quantity|~|iv-catheter-22g
nt-iv-needles|IV cath 20ga|IV cath 20ga|4|4|~|4|4|quantity|~|iv-catheter-20g
nt-iv-needles|IV cath 18ga|IV cath 18ga|4|4|~|4|4|quantity|~|iv-catheter-18g
nt-iv-needles|IV cath 16ga|IV cath 16ga|2|2|~|2|2|quantity|~|iv-catheter-16g
nt-iv-needles|IV cath 14ga|IV cath 14ga|2|2|~|2|2|quantity|~|iv-catheter-14g
nt-iv-needles|ARS Decomp 14ga 3"|ARS Decomp 14ga 3"|2|2|~|2|2|quantity|~|ars-decomp-14ga-3in
nt-iv-needles|EZ set 21ga ( Butterfly)|EZ set 21ga (Butterfly)|2|2|~|2|2|quantity|~|ez-set-21ga-butterfly
nt-iv-needles|18GA 1.5" FILTERED NEEDLES|18ga 1.5" filtered needles|2|2|~|2|2|quantity|~|needle-18ga-1-5in-filtered
nt-iv-needles|22GA 1.5" ADMIN NEEDLES|22ga 1.5" admin needles|2|2|~|2|2|quantity|~|needle-22ga-1-5in-admin
nt-iv-needles|Saline flush 10cc|Saline flush 10cc|5|5|~|5|5|quantity|~|flush-10cc-als-bag
nt-iv-needles|Syringe 1cc|Syringe 1cc|3|3|~|3|3|quantity|~|syringe-1cc
nt-iv-needles|Syringe 3cc|Syringe 3cc|3|3|~|3|3|quantity|~|syringe-3cc
nt-iv-needles|Syringe 10/12cc|Syringe 10/12cc|3|3|~|3|3|quantity|~|syringe-10-12cc
nt-iv-needles|Syringe 60cc|Syringe 60cc|2|2|~|2|2|quantity|~|syringe-60cc
nt-iv-needles|Armboards 9"|Armboards 9"|3|3|~|3|3|quantity|~|arm-board-9in
nt-iv-needles|Armboards 18"|Armboards 18"|3|3|~|3|3|quantity|~|arm-board-18in
nt-iv-needles|I.V. Tourniquet Latex Free|I.V. tourniquet latex free|5|5|~|5|5|quantity|~|iv-tourniquet-latex-free
nt-iv-needles|Betadine preps|Betadine preps|5|5|~|5|5|quantity|~|betadine-prep
nt-iv-needles|Alcohol preps|Alcohol preps|10|10|~|10|10|quantity|~|alcohol-prep
nt-iv-needles|N. Saline 1000cc|N. Saline 1000cc|2|2|~|2|2|quantity|~|normal-saline-1000
nt-iv-needles|D5w 50cc|D5w 50cc|1|1|~|1|1|quantity|~|d5w-50cc
nt-iv-needles|Nasal MADDET|Nasal MADDET|2|2|~|2|2|quantity|~|nasal-maddet
nt-airway-left|OPA #0 40mm|OPA #0 40mm|2|2|~|2|2|quantity|~|opa-40mm
nt-airway-left|OPA #1 50mm|OPA #1 50mm|2|2|~|2|2|quantity|~|opa-50mm
nt-airway-left|OPA #2 60mm|OPA #2 60mm|2|2|~|2|2|quantity|~|opa-60mm
nt-airway-left|OPA #3 80mm|OPA #3 80mm|2|2|~|2|2|quantity|~|opa-80mm
nt-airway-left|OPA #4 90mm|OPA #4 90mm|2|2|~|2|2|quantity|~|opa-90mm
nt-airway-left|OPA #5 100mm|OPA #5 100mm|2|2|~|2|2|quantity|~|opa-100mm
nt-airway-left|OPA #6 110mm|OPA #6 110mm|2|2|~|2|2|quantity|~|opa-110mm
nt-airway-left|NPA #30 FR|NPA #30 FR|1|1|~|1|1|quantity|~|npa-30
nt-airway-left|NPA #28 FR|NPA #28 FR|1|1|~|1|1|quantity|~|npa-28
nt-airway-left|NPA #26 FR|NPA #26 FR|1|1|~|1|1|quantity|~|npa-26
nt-airway-left|NPA #24 FR|NPA #24 FR|1|1|~|1|1|quantity|~|npa-24
nt-airway-left|NPA #22 FR|NPA #22 FR|1|1|~|1|1|quantity|~|npa-22
nt-airway-left|NPA #20 FR|NPA #20 FR|1|1|~|1|1|quantity|~|npa-20
nt-airway-left|NPA #18 FR|NPA #18 FR|1|1|~|1|1|quantity|~|npa-18
nt-airway-left|NPA #16 FR|NPA #16 FR|1|1|~|1|1|quantity|~|npa-16
nt-airway-left|NPA #14 FR|NPA #14 FR|1|1|~|1|1|quantity|~|npa-14
nt-airway-left|NPA #12 FR|NPA #12 FR|1|1|~|1|1|quantity|~|npa-12
nt-airway-middle|Bite sticks|Bite sticks|2|2|~|2|2|quantity|~|bite-stick
nt-airway-middle|Non-rebreather/adult|Non-rebreather/adult|3|3|~|3|3|quantity|~|non-rebreather-mask-adult
nt-airway-middle|Nasal cannula adult|Nasal cannula adult|3|3|~|3|3|quantity|~|nasal-cannula-adult
nt-airway-middle|Nebulizer adult|Nebulizer adult|2|2|~|2|2|quantity|~|nebulizer-mask-adult
nt-airway-middle|T-nebulizer adult|T-nebulizer adult|2|2|~|2|2|quantity|~|t-nebulizer-adult
nt-airway-middle|Nasal Capnoline adult|Nasal Capnoline adult|2|2|~|2|2|quantity|~|nasal-capnoline-adult
nt-airway-middle|Non-rebreather/pedi|Non-rebreather/pedi|2|2|~|2|2|quantity|~|non-rebreather-pedi
nt-airway-middle|Nasal cannula pedi|Nasal cannula pedi|2|2|~|2|2|quantity|~|nasal-cannula-pedi
nt-airway-middle|Nebulizer pedi|Nebulizer pedi|2|2|~|2|2|quantity|~|nebulizer-mask-pedi
nt-airway-middle|Nasal Capnoline pedi|Nasal Capnoline pedi|2|2|~|2|2|quantity|~|nasal-capnoline-pedi
nt-airway-middle|Non-rebreather/infant|Non-rebreather/infant|2|2|~|2|2|quantity|~|non-rebreather-infant
nt-airway-middle|CPAP tube/mask set|CPAP tube/mask set|1|1|~|1|1|quantity|~|tv-cpap-tube-mask-set
nt-airway-bvm-bag|CURAPLEX BVMs W/ Manometer|CURAPLEX BVMs W/ Manometer|2|2|~|1|1|quantity|~|curaplex-bvm-manometer
nt-airway-bvm-bag|ET Tube extention(1) PLACED IN BVM BAG|ET tube extension|~|~|~|1 in bag|1|quantity|loc=Placed in BVM bag;rev=1|et-tube-extension
nt-airway-bvm-bag|BVM CO Capnoline (1) PLACED IN BVM BAG|BVM CO Capnoline|~|~|~|1 in bag|1|quantity|loc=Placed in BVM bag;rev=1|bvm-co-capnoline
nt-airway-bvm-bag|i-gel 5 (1) PLACED IN BVM BAG|i-gel 5|~|~|~|1 in bag|1|quantity|loc=Placed in BVM bag;rev=1|igel-5
nt-airway-bvm-bag|i-gel 4 (1) PLACED IN BVM BAG|i-gel 4|~|~|~|1 in bag|1|quantity|loc=Placed in BVM bag;rev=1|igel-4
nt-airway-bvm-bag|i-gel 3 (1) PLACED IN BVM BAG|i-gel 3|~|~|~|1 in bag|1|quantity|loc=Placed in BVM bag;rev=1|igel-3
nt-airway-bvm-bag|i-gel 2.5 -IN HANDTEVY-|i-gel 2.5|1|1|~|1|1|quantity|loc=In Handtevy|tv-i-gel-size-2-5
nt-airway-bvm-bag|i-gel 2.0 -IN HANDTEVY-|i-gel 2.0|1|1|~|1|1|quantity|loc=In Handtevy|tv-i-gel-size-2-0
nt-airway-bvm-bag|i-gel 1.5 -IN HANDTEVY-|i-gel 1.5|1|1|~|1|1|quantity|loc=In Handtevy|tv-i-gel-size-1-5
nt-airway-bvm-bag|i-gel 1.0 -IN HANDTEVY-|i-gel 1.0|1|1|~|1|1|quantity|loc=In Handtevy|tv-i-gel-size-1-0
nt-airway-bvm-bag|CURAPLEX infant BVM -IN HANDTEVY-|CURAPLEX infant BVM|1|1|~|1|1|quantity|loc=In Handtevy|curaplex-infant-bvm
nt-airway-bvm-bag|ET tube 2.5 (cuffed)|ET tube 2.5 (cuffed)|1|1|~|1|1|quantity|~|et-tube-2-5-cuffed
nt-airway-bvm-bag|ET tube 3.0 (cuffed)|ET tube 3.0 (cuffed)|1|1|~|1|1|quantity|~|et-tube-3-0-cuffed
nt-airway-bvm-bag|ET tube 3.5 (cuffed)|ET tube 3.5 (cuffed)|1|1|~|1|1|quantity|~|et-tube-3-5-cuffed
nt-airway-bvm-bag|ET tube 4.0 (cuffed)|ET tube 4.0 (cuffed)|1|1|~|1|1|quantity|~|et-tube-4-0-cuffed
nt-airway-bvm-bag|ET tube 4.5 (cuffed)|ET tube 4.5 (cuffed)|1|1|~|1|1|quantity|~|et-tube-4-5-cuffed
nt-airway-bvm-bag|ET tube 5.0 (cuffed)|ET tube 5.0 (cuffed)|1|1|~|1|1|quantity|~|et-tube-5-0-cuffed
nt-airway-bvm-bag|ET tube 5.5 (cuffed)|ET tube 5.5 (cuffed)|1|1|~|1|1|quantity|~|et-tube-5-5-cuffed
nt-airway-bvm-bag|ET tube 6.0 (cuffed)|ET tube 6.0 (cuffed)|1|1|~|1|1|quantity|~|et-tube-6-0-cuffed
nt-airway-bvm-bag|ET tube 6.5 (cuffed)|ET tube 6.5 (cuffed)|1|1|~|1|1|quantity|~|et-tube-6-5-cuffed
nt-airway-bvm-bag|ET tube 7.0 (cuffed)|ET tube 7.0 (cuffed)|2|2|~|2|2|quantity|~|et-tube-7-0-cuffed
nt-airway-bvm-bag|ET tube 7.5 (cuffed)|ET tube 7.5 (cuffed)|2|2|~|2|2|quantity|~|et-tube-7-5-cuffed
nt-airway-bvm-bag|ET tube 8.0 (cuffed)|ET tube 8.0 (cuffed)|1|1|~|1|1|quantity|~|et-tube-8-0-cuffed
nt-airway-bvm-bag|ET tube 8.5 (cuffed)|ET tube 8.5 (cuffed)|1|1|~|1|1|quantity|~|et-tube-8-5-cuffed
nt-airway-bvm-bag|ET tube 9.0 (cuffed)|ET tube 9.0 (cuffed)|1|1|~|1|1|quantity|~|et-tube-9-0-cuffed
nt-airway-bvm-bag|Bougie 10Fr|Bougie 10Fr|1|1|~|1|1|quantity|~|bougie-10fr
nt-airway-bvm-bag|Bougie 15Fr|Bougie 15Fr|1|1|~|1|1|quantity|~|bougie-15fr
nt-airway-bvm-bag|Tube-lock / adult|Tube-lock / adult|2|2|~|2|2|quantity|~|tube-holder-adult
nt-airway-bvm-bag|Tube-lock / pedi|Tube-lock / pedi|1|1|~|1|1|quantity|~|tube-holder-pedi
nt-airway-bvm-bag|Suction cath 6Fr|Suction cath 6Fr|1|1|~|1|1|quantity|~|suction-catheter-6fr
nt-airway-bvm-bag|Suction cath 8Fr|Suction cath 8Fr|1|1|~|1|1|quantity|~|suction-catheter-8fr
nt-airway-bvm-bag|Suction cath 10Fr|Suction cath 10Fr|1|1|~|1|1|quantity|~|suction-catheter-10fr
nt-airway-bvm-bag|Suction cath 12Fr|Suction cath 12Fr|1|1|~|1|1|quantity|~|suction-catheter-12fr
nt-airway-bvm-bag|Suction cath 14Fr|Suction cath 14Fr|1|1|~|1|1|quantity|~|suction-catheter-14fr
nt-airway-bvm-bag|Suction cath 16Fr|Suction cath 16Fr|1|1|~|1|1|quantity|~|suction-catheter-16fr
nt-airway-bvm-bag|Suction cath 18Fr|Suction cath 18Fr|1|1|~|1|1|quantity|~|suction-catheter-18fr
nt-airway-bvm-bag|Suction yankauer|Suction yankauer|2|2|~|2|2|quantity|~|tv-suction-yankauer
nt-airway-bvm-bag|Meconium aspirators|Meconium aspirators|1|1|~|1|1|quantity|~|meconium-aspirator
nt-airway-bvm-bag|Portable suction canister|Portable suction canister|1|1|~|1|1|quantity|~|tv-portable-suction-canister
nt-airway-bvm-bag|Portable suction tubing|Portable suction tubing|1|1|~|1|1|quantity|~|tv-portable-suction-tubing
nt-airway-bvm-bag|Lubricating gel|Lubricating gel|5|5|~|5|5|quantity|~|tv-lubricating-gel
nt-airway-bvm-bag|MAC #4|MAC #4|1|1|~|1|1|quantity|~|mac-blade-4
nt-airway-bvm-bag|MAC #3|MAC #3|1|1|~|1|1|quantity|~|mac-blade-3
nt-airway-bvm-bag|MAC #2|MAC #2|1|1|~|1|1|quantity|~|mac-blade-2
nt-airway-bvm-bag|MILLER #3|MILLER #3|1|1|~|1|1|quantity|~|miller-blade-3
nt-airway-bvm-bag|MILLER #2|MILLER #2|1|1|~|1|1|quantity|~|miller-blade-2
nt-airway-bvm-bag|MILLER #1|MILLER #1|1|1|~|1|1|quantity|~|miller-blade-1
nt-airway-bvm-bag|MILLER #0|MILLER #0|1|1|~|1|1|quantity|~|miller-blade-0
nt-airway-bvm-bag|Quick-trach / adult|Quick-trach / adult|1 in bag|1|in bag|1|1|quantity|~|adult-quick-trach
nt-airway-bvm-bag|Quick-trach / pedi|Quick-trach / pedi|1 in bag|1|in bag|1|1|quantity|~|pedi-quick-trach
nt-airway-bvm-bag|ET Tube MADD Device|ET tube MADD device|1|1|~|1|1|quantity|~|et-madd-device
nt-airway-bvm-bag|Tracheostomy Mask Set|Tracheostomy Mask Set|1|1|~|1|1|quantity|~|tracheostomy-mask-set
nt-trauma-supplies|500 ml irrigation water|500 ml irrigation water|2|2|~|2|2|quantity|~|tv-500-ml-irrigation-water
nt-trauma-supplies|C-collar-adult -regular|C-collar-adult -regular|5|5|~|3|3|quantity|~|c-collar-adult-regular
nt-trauma-supplies|C-collar/adult-no-neck|C-collar/adult-no-neck|5|5|~|3|3|quantity|~|tv-c-collar-adult-no-neck
nt-trauma-supplies|C-collar-adjustable pedi|C-collar-adjustable pedi|2|2|~|2|2|quantity|~|tv-c-collar-adjustable-pedi
nt-trauma-supplies|Cardboard splints short|Cardboard splints short|3|3|~|3|3|quantity|~|tv-cardboard-splints-short
nt-trauma-supplies|Cardboard splints long|Cardboard splints long|3|3|~|3|3|quantity|~|tv-cardboard-splints-long
nt-trauma-supplies|Burn sheets|Burn sheets|2|2|~|2|2|quantity|~|tv-burn-sheets
nt-trauma-supplies|Burn Gel 4 x 16 Dressing|Burn Gel 4 x 16 Dressing|2|2|~|2|2|quantity|~|burn-gel-4x16-dressing
nt-trauma-supplies|Burn Gel Face Mask|Burn Gel Face Mask|1|1|~|1|1|quantity|~|burn-gel-face-mask
nt-trauma-supplies|Burn Gel 4x4 Dressing|Burn Gel 4x4 Dressing|2|2|~|2|2|quantity|~|tv-burn-gel-4-x-4-dressing
nt-trauma-supplies|Burn Gel 3.5g Packets|Burn Gel 3.5g Packets|4|4|~|4|4|quantity|~|burn-gel-small-packet
nt-trauma-supplies|1/2" tape|1/2" tape|1|1|~|1|1|quantity|~|tape-1-2in
nt-trauma-supplies|1" tape|1" tape|1|1|~|1|1|quantity|~|tape-1in
nt-trauma-supplies|3" tape|3" tape|1|1|~|1|1|quantity|~|tape-3in
nt-trauma-supplies|4" ace bandage|4" ace bandage|4|4|~|4|4|quantity|~|ace-bandage-4in
nt-trauma-supplies|6" ace bandage|6" ace bandage|4|4|~|4|4|quantity|~|ace-bandage-6in
nt-trauma-supplies|T-bandages|T-bandages|2|2|~|2|2|quantity|~|tv-t-bandages
nt-trauma-supplies|TPOD Pelvic Device|TPOD Pelvic Device|1|1|~|1|1|quantity|~|tpod-pelvic-device
nt-trauma-supplies|Carry alls|Carry alls|2|2|~|2|2|quantity|~|carry-all
nt-trauma-supplies|Combat App. Tourniquet CAT ( MCI BAG)|Combat App. Tourniquet CAT|10|10|~|10|10|quantity|qual=MCI BAG|cat-tourniquet
nt-trauma-supplies|Kling 2"|Kling 2"|4|4|~|4|4|quantity|~|kling-2in
nt-trauma-supplies|Kling 4"|Kling 4"|4|4|~|4|4|quantity|~|kling-4in
nt-trauma-supplies|Kling 6"|Kling 6"|4|4|~|4|4|quantity|~|kling-6in
nt-trauma-supplies|Steri pads 2x2"|Steri pads 2x2"|5 each|5|each|5 each|5|quantity|~|steri-pad-2x2
nt-trauma-supplies|Steri pads 4x4"|Steri pads 4x4"|5 each|5|each|5 each|5|quantity|~|steri-pad-4x4
nt-trauma-supplies|Surgi pads 5x9"|Surgi pads 5x9"|5 each|5|each|5 each|5|quantity|~|tv-surgi-pads-5-x-9
nt-trauma-supplies|Trauma dressing 12x30"|Trauma dressing 12x30"|2|2|~|2|2|quantity|~|tv-trauma-dressing-12-x-30
nt-trauma-supplies|Band aids 1 x 3" small|Band aids 1 x 3" small|10|10|~|10|10|quantity|~|tv-band-aids-1-x-3
nt-trauma-supplies|Band aids 4 1/2" x 2" large|Band aids 4 1/2" x 2" large|10|10|~|10|10|quantity|~|tv-band-aids-4-1-2-x-2
nt-trauma-supplies|Speedy Cleans|Speedy Cleans|1|1|~|1|1|quantity|~|speedy-cleans
nt-trauma-supplies|Quick-clot|Quick-clot|2|2|~|2|2|quantity|~|quikclot-gauze
nt-trauma-supplies|Ascherman chest seals|Asherman chest seals|2|2|~|2|2|quantity|~|asherman-chest-seal
nt-trauma-supplies|Cold packs|Cold packs|5|5|~|5|5|quantity|~|tv-cold-packs
nt-trauma-supplies|Ocular Eye Patch|Ocular Eye Patch|2|2|~|2|2|quantity|~|ocular-eye-patch
nt-ppe-decon|Latex gloves XXL|Latex gloves XXL|1 box|1|box|1 box|1|quantity|~|latex-gloves-xxl
nt-ppe-decon|Latex gloves XL|Latex gloves XL|1 box|1|box|1 box|1|quantity|~|latex-gloves-xl
nt-ppe-decon|Latex gloves Large|Latex gloves Large|1 box|1|box|1 box|1|quantity|~|latex-gloves-large
nt-ppe-decon|Latex gloves medium|Latex gloves medium|1 box|1|box|1 box|1|quantity|~|latex-gloves-medium
nt-ppe-decon|Latex gloves small|Latex gloves small|1 box|1|box|1 box|1|quantity|~|latex-gloves-small
nt-ppe-decon|Purple nitrile gloves XL|Purple nitrile gloves XL|1 box|1|box|1 box|1|quantity|~|purple-nitrile-gloves-xl
nt-ppe-decon|Purple nitrile gloves LG|Purple nitrile gloves LG|1 box|1|box|1 box|1|quantity|~|purple-nitrile-gloves-lg
nt-ppe-decon|Purple nitrile gloves MED|Purple nitrile gloves MED|1 box|1|box|1 box|1|quantity|~|purple-nitrile-gloves-med
nt-ppe-decon|Purple nitrile gloves SM|Purple nitrile gloves SM|1 box|1|box|1 box|1|quantity|~|purple-nitrile-gloves-sm
nt-ppe-decon|Tyvek suits 2XL|Tyvek suits 2XL|2|2|~|2|2|quantity|~|tv-tyvek-suits-2xl
nt-ppe-decon|Tyvek suits XL|Tyvek suits XL|2|2|~|2|2|quantity|~|tv-tyvek-suits-xl
nt-ppe-decon|Shoe covers|Shoe covers|3 pair|3|pair|3 pair|3|quantity|~|tv-shoe-covers
nt-ppe-decon|Tyvek sleeves|Tyvek sleeves|24|24|~|12|12|quantity|~|tv-tyvek-sleeves
nt-ppe-decon|N95 Masks|N95 Masks|10|10|~|10|10|quantity|~|n95-respirator
nt-ppe-decon|3/4 Face shields|3/4 Face shields|3|3|~|3|3|quantity|~|tv-3-4-face-shields
nt-ppe-decon|Safety Glasses (Clear)|Safety glasses (clear)|4|4|~|4|4|quantity|~|safety-glasses
nt-ppe-decon|Sanizide 32oz with Sprayer|Sanizide 32oz with Sprayer|2|2|~|1|1|quantity|~|sanizide-32oz-with-sprayer
nt-ppe-decon|Sanizide 1 Gallon|Sanizide 1 Gallon|1|1|~|1|1|quantity|~|sanizide-1-gallon
nt-ppe-decon|Convenience bags(VOMEX)|Convenience bags (VOMEX)|4|4|~|4|4|quantity|~|tv-convenience-bags
nt-ppe-decon|Peroxide bottle|Peroxide bottle|1|1|~|1|1|quantity|~|tv-peroxide-bottle
nt-ppe-decon|Alcohol bottle|Alcohol bottle|1|1|~|1|1|quantity|~|tv-alcohol-bottle
nt-ppe-decon|Red biohazard bags (LG)|Red biohazard bags (LG)|5|5|~|3|3|quantity|~|red-biohazard-bags-lg
nt-ppe-decon|Red biohazard bags ( Sm)|Red biohazard bags (Sm)|5|5|~|3|3|quantity|~|red-biohazard-bags-sm
nt-ppe-decon|Cida hand gel|Cida hand gel|2|2|~|2|2|quantity|~|tv-cida-hand-gel
nt-ppe-decon|Sharps container Small|Sharps container Small|1|1|~|1|1|quantity|~|tv-sharps-container-small
nt-ppe-decon|Sharps shuttle|Sharps shuttle|1|1|~|1|1|quantity|~|sharps-shuttle
nt-ppe-decon|Wipes|Wipes|1|1|~|1|1|quantity|~|wipes
nt-ppe-decon|Manual B/P Cuffs Adult/ Pedi|Manual B/P cuff Adult|1 each|1|each|1 each|1|quantity|vg=manual-b-p-cuffs;vl=Adult|manual-bp-cuff-adult
nt-ppe-decon|Manual B/P Cuffs Adult/ Pedi|Manual B/P cuff Pedi|1 each|1|each|1 each|1|quantity|vg=manual-b-p-cuffs;vl=Pedi|manual-bp-cuff-pedi
nt-ppe-decon|Stethescope|Stethoscope|1|1|~|1|1|quantity|~|stethoscope
nt-ekg-supplies|EKG electrodes|EKG electrodes|2 bags|2|bags|2 bags|2|quantity|~|tv-ekg-electrodes
nt-ekg-supplies|Combi pads-adult|Combi pads-adult|2|2|~|2|2|quantity|~|tv-combi-pads-adult
nt-ekg-supplies|Combi pads-pedi|Combi pads-pedi|1|1|~|1|1|quantity|~|tv-combi-pads-pedi
nt-ekg-supplies|EKG paper lifepack 15|EKG paper lifepack 15|2 rolls|2|rolls|2 roll|2|quantity|~|tv-ekg-paper-lifepak-15
nt-ekg-supplies|Disposable razors|Disposable razors|2 pack|2|pack|2 razors|2|quantity|~|tv-disposable-razors
nt-miscellaneous|Disp. Thermometer Strips|Disp. Thermometer Strips|5|5|~|3|3|quantity|~|disp-thermometer-strips
nt-miscellaneous|Pen Lights|Pen Lights|1|1|~|1|1|quantity|~|pen-light
nt-miscellaneous|Yellow foam blanket|Yellow foam blanket|3|3|~|3|3|quantity|~|tv-yellow-foam-blanket
nt-miscellaneous|Foil rescue blanket|Foil rescue blanket|1|1|~|1|1|quantity|~|foil-rescue-blanket
nt-miscellaneous|Ammonia inhalants / wipes|Ammonia inhalants / wipes|1 box|1|box|1 box|1|quantity|~|ammonia-inhalants-wipes
nt-miscellaneous|Vinegar|Vinegar|1|1|~|1|1|quantity|~|vinegar
nt-miscellaneous|OB kit|OB kit|2|2|~|2|2|quantity|~|tv-ob-kit
nt-miscellaneous|Backboard with Disposable Straps|Backboard with Disposable Straps|1|1|~|1|1|quantity|~|backboard-with-disposable-straps
nt-miscellaneous|Disposable Straps|Disposable Straps|2 pair|2|pair|1 pair|1|quantity|~|tv-disposable-straps
nt-miscellaneous|Adult Manual BP Cuff|Adult Manual BP Cuff|1|1|~|1|1|quantity|~|manual-bp-cuff-adult
nt-miscellaneous|Pedi Manual BP Cuff -IN HANDTEVY-|Pedi Manual BP Cuff|1|1|~|1|1|quantity|loc=In Handtevy|manual-bp-cuff-pedi
nt-miscellaneous|Infant Manual BP Cuff -IN HANDTEVY-|Infant Manual BP Cuff|1|1|~|1|1|quantity|loc=In Handtevy|manual-bp-cuff-infant
nt-life-pack-inventory|Modem|Modem|1|1|~|1|1|quantity|~|lifepak-modem
nt-life-pack-inventory|Ammonia inhalants|Ammonia inhalants|1 box|1|box|1 box|1|quantity|~|ammonia-inhalants-als-bag
nt-life-pack-inventory|SPO2-PULSEOX -sensor-Connected to LP|SPO2-PULSEOX sensor|1|1|~|1|1|quantity|loc=Connected to LP|lifepak-spo2-pulseox-sensor
nt-life-pack-inventory|CO2-RAINBOW - sensor-Rear Pouch of LP|CO2-RAINBOW sensor|1|1|~|1|1|quantity|loc=Rear Pouch of LP|lifepak-co2-rainbow-sensor
nt-life-pack-inventory|SPO2 PEDIATRIC sensor (single use)|SPO2 PEDIATRIC sensor|1|1|~|1|1|quantity|qual=single use|spo2-pediatric-sensor
nt-life-pack-inventory|SPO2 INFANT sensor (single use)|SPO2 INFANT sensor|1|1|~|1|1|quantity|qual=single use|spo2-infant-sensor
nt-life-pack-inventory|Combi Pads ADULT|Combi Pads ADULT|2|2|~|2|2|quantity|~|tv-combi-pads-adult
nt-life-pack-inventory|Combi Pads PEDI|Combi Pads PEDI|1|1|~|1|1|quantity|~|tv-combi-pads-pedi
nt-life-pack-inventory|EKG Electrodes|EKG Electrodes|1 bag|1|bag|1 bag|1|quantity|~|tv-ekg-electrodes
nt-life-pack-inventory|EKG Lifepack 15 Paper|EKG Lifepack 15 Paper|1|1|~|1|1|quantity|~|tv-ekg-paper-lifepak-15
nt-life-pack-inventory|Disposable Razors|Disposable Razors|2|2|~|1|1|quantity|~|tv-disposable-razors
nt-life-pack-inventory|4 Lead Cable|4 Lead Cable|1|1|~|1|1|quantity|~|lifepak-4-lead-cable
nt-life-pack-inventory|12 Lead Cable|12 Lead Cable|1|1|~|1|1|quantity|~|lifepak-12-lead-cable
nt-life-pack-inventory|Therapy Cable- (COMBI Pads Cable)|Therapy cable (COMBI pads cable)|1|1|~|1|1|quantity|~|lifepak-therapy-cable
nt-life-pack-inventory|Glucometer w/ Lancettes & strips|Glucometer w/ lancets & strips|1|1|~|1|1|quantity|~|glucometer-with-lancets-and-strips
nt-life-pack-inventory|Auto BP Cuffs XL, L, REG,, PEDI, INFANT|Auto BP cuff XL|1 each|1|each|1 each|1|quantity|vg=auto-bp-cuffs;vl=XL|tv-auto-bp-cuff-adult-xl
nt-life-pack-inventory|Auto BP Cuffs XL, L, REG,, PEDI, INFANT|Auto BP cuff L|1 each|1|each|1 each|1|quantity|vg=auto-bp-cuffs;vl=L|tv-auto-bp-cuff-adult-lg
nt-life-pack-inventory|Auto BP Cuffs XL, L, REG,, PEDI, INFANT|Auto BP cuff REG|1 each|1|each|1 each|1|quantity|vg=auto-bp-cuffs;vl=REG|tv-auto-bp-cuff-adult-reg
nt-life-pack-inventory|Auto BP Cuffs XL, L, REG,, PEDI, INFANT|Auto BP cuff PEDI|1 each|1|each|1 each|1|quantity|vg=auto-bp-cuffs;vl=PEDI|tv-auto-bp-cuff-pedi
nt-life-pack-inventory|Auto BP Cuffs XL, L, REG,, PEDI, INFANT|Auto BP cuff INFANT|1 each|1|each|1 each|1|quantity|vg=auto-bp-cuffs;vl=INFANT|tv-auto-bp-cuff-infant
nt-life-pack-inventory|Stethoscope|Stethoscope|1|1|~|1|1|quantity|~|stethoscope
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
        $this->assertSame('M.B.F.D. A.L.S. NON-TRANSPORT UNIT WEEKLY INVENTORY', $source['title']);
        $this->assertSame('Revised 1-2024', $source['version_label']);
        $this->assertSame('als_non_transport_medical_inventory.pdf', $source['file_name']);
        $this->assertSame('Date: / / M.B.F.D. A.L.S. NON-TRANSPORT UNIT WEEKLY INVENTORY Unit# Revised 1-2024', $source['printed_header']);

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

    public function test_differences_from_the_transport_sheet_are_kept_exactly_as_printed(): void
    {
        $sleeves = $this->item('Tyvek sleeves');
        $this->assertSame(['24', 24, '12', 12], [$sleeves->min_text, $sleeves->min_qty, $sleeves->flags['fill_if_text'], $sleeves->flags['fill_if_qty']]);
        foreach (['Red biohazard bags (LG)', 'Red biohazard bags ( Sm)'] as $printed) {
            $this->assertSame(['5', '3'], [$this->item($printed)->min_text, $this->item($printed)->flags['fill_if_text']], $printed);
        }
        $this->assertSame(['2', '1'], [$this->item('CURAPLEX BVMs W/ Manometer')->min_text, $this->item('CURAPLEX BVMs W/ Manometer')->flags['fill_if_text']]);
        $this->assertSame(['2', '1'], [$this->item('Sanizide 32oz with Sprayer')->min_text, $this->item('Sanizide 32oz with Sprayer')->flags['fill_if_text']]);
        $this->assertSame(['5', '3'], [$this->item('C-collar-adult -regular')->min_text, $this->item('C-collar-adult -regular')->flags['fill_if_text']]);

        $ezIo = $this->item('Adult EZ-IO (BLUE)');
        $this->assertSame(['2 IN BOX', 2, 'in box', '2'], [$ezIo->min_text, $ezIo->min_qty, $ezIo->min_unit, $ezIo->flags['fill_if_text']]);
        $steri = $this->item('Steri pads 2x2"');
        $this->assertSame(['5 each', 5, 'each', '5 each', 5], [$steri->min_text, $steri->min_qty, $steri->min_unit, $steri->flags['fill_if_text'], $steri->flags['fill_if_qty']]);
        $razors = $this->item('Disposable razors');
        $this->assertSame(['2 pack', 2, 'pack', '2 razors', 2], [$razors->min_text, $razors->min_qty, $razors->min_unit, $razors->flags['fill_if_text'], $razors->flags['fill_if_qty']]);
        $paper = $this->item('EKG paper lifepack 15');
        $this->assertSame(['2 rolls', 'rolls', '2 roll'], [$paper->min_text, $paper->min_unit, $paper->flags['fill_if_text']]);
        $lifepackRazors = $this->item('Disposable Razors');
        $this->assertSame(['2', '1'], [$lifepackRazors->min_text, $lifepackRazors->flags['fill_if_text']]);
        $this->assertNotSame($razors->item_key, $lifepackRazors->item_key, 'The same words in two places are two items.');
        $quick = $this->item('Quick-trach / adult');
        $this->assertSame(['1 in bag', 1, 'in bag', '1'], [$quick->min_text, $quick->min_qty, $quick->min_unit, $quick->flags['fill_if_text']]);
        $this->assertSame(['1 box', 'box', '1 box'], [$this->item('Ammonia inhalants / wipes')->min_text, $this->item('Ammonia inhalants / wipes')->min_unit, $this->item('Ammonia inhalants / wipes')->flags['fill_if_text']]);
        $this->assertSame('3 pair', $this->item('Shoe covers')->min_text);
        $this->assertSame(['2 pair', '1 pair'], [$this->item('Disposable Straps')->min_text, $this->item('Disposable Straps')->flags['fill_if_text']]);
        $this->assertSame(['2 bags', 'bags'], [$this->item('EKG electrodes')->min_text, $this->item('EKG electrodes')->min_unit]);
        $this->assertSame(['1 bag', 'bag'], [$this->item('EKG Electrodes')->min_text, $this->item('EKG Electrodes')->min_unit]);
    }

    public function test_the_five_yellow_bvm_rows_have_no_amount_and_are_flagged(): void
    {
        $printed = [
            'ET Tube extention(1) PLACED IN BVM BAG', 'BVM CO Capnoline (1) PLACED IN BVM BAG', 'i-gel 5 (1) PLACED IN BVM BAG',
            'i-gel 4 (1) PLACED IN BVM BAG', 'i-gel 3 (1) PLACED IN BVM BAG',
        ];
        foreach ($printed as $label) {
            $item = $this->item($label);
            $this->assertNull($item->min_text, $label);
            $this->assertNull($item->min_qty, $label);
            $this->assertSame(['1 in bag', 1], [$item->flags['fill_if_text'], $item->flags['fill_if_qty']], $label);
            $this->assertSame('Placed in BVM bag', $item->flags['location_text'], $label);
            $this->assertTrue($item->needs_review, $label);
            $this->assertStringContainsString('AMOUNT is blank', (string) $item->review_reason);
        }
        $this->assertSame('ET tube extension', $this->item('ET Tube extention(1) PLACED IN BVM BAG')->label);
        $this->assertCount(5, $this->items()->where('needs_review', true));
    }

    public function test_handtevy_rows_are_a_quantity_item_a_monthly_yes_no_check_and_in_handtevy_locations(): void
    {
        $kit = $this->item('HANDTEVY');
        $this->assertSame(['1', 1, '1', 1, 'quantity', 'handtevy-kit'], [$kit->min_text, $kit->min_qty, $kit->flags['fill_if_text'], $kit->flags['fill_if_qty'], $kit->response_type, $kit->catalogItem?->key]);

        $check = $this->item('HANDTEVY 1st Tuesday-Monthly Inventory-Inspection completed');
        $this->assertSame('yes_no', $check->response_type);
        $this->assertSame('monthly_first_tuesday', $check->flags['cadence']);
        $this->assertNull($check->min_text);
        $this->assertNull($check->min_qty);
        $this->assertArrayNotHasKey('fill_if_text', $check->flags);
        $this->assertNull($check->catalogItem);
        $this->assertCount(1, $this->items()->filter(static fn (SourceFormItem $i): bool => isset($i->flags['cadence'])), 'Only the first-Tuesday check has a cadence.');
        $this->assertSame(['nt-handtevy', 'nt-handtevy'], $this->items()->take(2)->pluck('section_key')->all());

        $inHandtevy = $this->items()->filter(static fn (SourceFormItem $i): bool => ($i->flags['location_text'] ?? null) === 'In Handtevy');
        $this->assertSame([
            'i-gel 2.5 -IN HANDTEVY-', 'i-gel 2.0 -IN HANDTEVY-', 'i-gel 1.5 -IN HANDTEVY-', 'i-gel 1.0 -IN HANDTEVY-',
            'CURAPLEX infant BVM -IN HANDTEVY-', 'Pedi Manual BP Cuff -IN HANDTEVY-', 'Infant Manual BP Cuff -IN HANDTEVY-',
        ], $inHandtevy->pluck('source_label')->all());
        $this->assertSame('i-gel 2.5', $this->item('i-gel 2.5 -IN HANDTEVY-')->label);
    }

    public function test_two_printed_rows_without_an_item_name_are_not_items_and_are_listed_for_a_person(): void
    {
        $review = implode("\n", $this->document()['source']['review_notes']);
        $this->assertStringContainsString('two table rows print AMOUNT 2 and Fill if 2 but have no item name', $review);
        $this->assertStringContainsString('grid rows 55 and 56', $review);
        $this->assertCount(246, $this->items());
        foreach ($this->items() as $item) {
            $this->assertNotSame('', trim($item->source_label));
            $this->assertNotSame('', trim($item->label));
        }
        // The two nameless rows sit between "22GA 1.5" ADMIN NEEDLES" (table row 54) and "Saline flush 10cc" (table row 57) in the left column.
        $this->assertSame(54, $this->item('22GA 1.5" ADMIN NEEDLES')->source_ref['row']);
        $this->assertSame(57, $this->item('Saline flush 10cc')->source_ref['row']);
        $this->assertSame('left', $this->item('Saline flush 10cc')->source_ref['column']);
    }

    public function test_variant_rows_become_one_item_per_printed_variant_with_the_rows_amount(): void
    {
        $bp = $this->items()->where('source_label', 'Manual B/P Cuffs Adult/ Pedi');
        $this->assertSame(['Adult', 'Pedi'], $bp->pluck('variant_label')->all());
        $this->assertSame(['1 each', '1 each'], $bp->pluck('min_text')->all());
        $this->assertSame(['manual-bp-cuff-adult', 'manual-bp-cuff-pedi'], $bp->map(fn ($i) => $i->catalogItem->key)->values()->all());

        $auto = $this->items()->where('source_label', 'Auto BP Cuffs XL, L, REG,, PEDI, INFANT');
        $this->assertSame(['XL', 'L', 'REG', 'PEDI', 'INFANT'], $auto->pluck('variant_label')->all());
        $this->assertSame(array_fill(0, 5, '1 each'), $auto->pluck('min_text')->all());
        $this->assertSame(array_fill(0, 5, 'each'), $auto->pluck('min_unit')->all());

        $syringe = $this->item('Syringe 10/12cc');
        $this->assertNull($syringe->variant_group, '"10/12cc" is one item (a 10cc or a 12cc).');
    }

    public function test_misspellings_stay_in_the_source_label_and_only_obvious_spelling_is_fixed_in_the_label(): void
    {
        $this->assertSame('Stethoscope', $this->item('Stethescope')->label);
        $this->assertSame('Asherman chest seals', $this->item('Ascherman chest seals')->label);
        $this->assertSame('Solu-Medrol', $this->item('Solu-Mederol')->label);
        $this->assertSame('Nasal MADDET', $this->item('Nasal MADDET')->label, 'A product spelling that is not obvious is not changed.');
        $this->assertSame('Combat App. Tourniquet CAT', $this->item('Combat App. Tourniquet CAT ( MCI BAG)')->label);
        $this->assertSame('MCI BAG', $this->item('Combat App. Tourniquet CAT ( MCI BAG)')->flags['qualifier_text']);
        $this->assertSame(['Connected to LP', 'Rear Pouch of LP'], [
            $this->item('SPO2-PULSEOX -sensor-Connected to LP')->flags['location_text'], $this->item('CO2-RAINBOW - sensor-Rear Pouch of LP')->flags['location_text'],
        ]);
        $this->assertSame('single use', $this->item('SPO2 PEDIATRIC sensor (single use)')->flags['qualifier_text']);
    }

    public function test_the_printed_note_under_the_bvm_heading_and_the_cont_heading_are_handled(): void
    {
        $this->assertSame(
            'BVMs come with Reg Adult & Child Masks / PEEP Valve / Filter / Tubing',
            $this->form()->notes['section_notes']['nt-airway-bvm-bag']['note'],
        );
        $sections = array_column($this->document()['sections'], 'source_label', 'key');
        $this->assertSame('TRAUMA SUPPLIES (continued as TRAUMA SUPPLIES CONT.)', $sections['nt-trauma-supplies']);
        $this->assertArrayNotHasKey('nt-trauma-supplies-cont', $sections);
        // The first and last rows of the merged section are the first row of TRAUMA SUPPLIES and the last of TRAUMA SUPPLIES CONT.
        $trauma = $this->items()->where('section_key', 'nt-trauma-supplies')->values();
        $this->assertSame('500 ml irrigation water', $trauma->first()->source_label);
        $this->assertSame('Ocular Eye Patch', $trauma->last()->source_label);
    }
}
