<?php

declare(strict_types=1);

namespace App\Services\SupportTriage;

/**
 * The first-install equipment catalog (owner rules, 2026-11-03), built from the item names on the seven Daily
 * Checkout checklists plus the everyday supply and station-facility wording members use. Administrators own the
 * catalog after the first seed: EquipmentRuleService::seedDefaults() only adds rows that are missing and never
 * changes a row an administrator has edited.
 *
 *  LOW    routine, inexpensive: absorbents and kitty litter, hand tools and hardware, consumables, cleaning supplies,
 *         interchangeable batteries (DeWalt and the like), forms and reference books.
 *  MEDIUM expensive or specialized: power tools, hydraulic and cutting tools, hose and nozzles, gas meters, radios and
 *         cameras, Lifepaks and other costly Rescue medical equipment, SCBA cylinders and parts, vehicle equipment.
 *  HIGH   complete SCBA units and facepieces, hoods, helmets, bunker coats and pants and other structural PPE.
 *
 * An alias ending in `*` matches any item name containing that phrase, the last word as a word prefix ("battery*").
 * Anything not matched stays unclassified (MEDIUM, flagged for staff) until an administrator classifies it.
 */
final class DefaultEquipmentCatalog
{
    /**
     * @return list<array{name: string, aliases: list<string>, category: string, level: string, handler: string, apparatus_scope: string|null, member_response: string|null, is_ppe: bool, is_critical: bool, is_expensive: bool, contact_300: string}>
     */
    public static function rows(): array
    {
        $rows = [];
        foreach ([...self::low(), ...self::medium(), ...self::high()] as [$name, $level, $category, $aliases]) {
            $rows[] = self::row($name, $level, $category, $aliases);
        }

        return $rows;
    }

    /**
     * @param  list<string>  $aliases
     * @return array{name: string, aliases: list<string>, category: string, level: string, handler: string, apparatus_scope: string|null, member_response: string|null, is_ppe: bool, is_critical: bool, is_expensive: bool, contact_300: string}
     */
    private static function row(string $name, string $level, string $category, array $aliases): array
    {
        return [
            'name' => $name,
            'aliases' => $aliases,
            'category' => $category,
            'level' => $level,
            'handler' => EquipmentRuleService::CATEGORY_HANDLERS[$category] ?? 'support_services',
            'apparatus_scope' => null,
            'member_response' => null,
            'is_ppe' => in_array($category, ['ppe', 'scba_unit', 'scba_facepiece'], true),
            'is_critical' => $level === EquipmentClassification::HIGH,
            'is_expensive' => $level !== EquipmentClassification::LOW,
            'contact_300' => match ($level) {
                EquipmentClassification::HIGH => 'always',
                EquipmentClassification::MEDIUM => 'urgent',
                default => 'never',
            },
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: list<string>}> */
    private static function low(): array
    {
        $l = EquipmentClassification::LOW;

        return [
            // Absorbents, cleaning and station supplies.
            ['Absorbents and kitty litter', $l, 'consumable', ['Kitty Litter*', 'Cat Litter*', 'Absorbent*', 'Speedy Dri*', 'Oil Dry*']],
            ['Cleaning supplies', $l, 'consumable', [
                'Bleach*', 'Vinegar*', 'Peroxide*', 'Alcohol*', 'Sanizide*', 'SpeedyCleanz*', 'Disinfectant*', 'Cleaner*', 'Cleaning suppl*', 'Detergent*',
                'Soap*', 'Sponge*', 'Squeegee*', 'Mop*', 'Drying Shammy*', 'Green Bucket*', 'Wipes*', 'Sterile Water*', 'Hand sanitizer*',
            ]],
            ['Station supplies', $l, 'consumable', [
                'Paper towel*', 'Toilet paper*', 'Toilet tissue*', 'Trash bag*', 'Garbage bag*', 'Can liner*', 'Coffee*', 'Paper cup*', 'Plastic cup*',
                'Light bulb*', 'Bulb*', 'Air freshener*', 'Printer paper*', 'Copy paper*', 'Toner*', 'Ink cartridge*', 'Water Cooler*',
            ]],
            ['Batteries (interchangeable)', $l, 'battery', ['Battery*', 'DeWalt Batter*', 'M18 Batter*', 'Milwaukee Batter*', 'AA Batter*', 'AAA Batter*', 'Spare Batter*']],
            ['Gloves, sleeves and disposable protection', $l, 'consumable', [
                'Glove*', 'Sleeves*', 'Tyvek*', 'Tyvex*', 'N-95*', 'Safety Glasses*', 'Safety vest*', 'Spare Sheet*', 'Emergency Blanket*', 'Yellow Blanket*', 'Blanket*',
            ]],
            ['Tarps, covers, cones and flares', $l, 'consumable', ['Tarp*', 'Salvage Cover*', 'Cone*', 'Flare*', 'Triangle*', 'Triangular Marker*', 'Road Hazards']],
            ['Tape, tags and signs', $l, 'consumable', [
                'Duct Tape*', 'Safety Tape*', 'Caution Tape*', 'Yellow Safety Tape*', 'Out of Service Tag*', 'Hyd Out of Service Tag*', 'Gaskets/Out of Service Tags',
                'PAR Tag*', 'No Smoking Sign*',
            ]],
            ['Forms, manuals and maps', $l, 'other', [
                'Fire Watch Form*', 'Fire Violation*', 'Clipboard*', 'Rope Log*', 'Rope Equip. Log Book', 'Vehicle Log Book', 'Tool Ops Manual', 'Fog Manual', 'Pierce Manual',
                'Terrorism Handbook', 'NIOSH Chemical Hazards', 'ERG', 'Transport Fee Chart', 'Pre-Fire Plans', 'City/Hydrant Map', 'FB Site Map', 'FB Sitemap', 'Passports',
            ]],
            ['Oils, lubricants and gas cans', $l, 'consumable', ['Bar Chain Oil', 'Lubricant and Silicone', 'Marine Lubricant', 'Gas Can*', 'Gas Card*', 'Foam', '5 Gallon Foam']],
            // Hand tools and hardware.
            ['Pike poles', $l, 'hand_tool', ['Pike Pole*']],
            ['Axes', $l, 'hand_tool', ['Axe', 'Flat Head Axe', 'Flat Headed Axe', 'Pick Headed Axe', 'Pick Head Axe']],
            ['Forcible entry hand tools', $l, 'hand_tool', [
                'Halligan*', 'Irons', 'Sledge*', 'Mallet*', 'Crowbar', 'Crow Bar', 'Pry Bar*', 'Bolt Cutter*', 'Hook*', 'Long Reach Tool*', 'Combination Forks', 'Override Tool*',
                'Glass Master*', 'Glassmaster*', 'Glass Cutter*', 'Center Punch*', 'Car Lock Out Kit*',
            ]],
            ['Shovels, brooms and hand tools', $l, 'hand_tool', [
                'Shovel*', 'Broom*', "Builder's Square", 'Rebar', 'Metal Plates', 'Chain', 'Chains with tips', 'Come-a-long*', 'Come-along*', 'Hi-Lift Jack*', 'Jumper Cables*',
                'Hose Clamps*', 'Hose Roller*', 'Hydrant Wrench*', 'Hydrant Assist*', 'Spanner*', 'LDH Spanner*',
            ]],
            ['Cribbing and wedges', $l, 'hand_tool', ['Cribbing*', 'Hybrid Wedge*', 'Wedge*']],
            ['Fittings, adapters and valves', $l, 'hand_tool', [
                'Storz to*', 'Storz Adapter*', 'Metro Adapter*', 'Reducer*', 'Double Male*', 'Siamese*', 'Gated Wye*', 'Wye*', 'Inline Ball Valve*', 'Inline Valve*', 'In line Valve*',
                'Inline With Couplings', 'Steamer Cap*', 'Cap*', 'Strainer*', 'Pressure Gauge*', 'Rear Supply Appliance*', 'Gaskets*', 'Garden Hose & Adapter', 'Pneumatic Hose',
                'Air Hose/Gauge/Chuck', '3" Drop Section', 'Plug & Dike', 'Leak Bag*',
            ]],
            ['Foam parts and lubricated tools', $l, 'hand_tool', ['Foam Eductor*', 'Foam Pickup*', 'Foam Transfer*', 'Foam tip*', 'Drill bit set', 'Gas Shut Off Valve*', 'Sprinkler*', 'Hydraulic Gas Squeeze Off', 'Manual Gas Squeeze off']],
            ['Keys, openers and small hardware', $l, 'hand_tool', [
                'Elevator Key*', 'Lock Box Key*', 'Fire Alarm Key*', 'Garage Door Clicker*', 'Twist Lock*', 'Extension Cord*', 'Extension Cords*', 'Reg. Extension cords', 'Electrical Junction Box',
                'Standard Extension Cord*', 'XL Carabiner*', 'Carabiner*', 'Webbing*', 'Black Webbing', 'Ratchet Strap*', 'Yellow Runner*', 'Straps',
            ]],
            ['Lights, tool boxes and bags', $l, 'hand_tool', [
                'Flashlight*', 'Flash Lights', 'Portable Lights', 'Binoculars', 'Voice Amp', 'Electric Fan', 'Tool Box*', 'Toolbox*', 'Carry All*', 'High Rise Bag', 'High Rise Pack',
                'Highrise Pack', 'Small Engine Bag', "Small Engine's Bag", 'Sawzall Bag', 'Truck Cache Bag', 'Bridge Bag', 'Anchor Bag', 'Rigging Bag', 'Utility Rope bag', 'Search Rope Bag',
                'Back Up Rope Bag', 'Extrication Bag', "Officer's Pack", 'Helmet Shields', 'Helmet Shield',
            ]],
            ['Fireboat deck gear', $l, 'other', [
                'Black Spare Shorelines', 'Black Docking Shorelines', 'Red Ball Bumpers', 'Anchor w/Chain & Rope', 'Bucket W/ Grapple on a chain with rope', 'Buckets', 'Tools (Inside Bench)',
                'Green Line', 'Soft Buoy', 'Life Ring', 'Torpedo Buoy*', 'Torpedo Bou*', 'Throw Bag*', 'Throw Frisbee', 'Rescue Frisbee*', 'Large Dive Flag', 'Dive Mask & Snorkel', 'Fins',
                'Snorkel Gear', 'Dive Light', 'Vehicle exterior clean', 'Warning triangles',
            ]],
            // Rescue medical (Captain 5 follows up).
            ['Backboards, collars and splints', $l, 'rescue_medical', [
                'Backboard*', 'Head Beds/Straps', 'Collars & headbeds', 'C-Collar*', 'Adj C-Collar', 'Splint*', 'Pedi Inflatable Splint', 'Arizona Vortex*', 'KED', 'Ferno Pedi-Mate*',
                'Patient FERNO 4 Point Restraint*', 'Mega Mover*',
            ]],
            ['Medical bags, kits and supplies', $l, 'rescue_medical', [
                'Airway Bag', 'Trauma Bag', 'Med Box', 'Med Supply Box', 'Medical Supplies', 'Boo Boo Box', 'Boo-Boo Box', 'MCI Bag', 'MCI Kit', 'OB Kits', 'First aid*', 'HandTevy*', 'Handtevy*',
                'Sharps*', 'Cooler*', 'Oral Thermometer*', 'Yellow Blankets',
            ]],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: list<string>}> */
    private static function medium(): array
    {
        $m = EquipmentClassification::MEDIUM;

        return [
            // Tools.
            ['Power tools', $m, 'power_tool', [
                'Sawzall*', 'SawZall 20 Volt and Batteries', 'Reciprocating Saw*', 'Dewalt K-Saw with Spare battery', 'K-Saw*', 'Partner Saw*', 'Band Saw*', 'Bandsaw*', 'Chainsaw*',
                'Chain Saw*', 'Circular Saw*', 'Rotary Saw*', 'Cutters Edge*', 'Angle Grinder*', 'Impact Wrench*', 'Impact Drill*', 'Hammer Drill*', 'Drill/Hammer Drill', 'Impact Driver*',
                'Oscillating Tool*', 'Air Chisel*', 'Honda Generator', 'Generator*', 'Hydraulic Generator', 'Power Unit*',
            ]],
            ['Fans', $m, 'power_tool', ['Gas Fan*', 'SuperVac Battery Fan', 'Turbo Draft*', 'PPV Fan*', 'Fans']],
            ['Hydraulic and rescue tools', $m, 'specialized_tool', [
                'Hydraulic*', 'TNT*', 'Spreader*', 'Combi Tool*', 'Rabbit Tool*', 'K-Tool', 'Ram Extension Box', 'Ram Support', 'Hot Stick*', 'Man vs Machine',
            ]],
            ['Rescue struts and stabilization', $m, 'specialized_tool', ['Paratech*', 'Res-Q*', 'ResQ*', 'V-Struts', 'Strut*', 'Rocker Panel Brace', 'Stabilization Bag', 'Stokes*']],
            ['Ropes and rescue harnesses', $m, 'specialized_tool', ['Rope*', 'Poly Rescue Rope*', '50\' 8mm', 'Harness*', 'Ladder Belt*', 'Rope Gear', 'Rope Rescue Gear', 'Dive Rescue Gear', 'Surf Rescue Gear']],
            ['Ground ladders', $m, 'vehicle_component', [
                '14\' Extension', '14\' Extension Ladder', '24\' Extension', '24\' Extension Ladder', '35\' Extension', '35\' Extension Ladder', 'Roof Ladder*', '16\' Roof', 'Attic Ladder*',
                'Little Giant*', 'Extension Ladder*',
            ]],
            // Fire hose, nozzles and appliances.
            ['Hose and hose lines', $m, 'vehicle_component', [
                'Hose*', 'Line*', 'Crosslay*', 'Pony*', 'Foam Line*', 'Soft Suction*', '50\' LDH Roll', 'Dewatering*', 'Flat Compartment (2)-50 ft of 3 in to a Storz', 'Street Manifold*', 'Fresh Water Coil Hose & Nozzle',
                "400' 3\" Hose w/ Inline Valve w/ Dbl/Male/Females", "500' 3\" Hose w/ Inline Ball Valve w/ Dbl/Male/Females", "800' 3\" Hose w/ Inline Valve w/ Dbl/Male/Females",
            ]],
            ['Nozzles and master streams', $m, 'vehicle_component', [
                'Nozzle*', 'Solid Bore*', 'Master Stream*', 'Blitzfire*', 'Aztec', 'MX Foam Jet', 'Florida FOG', 'Aerial Fog Nozzle/Tips', 'Large Foam Nozzle', 'Cellar Nozzle*', 'Fog Nozzle*',
            ]],
            ['Fire extinguishers', $m, 'other', ['Extinguisher*', 'CO2', 'Wet Water', 'Dry Chemical', 'Dry Chem*', 'Fire extinguisher']],
            // Station facility and appliances (members describe these in station requests).
            ['Station air conditioning and appliances', $m, 'station_facility', [
                'A/C', 'AC unit*', 'Air condition*', 'HVAC*', 'Refrigerator*', 'Fridge*', 'Freezer*', 'Ice machine*', 'Dishwasher*', 'Washing machine*',
                'Dryer*', 'Oven*', 'Stove*', 'Water heater*', 'Toilet*', 'Plumbing*', 'Roof leak*', 'Bay door*', 'Overhead door*', 'Door lock*',
                'Fire alarm panel*', 'Sprinkler system*',
            ]],
            // Detection and electronics.
            ['Gas meters and detection', $m, 'detection', [
                'Gas Meter*', 'Gas monitor*', 'Gas detector*', 'Drager*', 'Dreager*', 'Sensit*', 'LP CO MONITOR', 'LP CO Monitor Serial #', 'RAD Alert 50', 'Radialert', 'CO 3500PAK #', 'H2S 3500 PAK#',
                'CO ID#', 'H2S ID#', 'CO Monitor*', 'CO EXTINGUISHER',
            ]],
            ['Thermal imaging camera', $m, 'electronics', ['TIC', 'TIC w/ Spare Battery', 'TIC / Spare Batteries', 'FLIR*', 'Thermal imag*']],
            ['Radios and computers', $m, 'electronics', ['Radio*', 'Toughbook*', 'CAD', 'Plymovent*', 'Emergency Communication']],
            // Vehicle equipment (Fleet follows up).
            ['Vehicle fluids, tires and lights', $m, 'vehicle_component', [
                'Coolant level', 'Engine oil level', 'Fuel level', 'Tires inspected', 'Lights functional', 'Lights (Check)', 'Siren*', 'Air Horn*', 'Windshield Wipers Operational X3',
                'AFT Deck Lights', 'AFT Steps Lights', 'Port Deck Lights', 'STBD Lights', 'NAV/Anchor Lights', 'Cuddy Cabin Lights', 'Main Cabin Lights', 'Engine Room Lights', 'EMERG Lights', 'RAM Lights',
            ]],
            ['Winches, light towers and boat systems', $m, 'vehicle_component', [
                'Power Winch*', 'Light Tower*', 'BILGE Pump*', 'Lift Remote', 'Stabilizer Controls', 'Manual CO SEA FIRE system safety pin in place',
            ]],
            // Rescue medical.
            ['Lifepak and cardiac monitors', $m, 'rescue_medical', ['Life-Pak', 'LifePak*', 'LUCAS Device W/ Spare Battery', 'LUCAS*', 'Auto Pulse*', 'AutoPulse*', 'Cardiac monitor*', 'Defibrillator*', 'AED*']],
            ['Rescue medical equipment', $m, 'rescue_medical', [
                'Stair Chair*', 'Stairchair*', 'Jumbo Stretcher*', 'Stretcher Charging', 'LSU*', 'Powered Suction', 'Duodote', 'Spare O2 Bottle', 'Spare 02 Bottle', 'O2 Bottle*', 'Spare Oxygen Cylinder',
                'D-cylinder', 'M Cylinders', 'Oxygen*',
            ]],
            ['PFDs and life jackets', $m, 'ppe', ['PFD*', 'Life jacket*', 'Life vest*']],
            // SCBA (Air Tech follows up).
            ['SCBA cylinder', $m, 'scba_cylinder', ['SCBA Bottle*', 'SCBA Cylinder*', 'SCBA Air Bottle*', 'Air bottle*', 'Air cylinder*', 'Breathing air cylinder*', 'Breathing air bottle*', 'Spare SCBA Bottle*']],
            ['SCBA parts and accessories', $m, 'scba_component', [
                'SCBA Hoses/Regulator', 'SCBA regulator*', 'SCBA valve*', 'SCBA harness*', 'SCBA backplate*', 'SCBA hose*', 'SCBA gauge*', 'SCBA battery*', 'SCBA bracket*', 'SCBA strap*',
                'SCBA accessor*', 'SCBA part*', 'Regulator*', 'PASS device*',
            ]],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: list<string>}> */
    private static function high(): array
    {
        $h = EquipmentClassification::HIGH;

        return [
            ['SCBA (complete unit)', $h, 'scba_unit', [
                'SCBA', 'SCBAs', 'SCBA #1', 'SCBA #2', 'SCBA #3', 'SCBA #4', 'SCBA #5', 'SCBA with Identifier', 'Complete SCBA*', 'SCBA unit*', 'Scott Pack Respirator', 'SCBA Mask/Hose/Regulator',
            ]],
            ['SCBA facepiece / mask', $h, 'scba_facepiece', ['SCBA Facemask*', 'SCBA Facepiece*', 'SCBA Face Piece*', 'SCBA Mask*', 'Facepiece*', 'Face piece*']],
            ['Firefighting hoods', $h, 'ppe', ['Hood', 'Hoods', 'Fire hood*', 'Firefighting hood*', 'Nomex hood*', 'Structural hood*', 'Particulate hood*']],
            ['Firefighting helmets', $h, 'ppe', ['Helmet*', 'Firefighting helmet*', 'Structural helmet*', 'Fire helmet*', 'TRT Helmets', 'Ballistic vests and helmets']],
            ['Bunker coat', $h, 'ppe', ['Bunker coat*', 'Turnout coat*', 'Bunker jacket*', 'Turnout jacket*', 'Structural coat*', 'Firefighting coat*']],
            ['Bunker pants', $h, 'ppe', ['Bunker pant*', 'Turnout pant*', 'Structural pant*']],
            ['Other structural firefighting PPE', $h, 'ppe', ['Bunker gear*', 'Turnout gear*', 'Structural gear*', 'Bunker boot*', 'Turnout boot*', 'Structural boot*', 'Structural glove*', 'Firefighting glove*', 'Fire glove*']],
            // Checklist rows for a riding position's full gear set (exact names only; "gear" alone is never matched in text).
            ['Position gear set', $h, 'ppe', ['PPE', 'PPE Bag', 'Driver Gear', "Driver's Gear", 'Officer Gear', "Officer's Gear", 'F-1 Gear', 'F-I Gear', 'FI Gear']],
        ];
    }
}
