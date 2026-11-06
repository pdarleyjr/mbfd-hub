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
 * The paper "M.B.F.D. FIRE BOAT ALS INVENTORY" (Revised 7-2025) against the Fire Boat 6 ALS list. The sheet header says "Unit# FB# 4 & 5"
 * while its rows say FIREBOAT 6; that is recorded in review_notes and nothing refers to FB4 or FB5 as the unit.
 * SHEET is the transcription in sheet reading order: section key | printed text | label | AMOUNT | AMOUNT number | AMOUNT unit |
 * "Fill if <" | "Fill if <" number | response type | extras (loc, qual, band, cad, rev, vg, vl) | catalog ref. "~" is empty.
 * Every printed row has to be on the form, in this order, with the values as printed.
 */
final class FireBoat6AlsSheetTest extends TestCase
{
    use BuildsEmsWorld;
    use ImportsTrackedList;
    use RefreshDatabase;

    private const KEY = 'fire-boat-6-als';

    private const FILE = 'fire_boat_6_als_inventory.json';

    private const SHA256 = 'e052a18336ce340efb3c93f2bb9716ef7d9832306ba2549612aca56a6beb4182';

    private const ITEM_PREFIX = 'fb6.';

    /** The sections as the form shows them: key => [label, item count]. */
    private const SECTIONS = [
        'fb6-meds-red-bag' => ['Meds Red Bag', 27],
        'fb6-iv-needles' => ['I.V. / Needles', 32],
        'fb6-airway-green-bag-left' => ['Airway Green Bag (left column)', 17],
        'fb6-airway' => ['Airway', 12],
        'fb6-airway-green-bag-middle' => ['Airway Green Bag (middle column)', 51],
        'fb6-trauma-1-box' => ['Trauma #1 Box', 24],
        'fb6-trauma-2-box' => ['Trauma #2 Box', 14],
        'fb6-miscellaneous-on-boat' => ['Miscellaneous on Boat', 12],
        'fb6-life-pack-inventory' => ['Life Pack Inventory', 26],
    ];

    /** Catalog keys this list shares with the three older lists / keys only this and the other new lists use. */
    private const CATALOG_REUSED = 181;

    private const CATALOG_NEW = 34;

    private const SHEET = <<<'SHEET'
fb6-meds-red-bag|Adenocard 12mg|Adenocard 12 mg|2|2|~|2|2|quantity|band=Red|adenocard-12mg
fb6-meds-red-bag|Amiodarone|Amiodarone|4|4|~|4|4|quantity|band=Red|amiodarone
fb6-meds-red-bag|Atropine|Atropine|3|3|~|3|3|quantity|band=Red|atropine
fb6-meds-red-bag|Baby Aspirin|Baby Aspirin|1|1|~|1|1|quantity|band=Red|tv-baby-aspirin
fb6-meds-red-bag|Benadryl|Benadryl|2|2|~|2|2|quantity|band=Red|benadryl
fb6-meds-red-bag|Bicarb 8.4%|Bicarb 8.4%|2|2|~|2|2|quantity|band=Red|sodium-bicarb-8-4
fb6-meds-red-bag|Bicarb 4.2%|Bicarb 4.2%|1|1|~|1|1|quantity|band=Red|sodium-bicarb-4-2
fb6-meds-red-bag|Calcium chloride|Calcium chloride|1|1|~|1|1|quantity|band=Red|calcium-chloride
fb6-meds-red-bag|Dextrose 50%|Dextrose 50%|2|2|~|2|2|quantity|band=Red|dextrose-50
fb6-meds-red-bag|Dextrose 25%|Dextrose 25%|1|1|~|1|1|quantity|band=Red|dextrose-25
fb6-meds-red-bag|Dopamine drip|Dopamine drip|1|1|~|1|1|quantity|band=Red|dopamine
fb6-meds-red-bag|Duoneb|Duoneb|4|4|~|4|4|quantity|band=Red|duoneb-als-bag
fb6-meds-red-bag|Epi 1:10,000|Epi 1:10,000|5|5|~|5|5|quantity|band=Red|epi-1-10000
fb6-meds-red-bag|Epi 1:1,000|Epi 1:1,000|4|4|~|4|4|quantity|band=Red|epi-1-1000
fb6-meds-red-bag|Etomidate|Etomidate|1|1|~|1|1|quantity|band=Red|etomidate
fb6-meds-red-bag|Glucose oral gel|Glucose oral gel|2|2|~|2|2|quantity|band=Red|glucose-gel
fb6-meds-red-bag|Glucagon|Glucagon|1|1|~|1|1|quantity|band=Red|glucagon
fb6-meds-red-bag|Lasix|Lasix|2|2|~|2|2|quantity|band=Red|lasix
fb6-meds-red-bag|Lidocaine|Lidocaine|2|2|~|2|2|quantity|band=Red|lidocaine
fb6-meds-red-bag|Magnesium sulf.|Magnesium sulf.|1|1|~|1|1|quantity|band=Red|mag-sulfate
fb6-meds-red-bag|Narcan|Narcan|2|2|~|2|2|quantity|band=Red|narcan
fb6-meds-red-bag|Nitro Spray|Nitro Spray|1|1|~|1|1|quantity|band=Red|nitro-spray-als-bag
fb6-meds-red-bag|Racemic Epi|Racemic Epi|2|2|~|2|2|quantity|band=Red|racemic-epi
fb6-meds-red-bag|Solu-Mederol|Solu-Medrol|1|1|~|1|1|quantity|band=Red|solu-medrol
fb6-meds-red-bag|Tetracaine drops|Tetracaine drops|1|1|~|1|1|quantity|band=Red|tetracaine-drops
fb6-meds-red-bag|Zofran Oral|Zofran Oral|6|6|~|6|6|quantity|band=Red|zofran-oral
fb6-meds-red-bag|Zofran IV|Zofran IV|2|2|~|2|2|quantity|band=Red|zofran-iv
fb6-iv-needles|Safety Glasses (Clear)|Safety glasses (clear)|4|4|~|4|4|quantity|band=Red|safety-glasses
fb6-iv-needles|Mini drips|Mini drips|2|2|~|2|2|quantity|band=Red|mini-drip-set
fb6-iv-needles|Maxi drips|Maxi drips|2|2|~|2|2|quantity|band=Red|maxi-drip-set
fb6-iv-needles|Extension sets|Extension sets|5|5|~|5|5|quantity|band=Red|tv-extension-sets
fb6-iv-needles|3 way stopcock|3 way stopcock|1|1|~|1|1|quantity|band=Red|three-way-stopcock
fb6-iv-needles|Veniguards|Veniguards|5|5|~|5|5|quantity|band=Red|veniguard
fb6-iv-needles|Adult EZ-IO (BLUE)|Adult EZ-IO (BLUE)|2 IN BOX|2|in box|2|2|quantity|band=Red|tv-adult-ez-io-blue
fb6-iv-needles|Pedi EZ-IO (PINK)|Pedi EZ-IO (PINK)|1 IN BOX|1|in box|1|1|quantity|band=Red|tv-pedi-ez-io-pink
fb6-iv-needles|Lg Adult EZ-IO (YELLOW)|Lg Adult EZ-IO (YELLOW)|1 IN BOX|1|in box|1|1|quantity|band=Red|tv-large-adult-ez-io-yellow
fb6-iv-needles|IV cath 24ga|IV cath 24ga|4|4|~|4|4|quantity|band=Red|iv-catheter-24g
fb6-iv-needles|IV cath 22ga|IV cath 22ga|4|4|~|4|4|quantity|band=Red|iv-catheter-22g
fb6-iv-needles|IV cath 20ga|IV cath 20ga|4|4|~|4|4|quantity|band=Red|iv-catheter-20g
fb6-iv-needles|IV cath 18ga|IV cath 18ga|4|4|~|4|4|quantity|band=Red|iv-catheter-18g
fb6-iv-needles|IV cath 16ga|IV cath 16ga|2|2|~|2|2|quantity|band=Red|iv-catheter-16g
fb6-iv-needles|IV cath 14ga|IV cath 14ga|2|2|~|2|2|quantity|band=Red|iv-catheter-14g
fb6-iv-needles|EZ set 21ga ( Butterfly)|EZ set 21ga (Butterfly)|2|2|~|2|2|quantity|band=Red|ez-set-21ga-butterfly
fb6-iv-needles|18GA 1.5" FILTERED NEEDLES|18ga 1.5" filtered needles|2|2|~|2|2|quantity|band=Red|needle-18ga-1-5in-filtered
fb6-iv-needles|22GA 1.5" ADMIN NEEDLES|22ga 1.5" admin needles|2|2|~|2|2|quantity|band=Red|needle-22ga-1-5in-admin
fb6-iv-needles|Saline flush 10cc|Saline flush 10cc|5|5|~|5|5|quantity|band=Red|flush-10cc-als-bag
fb6-iv-needles|Syringe 1cc|Syringe 1cc|3|3|~|3|3|quantity|band=Red|syringe-1cc
fb6-iv-needles|Syringe 3cc|Syringe 3cc|3|3|~|3|3|quantity|band=Red|syringe-3cc
fb6-iv-needles|Syringe 10/12cc|Syringe 10/12cc|3|3|~|3|3|quantity|band=Red|syringe-10-12cc
fb6-iv-needles|Syringe 60cc|Syringe 60cc|2|2|~|2|2|quantity|band=Red|syringe-60cc
fb6-iv-needles|I.V. Tourniquet Latex Free|I.V. tourniquet latex free|5|5|~|5|5|quantity|band=Red|iv-tourniquet-latex-free
fb6-iv-needles|Betadine preps|Betadine preps|5|5|~|5|5|quantity|band=Red|betadine-prep
fb6-iv-needles|Alcohol preps|Alcohol preps|10|10|~|10|10|quantity|band=Red|alcohol-prep
fb6-iv-needles|N. Saline 1000cc|N. Saline 1000cc|4|4|~|4|4|quantity|band=Red|normal-saline-1000
fb6-iv-needles|D5w 50cc|D5w 50cc|1|1|~|1|1|quantity|band=Red|d5w-50cc
fb6-iv-needles|Nasal MADDET|Nasal MADDET|2|2|~|2|2|quantity|band=Red|nasal-maddet
fb6-iv-needles|Adult Manual BP Cuff|Adult Manual BP Cuff|1|1|~|1|1|quantity|band=Red|manual-bp-cuff-adult
fb6-iv-needles|Pedi Manual BP Cuff|Pedi Manual BP Cuff|1|1|~|1|1|quantity|band=Red|manual-bp-cuff-pedi
fb6-iv-needles|Convenience bags(VOMEX)|Convenience bags (VOMEX)|4|4|~|4|4|quantity|band=Red|tv-convenience-bags
fb6-airway-green-bag-left|OPA #0 40mm|OPA #0 40mm|2|2|~|2|2|quantity|band=Green|opa-40mm
fb6-airway-green-bag-left|OPA #1 50mm|OPA #1 50mm|2|2|~|2|2|quantity|band=Green|opa-50mm
fb6-airway-green-bag-left|OPA #2 60mm|OPA #2 60mm|2|2|~|2|2|quantity|band=Green|opa-60mm
fb6-airway-green-bag-left|OPA #3 80mm|OPA #3 80mm|2|2|~|2|2|quantity|band=Green|opa-80mm
fb6-airway-green-bag-left|OPA #4 90mm|OPA #4 90mm|2|2|~|2|2|quantity|band=Green|opa-90mm
fb6-airway-green-bag-left|OPA #5 100mm|OPA #5 100mm|2|2|~|2|2|quantity|band=Green|opa-100mm
fb6-airway-green-bag-left|OPA #6 110mm|OPA #6 110mm|2|2|~|2|2|quantity|band=Green|opa-110mm
fb6-airway-green-bag-left|NPA #30 FR|NPA #30 FR|1|1|~|1|1|quantity|band=Green|npa-30
fb6-airway-green-bag-left|NPA #28 FR|NPA #28 FR|1|1|~|1|1|quantity|band=Green|npa-28
fb6-airway-green-bag-left|NPA #26 FR|NPA #26 FR|1|1|~|1|1|quantity|band=Green|npa-26
fb6-airway-green-bag-left|NPA #24 FR|NPA #24 FR|1|1|~|1|1|quantity|band=Green|npa-24
fb6-airway-green-bag-left|NPA #22 FR|NPA #22 FR|1|1|~|1|1|quantity|band=Green|npa-22
fb6-airway-green-bag-left|NPA #20 FR|NPA #20 FR|1|1|~|1|1|quantity|band=Green|npa-20
fb6-airway-green-bag-left|NPA #18 FR|NPA #18 FR|1|1|~|1|1|quantity|band=Green|npa-18
fb6-airway-green-bag-left|NPA #16 FR|NPA #16 FR|1|1|~|1|1|quantity|band=Green|npa-16
fb6-airway-green-bag-left|NPA #14 FR|NPA #14 FR|1|1|~|1|1|quantity|band=Green|npa-14
fb6-airway-green-bag-left|NPA #12 FR|NPA #12 FR|1|1|~|1|1|quantity|band=Green|npa-12
fb6-airway|Bite sticks|Bite sticks|2|2|~|2|2|quantity|band=Green|bite-stick
fb6-airway|Non-rebreather/adult|Non-rebreather/adult|3|3|~|3|3|quantity|band=Green|non-rebreather-mask-adult
fb6-airway|Nasal cannula adult|Nasal cannula adult|3|3|~|3|3|quantity|band=Green|nasal-cannula-adult
fb6-airway|Nebulizer adult|Nebulizer adult|2|2|~|2|2|quantity|band=Green|nebulizer-mask-adult
fb6-airway|T-nebulizer adult|T-nebulizer adult|2|2|~|2|2|quantity|band=Green|t-nebulizer-adult
fb6-airway|Nasal Capnoline adult|Nasal Capnoline adult|2|2|~|2|2|quantity|band=Green|nasal-capnoline-adult
fb6-airway|Non-rebreather/pedi|Non-rebreather/pedi|2|2|~|2|2|quantity|band=Green|non-rebreather-pedi
fb6-airway|Nasal cannula pedi|Nasal cannula pedi|2|2|~|2|2|quantity|band=Green|nasal-cannula-pedi
fb6-airway|Nebulizer pedi|Nebulizer pedi|2|2|~|2|2|quantity|band=Green|nebulizer-mask-pedi
fb6-airway|Nasal Capnoline pedi|Nasal Capnoline pedi|2|2|~|2|2|quantity|band=Green|nasal-capnoline-pedi
fb6-airway|Non-rebreather/infant|Non-rebreather/infant|2|2|~|2|2|quantity|band=Green|non-rebreather-infant
fb6-airway|CPAP tube/mask set|CPAP tube/mask set|1|1|~|1|1|quantity|band=Green|tv-cpap-tube-mask-set
fb6-airway-green-bag-middle|CURAPLEX BVMs W/ Manometer|CURAPLEX BVMs W/ Manometer|2|2|~|1|1|quantity|band=Green|curaplex-bvm-manometer
fb6-airway-green-bag-middle|ET Tube extention(1) PLACED IN BVM BAG|ET tube extension|~|~|~|1 in bag|1|quantity|loc=Placed in BVM bag;band=Green;rev=1|et-tube-extension
fb6-airway-green-bag-middle|BVM CO Capnoline (1) PLACED IN BVM BAG|BVM CO Capnoline|~|~|~|1 in bag|1|quantity|loc=Placed in BVM bag;band=Green;rev=1|bvm-co-capnoline
fb6-airway-green-bag-middle|i-gel 5 (1) PLACED IN BVM BAG|i-gel 5|~|~|~|1 in bag|1|quantity|loc=Placed in BVM bag;band=Green;rev=1|igel-5
fb6-airway-green-bag-middle|i-gel 4 (1) PLACED IN BVM BAG|i-gel 4|~|~|~|1 in bag|1|quantity|loc=Placed in BVM bag;band=Green;rev=1|igel-4
fb6-airway-green-bag-middle|i-gel 3 (1) PLACED IN BVM BAG|i-gel 3|~|~|~|1 in bag|1|quantity|loc=Placed in BVM bag;band=Green;rev=1|igel-3
fb6-airway-green-bag-middle|i-gel 2.5|i-gel 2.5|1|1|~|1|1|quantity|band=Green|tv-i-gel-size-2-5
fb6-airway-green-bag-middle|i-gel 2.0|i-gel 2.0|1|1|~|1|1|quantity|band=Green|tv-i-gel-size-2-0
fb6-airway-green-bag-middle|i-gel 1.5|i-gel 1.5|1|1|~|1|1|quantity|band=Green|tv-i-gel-size-1-5
fb6-airway-green-bag-middle|i-gel 1.0|i-gel 1.0|1|1|~|1|1|quantity|band=Green|tv-i-gel-size-1-0
fb6-airway-green-bag-middle|Infant BVM|Infant BVM|1|1|~|1|1|quantity|band=Green|tv-bvm-infant
fb6-airway-green-bag-middle|ET tube 2.5 (cuffed)|ET tube 2.5 (cuffed)|1|1|~|1|1|quantity|band=Green|et-tube-2-5-cuffed
fb6-airway-green-bag-middle|ET tube 3.0 (cuffed)|ET tube 3.0 (cuffed)|1|1|~|1|1|quantity|band=Green|et-tube-3-0-cuffed
fb6-airway-green-bag-middle|ET tube 3.5 (cuffed)|ET tube 3.5 (cuffed)|1|1|~|1|1|quantity|band=Green|et-tube-3-5-cuffed
fb6-airway-green-bag-middle|ET tube 4.0 (cuffed)|ET tube 4.0 (cuffed)|1|1|~|1|1|quantity|band=Green|et-tube-4-0-cuffed
fb6-airway-green-bag-middle|ET tube 4.5 (cuffed)|ET tube 4.5 (cuffed)|1|1|~|1|1|quantity|band=Green|et-tube-4-5-cuffed
fb6-airway-green-bag-middle|ET tube 5.0 (cuffed)|ET tube 5.0 (cuffed)|1|1|~|1|1|quantity|band=Green|et-tube-5-0-cuffed
fb6-airway-green-bag-middle|ET tube 5.5 (cuffed)|ET tube 5.5 (cuffed)|1|1|~|1|1|quantity|band=Green|et-tube-5-5-cuffed
fb6-airway-green-bag-middle|ET tube 6.0 (cuffed)|ET tube 6.0 (cuffed)|1|1|~|1|1|quantity|band=Green|et-tube-6-0-cuffed
fb6-airway-green-bag-middle|ET tube 6.5 (cuffed)|ET tube 6.5 (cuffed)|1|1|~|1|1|quantity|band=Green|et-tube-6-5-cuffed
fb6-airway-green-bag-middle|ET tube 7.0 (cuffed)|ET tube 7.0 (cuffed)|2|2|~|2|2|quantity|band=Green|et-tube-7-0-cuffed
fb6-airway-green-bag-middle|ET tube 7.5 (cuffed)|ET tube 7.5 (cuffed)|2|2|~|2|2|quantity|band=Green|et-tube-7-5-cuffed
fb6-airway-green-bag-middle|ET tube 8.0 (cuffed)|ET tube 8.0 (cuffed)|1|1|~|1|1|quantity|band=Green|et-tube-8-0-cuffed
fb6-airway-green-bag-middle|ET tube 8.5 (cuffed)|ET tube 8.5 (cuffed)|1|1|~|1|1|quantity|band=Green|et-tube-8-5-cuffed
fb6-airway-green-bag-middle|ET tube 9.0 (cuffed)|ET tube 9.0 (cuffed)|1|1|~|1|1|quantity|band=Green|et-tube-9-0-cuffed
fb6-airway-green-bag-middle|Bougie 10Fr|Bougie 10Fr|1|1|~|1|1|quantity|band=Green|bougie-10fr
fb6-airway-green-bag-middle|Bougie 15Fr|Bougie 15Fr|1|1|~|1|1|quantity|band=Green|bougie-15fr
fb6-airway-green-bag-middle|Tube-lock / adult|Tube-lock / adult|2|2|~|2|2|quantity|band=Green|tube-holder-adult
fb6-airway-green-bag-middle|Tube-lock / pedi|Tube-lock / pedi|1|1|~|1|1|quantity|band=Green|tube-holder-pedi
fb6-airway-green-bag-middle|Suction cath 6Fr|Suction cath 6Fr|1|1|~|1|1|quantity|band=Green|suction-catheter-6fr
fb6-airway-green-bag-middle|Suction cath 8Fr|Suction cath 8Fr|1|1|~|1|1|quantity|band=Green|suction-catheter-8fr
fb6-airway-green-bag-middle|Suction cath 10Fr|Suction cath 10Fr|1|1|~|1|1|quantity|band=Green|suction-catheter-10fr
fb6-airway-green-bag-middle|Suction cath 12Fr|Suction cath 12Fr|1|1|~|1|1|quantity|band=Green|suction-catheter-12fr
fb6-airway-green-bag-middle|Suction cath 14Fr|Suction cath 14Fr|1|1|~|1|1|quantity|band=Green|suction-catheter-14fr
fb6-airway-green-bag-middle|Suction cath 16Fr|Suction cath 16Fr|1|1|~|1|1|quantity|band=Green|suction-catheter-16fr
fb6-airway-green-bag-middle|Suction cath 18Fr|Suction cath 18Fr|1|1|~|1|1|quantity|band=Green|suction-catheter-18fr
fb6-airway-green-bag-middle|Suction yankauer|Suction yankauer|2|2|~|2|2|quantity|band=Green|tv-suction-yankauer
fb6-airway-green-bag-middle|Meconium aspirators|Meconium aspirators|1|1|~|1|1|quantity|band=Green|meconium-aspirator
fb6-airway-green-bag-middle|Manual Suction in Airway-Bag|Manual Suction in Airway-Bag|1|1|~|1|1|quantity|band=Green|manual-suction-in-airway-bag
fb6-airway-green-bag-middle|Portable suction with container (FIREBOAT 6)|Portable suction with container|1|1|~|1|1|quantity|qual=FIREBOAT 6;band=Green|portable-suction-with-container
fb6-airway-green-bag-middle|Lubricating gel|Lubricating gel|5|5|~|5|5|quantity|band=Green|tv-lubricating-gel
fb6-airway-green-bag-middle|MAC #4|MAC #4|1|1|~|1|1|quantity|band=Green|mac-blade-4
fb6-airway-green-bag-middle|MAC #3|MAC #3|1|1|~|1|1|quantity|band=Green|mac-blade-3
fb6-airway-green-bag-middle|MAC #2|MAC #2|1|1|~|1|1|quantity|band=Green|mac-blade-2
fb6-airway-green-bag-middle|MILLER #3|MILLER #3|1|1|~|1|1|quantity|band=Green|miller-blade-3
fb6-airway-green-bag-middle|MILLER #2|MILLER #2|1|1|~|1|1|quantity|band=Green|miller-blade-2
fb6-airway-green-bag-middle|MILLER #1|MILLER #1|1|1|~|1|1|quantity|band=Green|miller-blade-1
fb6-airway-green-bag-middle|MILLER #0|MILLER #0|1|1|~|1|1|quantity|band=Green|miller-blade-0
fb6-airway-green-bag-middle|Quick-trach / adult|Quick-trach / adult|1 in bag|1|in bag|1|1|quantity|band=Green|adult-quick-trach
fb6-airway-green-bag-middle|Quick-trach / pedi|Quick-trach / pedi|1 in bag|1|in bag|1|1|quantity|band=Green|pedi-quick-trach
fb6-airway-green-bag-middle|Tracheostomy Mask Set|Tracheostomy Mask Set|1|1|~|1|1|quantity|band=Green|tracheostomy-mask-set
fb6-trauma-1-box|ARS Decomp 14ga 3"|ARS Decomp 14ga 3"|2|2|~|2|2|quantity|~|ars-decomp-14ga-3in
fb6-trauma-1-box|500 ml irrigation water|500 ml irrigation water|2|2|~|2|2|quantity|~|tv-500-ml-irrigation-water
fb6-trauma-1-box|Tourniquet CAT|Tourniquet CAT|6|6|~|6|6|quantity|~|cat-tourniquet
fb6-trauma-1-box|Steri pads 2x2"|Steri pads 2x2"|5 each|5|each|5 each|5|quantity|~|steri-pad-2x2
fb6-trauma-1-box|Steri pads 4x4"|Steri pads 4x4"|5 each|5|each|5 each|5|quantity|~|steri-pad-4x4
fb6-trauma-1-box|Armboards 9"|Armboards 9"|4|4|~|4|4|quantity|~|arm-board-9in
fb6-trauma-1-box|Armboards 18"|Armboards 18"|4|4|~|4|4|quantity|~|arm-board-18in
fb6-trauma-1-box|T-bandages|T-bandages|2|2|~|2|2|quantity|~|tv-t-bandages
fb6-trauma-1-box|Kling 2"|Kling 2"|4|4|~|4|4|quantity|~|kling-2in
fb6-trauma-1-box|Kling 4"|Kling 4"|4|4|~|4|4|quantity|~|kling-4in
fb6-trauma-1-box|Kling 6"|Kling 6"|4|4|~|4|4|quantity|~|kling-6in
fb6-trauma-1-box|1/2" tape|1/2" tape|1|1|~|1|1|quantity|~|tape-1-2in
fb6-trauma-1-box|1" tape|1" tape|2|2|~|2|2|quantity|~|tape-1in
fb6-trauma-1-box|3" tape|3" tape|2|2|~|2|2|quantity|~|tape-3in
fb6-trauma-1-box|4" ace bandage|4" ace bandage|5|5|~|5|5|quantity|~|ace-bandage-4in
fb6-trauma-1-box|6" ace bandage|6" ace bandage|5|5|~|5|5|quantity|~|ace-bandage-6in
fb6-trauma-1-box|Band aids 1 x 3" small|Band aids 1 x 3" small|10|10|~|10|10|quantity|~|tv-band-aids-1-x-3
fb6-trauma-1-box|Band aids 4 1/2" x 2" large|Band aids 4 1/2" x 2" large|10|10|~|10|10|quantity|~|tv-band-aids-4-1-2-x-2
fb6-trauma-1-box|Cold packs|Cold packs|5|5|~|5|5|quantity|~|tv-cold-packs
fb6-trauma-1-box|Ocular Eye Patch|Ocular Eye Patch|2|2|~|2|2|quantity|~|ocular-eye-patch
fb6-trauma-1-box|Ascherman chest seals|Asherman chest seals|2|2|~|2|2|quantity|~|asherman-chest-seal
fb6-trauma-1-box|Peroxide bottle|Peroxide bottle|1|1|~|1|1|quantity|~|tv-peroxide-bottle
fb6-trauma-1-box|Alcohol bottle|Alcohol bottle|1|1|~|1|1|quantity|~|tv-alcohol-bottle
fb6-trauma-1-box|Quick-clot|Quick-clot|2|2|~|2|2|quantity|~|quikclot-gauze
fb6-trauma-2-box|C-collar-adult -regular|C-collar-adult -regular|3|3|~|3|3|quantity|~|c-collar-adult-regular
fb6-trauma-2-box|C-collar/adult-no-neck|C-collar/adult-no-neck|3|3|~|3|3|quantity|~|tv-c-collar-adult-no-neck
fb6-trauma-2-box|C-collar-adjustable pedi|C-collar-adjustable pedi|2|2|~|2|2|quantity|~|tv-c-collar-adjustable-pedi
fb6-trauma-2-box|Burn sheets|Burn sheets|2|2|~|2|2|quantity|~|tv-burn-sheets
fb6-trauma-2-box|Burn Gel Face Mask|Burn Gel Face Mask|1|1|~|1|1|quantity|~|burn-gel-face-mask
fb6-trauma-2-box|Burn Gel 4 x 16 Dressing|Burn Gel 4 x 16 Dressing|2|2|~|2|2|quantity|~|burn-gel-4x16-dressing
fb6-trauma-2-box|Burn Gel 4x4 Dressing|Burn Gel 4x4 Dressing|2|2|~|2|2|quantity|~|tv-burn-gel-4-x-4-dressing
fb6-trauma-2-box|Burn Gel 3.5g Packets|Burn Gel 3.5g Packets|5|5|~|5|5|quantity|~|burn-gel-small-packet
fb6-trauma-2-box|TPOD Pelvic Device|TPOD Pelvic Device|1|1|~|1|1|quantity|~|tpod-pelvic-device
fb6-trauma-2-box|Trauma dressing 12x30"|Trauma dressing 12x30"|2|2|~|2|2|quantity|~|tv-trauma-dressing-12-x-30
fb6-trauma-2-box|Carry all|Carry all|1|1|~|1|1|quantity|~|carry-all
fb6-trauma-2-box|Foil rescue blanket|Foil rescue blanket|2|2|~|2|2|quantity|~|foil-rescue-blanket
fb6-trauma-2-box|Yellow foam blanket|Yellow foam blanket|3|3|~|3|3|quantity|~|tv-yellow-foam-blanket
fb6-trauma-2-box|OB kit|OB kit|1|1|~|1|1|quantity|~|tv-ob-kit
fb6-miscellaneous-on-boat|Vinegar 1 Gallon|Vinegar 1 Gallon|1|1|~|1|1|quantity|~|vinegar-1-gallon
fb6-miscellaneous-on-boat|Backboard with Disposable Straps|Backboard with Disposable Straps|1|1|~|1|1|quantity|~|backboard-with-disposable-straps
fb6-miscellaneous-on-boat|Disposable Straps|Disposable Straps|2 pair|2|pair|1 pair|1|quantity|~|tv-disposable-straps
fb6-miscellaneous-on-boat|Red biohazard bags (LG)|Red biohazard bags (LG)|3|3|~|3|3|quantity|~|red-biohazard-bags-lg
fb6-miscellaneous-on-boat|Red biohazard bags ( Sm)|Red biohazard bags (Sm)|3|3|~|3|3|quantity|~|red-biohazard-bags-sm
fb6-miscellaneous-on-boat|Latex gloves XL|Latex gloves XL|1 box|1|box|1 box|1|quantity|~|latex-gloves-xl
fb6-miscellaneous-on-boat|Latex gloves Large|Latex gloves Large|1 box|1|box|1 box|1|quantity|~|latex-gloves-large
fb6-miscellaneous-on-boat|Latex gloves medium|Latex gloves medium|1 box|1|box|1 box|1|quantity|~|latex-gloves-medium
fb6-miscellaneous-on-boat|Cida hand gel|Cida hand gel|2|2|~|2|2|quantity|~|tv-cida-hand-gel
fb6-miscellaneous-on-boat|Spare O2 Bottle|Spare O2 Bottle|1|1|~|1|1|quantity|~|spare-o2-bottle
fb6-miscellaneous-on-boat|Cardboard splints short (FIREBOAT 6)|Cardboard splints short|3|3|~|3|3|quantity|qual=FIREBOAT 6|tv-cardboard-splints-short
fb6-miscellaneous-on-boat|Cardboard splints long (FIREBOAT 6)|Cardboard splints long|3|3|~|3|3|quantity|qual=FIREBOAT 6|tv-cardboard-splints-long
fb6-life-pack-inventory|Glucometer strips|Glucometer strips|25|25|~|25|25|quantity|~|test-strips
fb6-life-pack-inventory|Gluco lancettes|Gluco lancets|25|25|~|25|25|quantity|~|lancets
fb6-life-pack-inventory|Disp. Thermometer Strips|Disp. Thermometer Strips|3|3|~|3|3|quantity|~|disp-thermometer-strips
fb6-life-pack-inventory|Pen Lights|Pen Lights|1|1|~|1|1|quantity|~|pen-light
fb6-life-pack-inventory|Modem|Modem|1|1|~|1|1|quantity|~|lifepak-modem
fb6-life-pack-inventory|Ammonia inhalants|Ammonia inhalants|1 box|1|box|1 box|1|quantity|~|ammonia-inhalants-als-bag
fb6-life-pack-inventory|SPO2-PULSEOX -sensor-Connected to LP|SPO2-PULSEOX sensor|1|1|~|1|1|quantity|loc=Connected to LP|lifepak-spo2-pulseox-sensor
fb6-life-pack-inventory|CO2-RAINBOW - sensor-Rear Pouch of LP|CO2-RAINBOW sensor|1|1|~|1|1|quantity|loc=Rear Pouch of LP|lifepak-co2-rainbow-sensor
fb6-life-pack-inventory|SPO2 PEDIATRIC sensor (single use)|SPO2 PEDIATRIC sensor|1|1|~|1|1|quantity|qual=single use|spo2-pediatric-sensor
fb6-life-pack-inventory|SPO2 INFANT sensor (single use)|SPO2 INFANT sensor|1|1|~|1|1|quantity|qual=single use|spo2-infant-sensor
fb6-life-pack-inventory|Combi Pads ADULT|Combi Pads ADULT|2|2|~|2|2|quantity|~|tv-combi-pads-adult
fb6-life-pack-inventory|Combi Pads PEDI|Combi Pads PEDI|1|1|~|1|1|quantity|~|tv-combi-pads-pedi
fb6-life-pack-inventory|EKG Electrodes|EKG Electrodes|1 bag|1|bag|1 bag|1|quantity|~|tv-ekg-electrodes
fb6-life-pack-inventory|EKG Lifepack 15 Paper|EKG Lifepack 15 Paper|1|1|~|1|1|quantity|~|tv-ekg-paper-lifepak-15
fb6-life-pack-inventory|Disposable Razors|Disposable Razors|2|2|~|1|1|quantity|~|tv-disposable-razors
fb6-life-pack-inventory|4 Lead Cable|4 Lead Cable|1|1|~|1|1|quantity|~|lifepak-4-lead-cable
fb6-life-pack-inventory|12 Lead Cable|12 Lead Cable|1|1|~|1|1|quantity|~|lifepak-12-lead-cable
fb6-life-pack-inventory|Therapy Cable- (COMBI Pads Cable)|Therapy cable (COMBI pads cable)|1|1|~|1|1|quantity|~|lifepak-therapy-cable
fb6-life-pack-inventory|Glucometer w/ Lancettes & strips|Glucometer w/ lancets & strips|1|1|~|1|1|quantity|~|glucometer-with-lancets-and-strips
fb6-life-pack-inventory|Auto BP Cuffs XL, L, REG,, PEDI, INFANT|Auto BP cuff XL|1 each|1|each|1 each|1|quantity|vg=auto-bp-cuffs;vl=XL|tv-auto-bp-cuff-adult-xl
fb6-life-pack-inventory|Auto BP Cuffs XL, L, REG,, PEDI, INFANT|Auto BP cuff L|1 each|1|each|1 each|1|quantity|vg=auto-bp-cuffs;vl=L|tv-auto-bp-cuff-adult-lg
fb6-life-pack-inventory|Auto BP Cuffs XL, L, REG,, PEDI, INFANT|Auto BP cuff REG|1 each|1|each|1 each|1|quantity|vg=auto-bp-cuffs;vl=REG|tv-auto-bp-cuff-adult-reg
fb6-life-pack-inventory|Auto BP Cuffs XL, L, REG,, PEDI, INFANT|Auto BP cuff PEDI|1 each|1|each|1 each|1|quantity|vg=auto-bp-cuffs;vl=PEDI|tv-auto-bp-cuff-pedi
fb6-life-pack-inventory|Auto BP Cuffs XL, L, REG,, PEDI, INFANT|Auto BP cuff INFANT|1 each|1|each|1 each|1|quantity|vg=auto-bp-cuffs;vl=INFANT|tv-auto-bp-cuff-infant
fb6-life-pack-inventory|Stethoscope|Stethoscope|1|1|~|1|1|quantity|~|stethoscope
fb6-life-pack-inventory|Ammonia inhalants / wipes|Ammonia inhalants / wipes|1 box|1|box|1 box|1|quantity|~|ammonia-inhalants-wipes
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
        $this->assertSame('M.B.F.D. FIRE BOAT ALS INVENTORY', $source['title']);
        $this->assertSame('Revised 7-2025', $source['version_label']);
        $this->assertSame('fire_boat_6_medical_inventory.pdf', $source['file_name']);
        $this->assertSame('Date: / / M.B.F.D. FIRE BOAT ALS INVENTORY Unit# FB# 4 & 5 Revised 7-2025', $source['printed_header']);

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

    public function test_the_header_conflict_is_recorded_and_the_data_keeps_the_boat_6_key(): void
    {
        $source = $this->document()['source'];
        $this->assertSame('Date: / / M.B.F.D. FIRE BOAT ALS INVENTORY Unit# FB# 4 & 5 Revised 7-2025', $source['printed_header']);
        $this->assertSame('fire-boat-6-als', $source['key']);
        $this->assertStringContainsString('Unit# FB# 4 & 5', $source['review_notes'][0]);
        $this->assertStringContainsString('FIREBOAT 6', $source['review_notes'][0]);
        $this->assertStringContainsString('no data was changed', $source['review_notes'][0]);

        $form = $this->form();
        $this->assertStringContainsString('FB# 4 & 5', $form->notes['printed_header']);
        $this->assertStringContainsString('FIREBOAT 6', $form->notes['review_notes'][0]);
    }

    public function test_nothing_in_the_list_refers_to_fb4_or_fb5_as_the_unit(): void
    {
        $document = $this->document();
        $everything = json_encode(['title' => $document['source']['title'], 'key' => $document['source']['key'], 'sections' => $document['sections']]);
        $this->assertDoesNotMatchRegularExpression('/\bFB\s*#?\s*[45]\b/i', (string) $everything);
        $this->assertDoesNotMatchRegularExpression('/fire\s*boat\s*[45]\b/i', (string) $everything);
        $notes = implode(' ', $document['source']['notes']);
        $this->assertDoesNotMatchRegularExpression('/\bFB\s*#?\s*[45]\b/i', $notes);
    }

    public function test_rows_that_say_fireboat_6_carry_it_as_a_qualifier_not_in_the_label(): void
    {
        $rows = [
            'Portable suction with container (FIREBOAT 6)' => 'Portable suction with container',
            'Cardboard splints short (FIREBOAT 6)' => 'Cardboard splints short',
            'Cardboard splints long (FIREBOAT 6)' => 'Cardboard splints long',
        ];
        foreach ($rows as $printed => $label) {
            $item = $this->item($printed);
            $this->assertSame($label, $item->label);
            $this->assertSame('FIREBOAT 6', $item->flags['qualifier_text']);
        }
        $this->assertCount(3, $this->items()->filter(static fn (SourceFormItem $i): bool => ($i->flags['qualifier_text'] ?? null) === 'FIREBOAT 6'));
        $this->assertSame(['single use', 'single use'], $this->items()->filter(static fn (SourceFormItem $i): bool => ($i->flags['qualifier_text'] ?? null) === 'single use')->pluck('flags.qualifier_text')->values()->all());
        $this->assertSame(['1', '1'], [$this->item('Portable suction with container (FIREBOAT 6)')->min_text, $this->item('Portable suction with container (FIREBOAT 6)')->flags['fill_if_text']]);
        $this->assertSame(['3', '3'], [$this->item('Cardboard splints short (FIREBOAT 6)')->min_text, $this->item('Cardboard splints short (FIREBOAT 6)')->flags['fill_if_text']]);
    }

    public function test_printed_color_groups_and_compartments_are_kept(): void
    {
        $this->assertSame(array_keys(self::SECTIONS), [
            'fb6-meds-red-bag', 'fb6-iv-needles', 'fb6-airway-green-bag-left', 'fb6-airway', 'fb6-airway-green-bag-middle',
            'fb6-trauma-1-box', 'fb6-trauma-2-box', 'fb6-miscellaneous-on-boat', 'fb6-life-pack-inventory',
        ]);
        $sections = array_column($this->document()['sections'], 'source_label', 'key');
        $this->assertSame('MEDS RED BAG', $sections['fb6-meds-red-bag']);
        $this->assertSame('TRAUMA #1 BOX (continued as TRAUMA #1 BOX ( CONT ))', $sections['fb6-trauma-1-box']);
        $this->assertSame('MISCELLANEOUS ON BOAT', $sections['fb6-miscellaneous-on-boat']);
        $this->assertSame('LIFE PACK INVENTORY', $sections['fb6-life-pack-inventory']);

        $bands = $this->items()->groupBy('section_key')->map(fn ($group) => $group->pluck('flags')->pluck('color_band')->unique()->values()->all())->all();
        $this->assertSame([
            'fb6-meds-red-bag' => ['Red'], 'fb6-iv-needles' => ['Red'], 'fb6-airway-green-bag-left' => ['Green'], 'fb6-airway' => ['Green'],
            'fb6-airway-green-bag-middle' => ['Green'], 'fb6-trauma-1-box' => [null], 'fb6-trauma-2-box' => [null],
            'fb6-miscellaneous-on-boat' => [null], 'fb6-life-pack-inventory' => [null],
        ], $bands);
        $this->assertSame(
            'BVMs come with Reg Adult & Child Masks / PEEP Valve / Filter / Tubing',
            $this->form()->notes['section_notes']['fb6-airway-green-bag-middle']['note'],
        );
    }

    public function test_only_medical_rows_are_on_the_list_no_boat_machinery_electronics_scba_or_fire_gear(): void
    {
        $pattern = '/scba|raymarine|radar|flir|sonar|siren|nozzle|hose|anchor|bumper|shoreline|pfd|extinguisher|generator|engine|fuel|dewatering|foam line|snorkel|scuba|headset|intercom|radio/i';
        foreach ($this->items() as $item) {
            $this->assertDoesNotMatchRegularExpression($pattern, $item->source_label, $item->source_label);
            $this->assertDoesNotMatchRegularExpression($pattern, $item->label, $item->label);
        }
        $this->assertNull($this->items()->firstWhere('source_label', 'HANDTEVY'), 'The boat sheet prints no Handtevy row.');
        $this->assertCount(0, $this->items()->filter(static fn (SourceFormItem $i): bool => isset($i->flags['location_text']) && $i->flags['location_text'] === 'In Handtevy'));
        $this->assertCount(0, $this->items()->filter(static fn (SourceFormItem $i): bool => $i->response_type === 'yes_no'));
    }

    public function test_the_boats_own_numbers_are_kept_as_printed(): void
    {
        $this->assertSame(['6', '6'], [$this->item('Tourniquet CAT')->min_text, $this->item('Tourniquet CAT')->flags['fill_if_text']]);
        $this->assertSame(['2', '2'], [$this->item('1" tape')->min_text, $this->item('1" tape')->flags['fill_if_text']]);
        $this->assertSame(['4', '4'], [$this->item('N. Saline 1000cc')->min_text, $this->item('N. Saline 1000cc')->flags['fill_if_text']]);
        $this->assertSame(['1', '1'], [$this->item('Spare O2 Bottle')->min_text, $this->item('Spare O2 Bottle')->flags['fill_if_text']]);
        $this->assertSame(['2 pair', '1 pair'], [$this->item('Disposable Straps')->min_text, $this->item('Disposable Straps')->flags['fill_if_text']]);
        $this->assertSame(['3', '3'], [$this->item('Red biohazard bags (LG)')->min_text, $this->item('Red biohazard bags (LG)')->flags['fill_if_text']]);
        $this->assertSame(['2', '1'], [$this->item('CURAPLEX BVMs W/ Manometer')->min_text, $this->item('CURAPLEX BVMs W/ Manometer')->flags['fill_if_text']]);
        $this->assertSame(['1 in bag', '1'], [$this->item('Quick-trach / pedi')->min_text, $this->item('Quick-trach / pedi')->flags['fill_if_text']]);
        $this->assertSame(['2 IN BOX', '2'], [$this->item('Adult EZ-IO (BLUE)')->min_text, $this->item('Adult EZ-IO (BLUE)')->flags['fill_if_text']]);
        $this->assertSame('Latex gloves XL|Latex gloves Large|Latex gloves medium', $this->items()->filter(static fn (SourceFormItem $i): bool => str_starts_with($i->source_label, 'Latex gloves'))->pluck('source_label')->implode('|'));
    }

    public function test_the_five_yellow_bvm_rows_have_no_amount_and_are_flagged(): void
    {
        foreach (['ET Tube extention(1) PLACED IN BVM BAG', 'BVM CO Capnoline (1) PLACED IN BVM BAG', 'i-gel 5 (1) PLACED IN BVM BAG', 'i-gel 4 (1) PLACED IN BVM BAG', 'i-gel 3 (1) PLACED IN BVM BAG'] as $label) {
            $item = $this->item($label);
            $this->assertNull($item->min_text, $label);
            $this->assertSame(['1 in bag', 1], [$item->flags['fill_if_text'], $item->flags['fill_if_qty']], $label);
            $this->assertSame('Placed in BVM bag', $item->flags['location_text']);
            $this->assertTrue($item->needs_review);
        }
        $this->assertCount(5, $this->items()->where('needs_review', true));
    }

    public function test_the_boat_sheet_covers_only_part_of_the_daily_checkout_medical_group(): void
    {
        // FIRE BOAT 6 Daily (8/2026 v4) medical group: Airway Bag, Med Box/Boo Boo-Bag, LifePak 15, Spare O2 Bottle, LSU Suction,
        // Gloves SM-XXL (1BXea), Sleeves (1BX), Backboards (2). The ALS sheet lists contents, not the bags themselves.
        $labels = $this->items()->pluck('source_label');
        $this->assertContains('Spare O2 Bottle', $labels->all());
        $this->assertContains('Backboard with Disposable Straps', $labels->all());
        $this->assertSame(['1', '1'], [$this->item('Backboard with Disposable Straps')->min_text, $this->item('Backboard with Disposable Straps')->flags['fill_if_text']], 'The checkout says Backboards (2); the ALS sheet says 1.');
        $this->assertSame(3, $labels->filter(static fn (string $l): bool => str_starts_with($l, 'Latex gloves'))->count(), 'No small or XXL gloves on the boat ALS sheet.');
        $this->assertCount(0, $labels->filter(static fn (string $l): bool => stripos($l, 'sleeves') !== false), 'No sleeves on the boat ALS sheet.');
        $this->assertCount(0, $labels->filter(static fn (string $l): bool => stripos($l, 'LSU') !== false || stripos($l, 'boo boo') !== false));
    }
}
