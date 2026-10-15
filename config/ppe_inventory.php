<?php

declare(strict_types=1);

/*
 * Suggestions offered by the Firefighting PPE / Uniforms inventory form.
 * Every field also accepts free text, so these lists only speed up entry;
 * values already typed into the inventory are suggested alongside them.
 */
return [
    'categories' => [
        'Uniform',
        'Firefighting PPE',
        'Footwear',
        'Outerwear',
        'Accessory',
        'Other',
    ],

    'item_types' => [
        // Firefighting PPE
        'Bunker Coat',
        'Bunker Pants',
        'Helmet',
        'Structural Gloves',
        'Extrication Gloves',
        'Medical Gloves',
        'Firefighting Boots',
        'Hood',
        'Suspenders',
        'SCBA Mask',
        'Wildland Coat',
        'Wildland Pants',
        'Eye Protection',
        'Hearing Protection',
        // Uniforms
        'T-Shirt',
        'Long Sleeve T-Shirt',
        'Polo Shirt',
        'Class A Shirt',
        'Class B Shirt',
        'Uniform Pants',
        'Shorts',
        'Station Boots',
        'Jacket',
        'Sweatshirt',
        'Job Shirt',
        'Jumpsuit',
        'Belt',
        'Cap',
        'Beanie',
        'Badge',
        'Collar Brass',
        'Name Tag',
    ],

    'manufacturers' => [
        'Globe',
        'MSA',
        'Lion',
        'Fire-Dex',
        'Honeywell',
        'Morning Pride',
        'Veridian',
        'Quaker Safety',
        'Innotex',
        'Cairns',
        'Bullard',
        'Phenix',
        'Dräger',
        'Scott Safety',
        'Majestic',
        'PGI',
        'Shelby',
        'HexArmor',
        'Ringers',
        'Haix',
        'Thorogood',
        'Black Diamond',
        'Pro-Warrington',
        '5.11 Tactical',
        'Blauer',
        'Elbeco',
        'Galls',
        'Flying Cross',
        'Lighthouse Uniform',
        'Carhartt',
    ],

    'sizes' => [
        // Garments
        'XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL',
        'S Tall', 'M Tall', 'L Tall', 'XL Tall', '2XL Tall', '3XL Tall',
        // Gloves
        'Glove XS', 'Glove S', 'Glove M', 'Glove L', 'Glove XL', 'Glove 2XL',
        // Boots
        '6', '6.5', '7', '7.5', '8', '8.5', '9', '9.5', '10', '10.5', '11', '11.5', '12', '12.5', '13', '14', '15',
        '9W', '9.5W', '10W', '10.5W', '11W', '11.5W', '12W', '13W',
        // Pants and turnout gear (waist x inseam / chest x sleeve)
        '28x30', '30x30', '30x32', '32x30', '32x32', '34x30', '34x32', '36x30', '36x32', '38x30', '38x32', '40x30', '40x32', '42x30', '42x32', '44x32',
        '40 Chest x 32 Sleeve', '42 Chest x 32 Sleeve', '44 Chest x 34 Sleeve', '46 Chest x 34 Sleeve', '48 Chest x 35 Sleeve', '50 Chest x 35 Sleeve',
        'One Size',
    ],

    'colors' => [
        'Navy',
        'Black',
        'Tan',
        'Khaki',
        'Gold',
        'Yellow',
        'Hi-Vis Yellow',
        'Red',
        'White',
        'Gray',
        'Charcoal',
        'Royal Blue',
    ],

    'conditions' => [
        'New',
        'Like New',
        'Good',
        'Fair',
        'Needs Repair',
        'Out of Service',
    ],

    'storage_locations' => [
        'Headquarters Supply',
        'Station 1',
        'Station 2',
        'Station 3',
        'Station 4',
        'Station 6',
        'Support Services',
    ],
];
