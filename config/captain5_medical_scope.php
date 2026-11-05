<?php

/*
|--------------------------------------------------------------------------
| Captain 5 medical equipment scope (Engine / Ladder Daily Checkout)
|--------------------------------------------------------------------------
| A reviewed mapping keyed by (checklist_type, item_key). It is NOT a keyword
| rule: runtime classification only looks items up here (and in the admin
| decisions table captain5_item_classifications, which wins).
|
| For each checklist template:
|  - `reviewed_version`: canonical sha256 of the template that was reviewed item by
|    item (tests/Unit/Captain5/MedicalScopeMappingTest fails when a template changes,
|    forcing a new review).
|  - `medical`: patient-care equipment Captain 5 oversees.
|  - `ambiguous`: items whose medical role depends on local use. They stay hidden from
|    Captain 5 and appear in the admin Medical Scope review until an administrator decides.
|  - every other item present in the reviewed template is not medical (Fleet scope).
|  - items that did not exist at review time (for example inventory-correction additions
|    `inv-c*`) are unclassified: hidden from Captain 5 and queued for admin review.
|
| Ownership follows the equipment, not the apparatus (docs/unified-activity-and-ownership.md):
| a Rescue's DeWalt saws, SCBA, TIC, rope and water-rescue gear are Logistics', its patient-care
| equipment and the stretcher (charging, spare battery, restraints) are Captain 5's.
*/

return [
    'engine' => [
        'reviewed_version' => '68153fa21c028da37ea1935e1aed9a40b774393eda597ad2da38cf27c0091868',
        'medical' => [
            'comp_a3-auto-pulse',        // Auto Pulse
            'comp_1-item-5',             // MCI Kit
            'comp_1-item-6',             // Handtevy Kit
            'comp_4-item-1',             // LifePak
            'comp_4-item-2',             // Med Box
            'comp_4-item-3',             // Airway Bag
            'comp_4-item-4',             // Spare O2 Bottle
            'comp_4-item-5',             // Med Supply Box
            'comp_b4-lsu-suction',       // LSU Suction
            'comp_b4-boo-boo-box',       // Boo Boo Box
            'comp_b4-sharps-cont',       // Sharps Cont.
            'comp_b4-c-collars',         // C-Collars
            'comp_b4-head-beds-straps',  // Head Beds/Straps
            'backboards_comp-item-1',    // Backboards
            'backboards_comp-item-2',    // Cardboard Splints
            'top_comp_2-item-4',         // Stair Chair (Top Compartment 1)
            'top_comp_3-item-3',         // KED
            'top_comp_3-item-4',         // Traction Splint
        ],
        'ambiguous' => [
            'comp_4-item-6',             // Coil Pack (stored with medical gear; a hose pack on Ladder 1)
            'comp_b4-water-cooler-cups', // Water Cooler/Cups
            'comp_b1-gloves',            // Gloves (Officer R4, beside MCI/Handtevy)
            'comp_b1-sleeves',           // Sleeves
            'top_comp_3-item-6',         // Tyvek Suits
        ],
    ],

    'engine2' => [
        'reviewed_version' => 'ad29c6f095560e37865745c246ef308e5523ab5487746caea0f1b74ee08a3716',
        'medical' => [
            'comp_l1-item-2',            // HandTevy Box
            'comp_l1-item-3',            // MCI Kit
            'comp_l3-item-1',            // Auto Pulse
            'comp_r1-item-1',            // Life-Pak
            'comp_r1-item-2',            // Med Box
            'comp_r1-item-3',            // Airway Bag
            'comp_r1-item-4',            // LSU Suction
            'comp_r1-item-5',            // Boo Boo Box
            'comp_r1-item-6',            // Sharps Container
            'comp_r1-item-7',            // C-Collars
            'comp_r1-item-8',            // Head Beds/Straps
            'backboards_comp-item-1',    // Backboards
            'backboards_comp-item-2',    // Cardboard Splints
            'top_comp_2-item-6',         // Stair Chair
            'top_comp_2-item-7',         // KED
            'top_comp_2-item-8',         // Traction Splint
        ],
        'ambiguous' => [
            'scba_bottle_comp-item-3',   // Spare Oxygen Cylinder (SCBA compartment: medical O2 or breathing air?)
            'top_comp_1-item-5',         // PPE Bag
            'top_comp_1-item-6',         // Tyvek Suit
            'top_comp_3-item-9',         // Patient Harness (rope rescue compartment)
            'comp_r4-item-1',            // Water Cooler/Cups
            'rear_cab-item-10',          // Gloves (M, L, XL)
            'rear_cab-item-11',          // Sleeves
        ],
    ],

    'ladder1' => [
        'reviewed_version' => 'cd5d2a0d3e432d92f8a09f62a1f624cc62e0581e4b7f29f5a280bfd926b3f58c',
        'medical' => [
            'backboards_comp-item-1',    // Backboards
            'backboards_comp-item-2',    // Cardboard Lg Splints
            'backboards_comp-item-3',    // Cardboard Sm Splints
            'backboards_comp-item-4',    // Adj C-Collar
            'comp_5-item-2',             // Emergency Blankets
            'comp_5-item-3',             // Sterile Water
            'comp_5-item-7',             // Collars & headbeds
            'comp_5-item-8',             // KED
            'comp_5-item-10',            // Traction Splint
            'comp_4-item-3',             // LifePak
            'comp_4-item-4',             // Med Box
            'comp_4-item-5',             // Airway Bag
            'comp_4-item-6',             // O2 Bottle
            'comp_4-item-7',             // Med Supply Box
            'comp_4-item-8',             // Powered Suction
            'rear_cab-item-8',           // Handtevy Box
            'rear_cab-item-9',           // MCI Bag
        ],
        'ambiguous' => [
            'comp_5-item-1',             // Sanizide (disinfectant)
            'comp_5-item-4',             // Alcohol
            'comp_5-item-5',             // Peroxide
            'comp_5-item-6',             // SpeedyCleanz
            'comp_5-item-9',             // Carry All
            'comp_2-item-2',             // Jumbo Stretcher
            'comp_2-item-3',             // Mega Mover
            'rear_cab-item-10',          // PPE
            'rear_cab-item-11',          // Tyvex Suits
            'ladder-item-3',             // Stokes w/ Harnesses
        ],
    ],

    'ladder3' => [
        'reviewed_version' => 'aee36ecac4a2fb837feeb999e3852945e9d43cf1c2a6588dd3b7d057338618dd',
        'medical' => [
            'comp_4-item-10',            // Sharps Container
            'comp_4-item-11',            // Medical Supplies
            'comp_4-item-12',            // Yellow Blankets
            'officer_side_panel-item-4', // LifePak
            'officer_side_panel-item-5', // Med Box
            'officer_side_panel-item-6', // Airway Bag
            'officer_side_panel-item-7', // Medical Supplies
            'officer_side_panel-item-8', // Boo-Boo Box
            'officer_side_panel-item-9', // Duodote
            'officer_side_panel-item-24', // Backboard
            'rear_cab-item-12',          // LSU Suction
        ],
        'ambiguous' => [
            'officer_side_panel-item-10', // Scott Pack Respirator
            'comp_4-item-13',            // Fluid Absorbent
            'officer_side_panel-item-20', // Stokes Basket
            'rear_cab-item-11',          // Water Cooler
        ],
    ],

    'rescue' => [
        'reviewed_version' => '60b5f4f4b96284e2d17254b517c3c77c961bb1554c3bb733198d413193f9cf01',
        'medical' => [
            'patient_compartment-item-9',  // MCI Bag
            'compartment_b-item-11',       // LUCAS Device W/ Spare Battery
            'compartment_a-item-1',        // M Cylinders (oxygen)
            'compartment_a-item-2',        // Backboard / Scoop Stretcher
            'compartment_a-item-3',        // D-cylinder (oxygen)
            'compartment_a-item-4',        // Stair Chair (L4)
            'compartment_d-item-2',        // Stair Chair (R1)
            'compartment_d-item-3',        // KED
            'compartment_d-item-5',        // Traction Splint
            'compartment_d-item-7',        // C-Collars Reg & No Neck
            'compartment_d-item-9',        // C-Collars Pedi
            'compartment_d-item-10',       // Cardboard Splints
            'compartment_d-item-12',       // Pedi Backboard
            'compartment_a-item-5',        // HANDTEVY
            'compartment_a-item-6',        // Emergency Yellow Blankets
            'patient_compartment-item-6',  // Ferno Pedi-Mate
            'patient_compartment-item-8',  // Cooler (@36-48 Degrees), medication cooler
            'patient_compartment-item-17', // Pedi Inflatable Splint
            'patient_compartment-item-19', // OB Kits
            'patient_compartment-item-22', // Carry Alls (patient movement)
            'patient_compartment-item-2',  // Airway Bag
            'patient_compartment-item-3',  // Med Box
            'patient_compartment-item-4',  // Trauma Bag
            'patient_compartment-item-5',  // LifePak 15
            'patient_compartment-item-7',  // LSU Portable Suction
            'patient_compartment-item-16', // Oral Thermometer
            'stretcher-item-1',            // Patient FERNO 4 Point Restraint w/ Lap Belt
            'stretcher-item-2',            // Stretcher Charging
            'compartment_b-item-12',       // Spare Battery (stretcher compartment: the stretcher's battery)
            'compartment_c-item-8',        // Yellow Blanket (stretcher)
            'compartment_c-item-9',        // Spare Sheet (stretcher)
            'compartment_c-item-10',       // Carry All (stretcher)
            'compartment_c-item-11',       // O2 Bottle w/ Reg (stretcher)
        ],
        'ambiguous' => [
            'compartment_d-item-14',       // Straps (R1, beside KED and splints: backboard straps or cargo straps?)
            'patient_compartment-item-11', // PPE Bag
            'patient_compartment-item-14', // Tyveks
            'patient_compartment-item-10', // Transport Fee Chart
            'compartment_d-item-11',       // LP CO MONITOR (LifePak CO-oximetry or a gas detector?)
            'compartment_d-item-13',       // LP CO Monitor Serial #
            'compartment_c-item-12',       // Vinegar (jellyfish stings or cleaning?)
            'compartment_c-item-14',       // SpeedyCleanz (disinfectant)
        ],
    ],

    'fireboat6' => [
        'reviewed_version' => '92930bda64264e1b5af7a18c7b4696d218b70a9edebd135309b69ae70737c0dd',
        'medical' => [
            'fb6-medical-airway-bag',      // Airway Bag
            'fb6-medical-med-box',         // Med Box
            'fb6-medical-lifepak-15',      // LifePak 15
            'fb6-medical-spare-o2-bottle', // Spare 02 Bottle
            'fb6-medical-lsu-suction',     // LSU Suction
            'fb6-medical-backboards',      // Backboards (2)
        ],
        'ambiguous' => [
            'fb6-medical-gloves',          // Gloves XXL-SM (1BX)
            'fb6-medical-sleeves',         // Sleeves (1BX)
        ],
    ],
];
