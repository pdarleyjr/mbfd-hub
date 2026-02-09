<?php

namespace App\Config;

/**
 * Station Supply List - Single Source of Truth
 * Based on STATION SUPPLY LIST 12-2025.xlsx
 * 
 * This configuration defines all available station supplies organized by category.
 * Used by both the React form (frontend) and Filament admin panel (backend) to ensure consistency.
 */
class StationSupplyList
{
    /**
     * Get all station supplies organized by category
     * 
     * @return array
     */
    public static function getSupplies(): array
    {
        return [
            // Garbage bags & Paper goods
            'REN22505-CA' => [
                'name' => 'RENOWN FITS 20-30 GAL. 30" x 36" .5 MIL BLK CAN LINER (25 small bags/ROLL, 10-ROLLS P/CASE)',
                'category' => 'Garbage bags & Paper goods',
                'max' => 2,
            ],
            'REN66024-CA' => [
                'name' => 'RENOWN 60 GAL. 2 MIL 38" x 58" BLK CAN LINER (10 large bags/ROLL, 10-ROLL P/CASE)',
                'category' => 'Garbage bags & Paper goods',
                'max' => 2,
            ],
            'HOS537-10' => [
                'name' => 'HOSPECO 10 LBS. P/BOX WHITE TERRY TOWEL RAGS',
                'category' => 'Garbage bags & Paper goods',
                'max' => 1,
            ],
            'REN06002-WB' => [
                'name' => 'RENOWN WHITE MULTIFOLD PAPER TOWELS (250 SHEETS P/PACK, 16 PACKS P/CASE)',
                'category' => 'Garbage bags & Paper goods',
                'max' => 4,
            ],
            'REN06004-WB' => [
                'name' => 'RENOWN NATURAL HARDWOUND PAPER TOWELS (800 FT. P/ROLL, 6 ROLLS P/CASE)',
                'category' => 'Garbage bags & Paper goods',
                'max' => 4,
            ],
            '309116312' => [
                'name' => 'RENOWN SINGLE ROLL 2-PLY 4" x 3.75" TOILET PAPER (500 SHEETS P/ROLL, 96 ROLLS P/CASE)',
                'category' => 'Garbage bags & Paper goods',
                'max' => 2,
            ],
            'GPT2717714' => [
                'name' => 'SPARKLE 2-PLY WHITE PERFORATED PAPER TOWEL ROLL (15 ROLLS P/CASE)',
                'category' => 'Garbage bags & Paper goods',
                'max' => 2,
            ],
            'SPA6075' => [
                'name' => 'STERIPHENE 20OZ. AEROSOL CAN DISINFECTANT DEODORANT, SPRING BREEZE SCENT (12 P/PACK)',
                'category' => 'Garbage bags & Paper goods',
                'max' => 1,
            ],

            // Floors
            '326445104' => [
                'name' => 'RENOWN GENERAL PURPOSE FLOOR CLEANER 1 GAL. (4 P/CASE)',
                'category' => 'Floors',
                'max' => 2,
            ],
            'REN051' => [
                'name' => 'RENOWN CLEANER DEGREASER 1 GAL. (4 P/CASE)',
                'category' => 'Floors',
                'max' => 4,
            ],
            'PGC02621' => [
                'name' => 'MR. CLEAN 1 GAL. OPEN LOOP FLOOR CLEANER, LEMON SCENT (3 P/CASE)',
                'category' => 'Floors',
                'max' => 2,
            ],
            'REN03953' => [
                'name' => 'CARLISLE 10" BLUE POLYPROPYLENE DUAL SURFACE SCRUB BRUSH',
                'category' => 'Floors',
                'max' => 3,
            ],
            'REN03964' => [
                'name' => 'RENOWN 8" GREEN SWIVEL SCRUB BRUSH',
                'category' => 'Floors',
                'max' => 3,
            ],
            '312441367' => [
                'name' => '10" FEATHER TIP BI-LEVEL VEHICLE BRUSH',
                'category' => 'Floors',
                'max' => 3,
            ],
            '322775366' => [
                'name' => 'WOOD METAL THREADED BROOM HANDLE 60" x 15/16"',
                'category' => 'Floors',
                'max' => 4,
            ],
            'CSM36622200' => [
                'name' => 'CARLISLE 22" SOFT BLACK FOAM RUBBER FLOOR SQUEEGEE',
                'category' => 'Floors',
                'max' => 4,
            ],
            '322775340' => [
                'name' => '15/16" x 54" YELLOW FIBERGLASS HANDLE',
                'category' => 'Floors',
                'max' => 4,
            ],
            'REN03984' => [
                'name' => 'RENOWN PALYRA GARAGE SWEEP',
                'category' => 'Floors',
                'max' => 2,
            ],
            'REN05125' => [
                'name' => 'RENOWN 12" BLACK L-GRIP PLASTIC UPRIGHT LOBBY DUST PAN',
                'category' => 'Floors',
                'max' => 1,
            ],
            'REN03997' => [
                'name' => 'RENOWN 55" CORN HOUSEKEEPING BROOM',
                'category' => 'Floors',
                'max' => 2,
            ],
            '321381641' => [
                'name' => 'WOODEN MOP HANDLE WITH PLASTIC JAWS 60" x 15/16" (2-PACK)',
                'category' => 'Floors',
                'max' => 1,
            ],
            '318353671' => [
                'name' => 'BLENDED COTTON SYNTHETIC REPLACEMENT STRING MOP MEDIUM LOOP WIDE BAND MOP HEAD',
                'category' => 'Floors',
                'max' => 12,
            ],
            'RCP758088YL' => [
                'name' => 'RUBBERMAID WAVE BRAKE 35QT. YELLOW SIDE-PRESS COMBO MOP BUCKET AND WRINGER SYSTEM',
                'category' => 'Floors',
                'max' => 1,
            ],

            // Laundry
            'KIK55GB' => [
                'name' => 'PURE BRIGHT 128OZ. 6% EPA GERMICIDAL BLEACH (6 P/CASE)',
                'category' => 'Laundry',
                'max' => 2,
            ],
            'PGC03259' => [
                'name' => 'FEBREZE 32OZ. FABRIC REFRESHER SPRAY, ORIGINAL SCENT (8 P/CASE)',
                'category' => 'Laundry',
                'max' => 1,
            ],
            'SPA7003-04' => [
                'name' => 'SPARTAN CLOTHESLINE FRESH 1 GAL. LAUNDRY DETERGENT (4 P/CASE)',
                'category' => 'Laundry',
                'max' => 3,
            ],

            // Bathroom & Cleaners
            'PGC58775' => [
                'name' => 'SPIC AND SPAN 32OZ. DISINFECTING ALL PURPOSE AND GLASS CLEANER (8 P/CASE)',
                'category' => 'Bathroom & Cleaners',
                'max' => 1,
            ],
            'SMP13005' => [
                'name' => 'SIMPLE GREEN ALL PURPOSE CONCENTRATED CLEANER (6/CASE)',
                'category' => 'Bathroom & Cleaners',
                'max' => 1,
            ],
            'CLO00031' => [
                'name' => 'CLOROX 24OZ. TOILET BOWL CLEANER (12 P/CASE)',
                'category' => 'Bathroom & Cleaners',
                'max' => 1,
            ],
            'PGC22569' => [
                'name' => 'COMET 32OZ. DISINFECTING SANITIZING BATHROOM CLEANER SPRAY (8 P/CASE)',
                'category' => 'Bathroom & Cleaners',
                'max' => 1,
            ],
            'YYYSH-CLN' => [
                'name' => 'SPLASH HOG CLEAN URINAL SCREEN (6/BOX)',
                'category' => 'Bathroom & Cleaners',
                'max' => 2,
            ],
            'RCP295700BG' => [
                'name' => 'RUBBERMAID 10.25 GAL. BEIGE RECTANGULAR TRASH CAN',
                'category' => 'Bathroom & Cleaners',
                'max' => 2,
            ],
            'IMP9200-90' => [
                'name' => 'IMPACT PRODUCTS - BLACK INDUSTRIAL PLUNGER',
                'category' => 'Bathroom & Cleaners',
                'max' => 1,
            ],
            '311535529' => [
                'name' => 'RENOWN WHITE TOILET BRUSH, MEDIUM (6 P/PACK)',
                'category' => 'Bathroom & Cleaners',
                'max' => 1,
            ],
            'IMP5032HG-90' => [
                'name' => 'IMPACT PRODUCTS 32OZ. PLASTIC SPRAY BOTTLE WITH HANDI-HOLD & GRADUATIONS',
                'category' => 'Bathroom & Cleaners',
                'max' => 4,
            ],
            '313640723' => [
                'name' => 'IMPACT 10" RETAIL TRIGGER SPRAY',
                'category' => 'Bathroom & Cleaners',
                'max' => 4,
            ],
            '202751159' => [
                'name' => 'ZEP 1 GAL. ANTIBACTERIAL DISINFECTANT CLEANER WITH LEMON (4 P/CASE)',
                'category' => 'Bathroom & Cleaners',
                'max' => 1,
            ],

            // Kitchen
            'REN02118' => [
                'name' => 'RENOWN SCRUB SPONGE, MEDIUM (20 P/CASE)',
                'category' => 'Kitchen',
                'max' => 2,
            ],
            'CLO88320' => [
                'name' => 'S.O.S STEEL WOOL SOAP PADS (15 P/BOX)',
                'category' => 'Kitchen',
                'max' => 2,
            ],
            'REN05001-AM' => [
                'name' => 'RENOWN OVEN AND GRILL CLEANER (12 P/CASE)',
                'category' => 'Kitchen',
                'max' => 1,
            ],
        ];
    }

    /**
     * Get supply name by item ID
     * 
     * @param string $itemId
     * @return string|null
     */
    public static function getSupplyName(string $itemId): ?string
    {
        $supplies = self::getSupplies();
        return $supplies[$itemId]['name'] ?? null;
    }

    /**
     * Get supply data by item ID
     * 
     * @param string $itemId
     * @return array|null
     */
    public static function getSupply(string $itemId): ?array
    {
        $supplies = self::getSupplies();
        return $supplies[$itemId] ?? null;
    }

    /**
     * Get supplies organized by category
     * 
     * @return array
     */
    public static function getSuppliesByCategory(): array
    {
        $supplies = self::getSupplies();
        $categorized = [];

        foreach ($supplies as $itemId => $supply) {
            $category = $supply['category'];
            if (!isset($categorized[$category])) {
                $categorized[$category] = [];
            }
            $categorized[$category][$itemId] = $supply;
        }

        return $categorized;
    }
}
