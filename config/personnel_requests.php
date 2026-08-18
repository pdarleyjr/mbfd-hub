<?php

return [
    /* Unknown codes are intentionally absent and therefore fail closed. */
    'uniform_catalog' => [
        't_shirt' => ['label' => 'T-Shirt', 'sizes' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL']],
        'long_sleeve_shirt' => ['label' => 'Long Sleeve Shirt', 'sizes' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL']],
        'polo_shirt' => ['label' => 'Polo Shirt', 'sizes' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL']],
        'uniform_shirt' => ['label' => 'Uniform Shirt', 'sizes' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL']],
        'uniform_pants' => ['label' => 'Uniform Pants', 'sizes' => []],
        'class_a_shirt' => ['label' => 'Class A Shirt', 'sizes' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL']],
        'class_a_pants' => ['label' => 'Class A Pants', 'sizes' => []],
        'class_a_coat' => ['label' => 'Class A Coat', 'sizes' => []],
        'jacket' => ['label' => 'Department Jacket', 'sizes' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL']],
        'belt' => ['label' => 'Uniform Belt', 'sizes' => []],
        'work_boots' => ['label' => 'Work Boots', 'sizes' => []],
    ],

    'equipment_catalog' => [
        'firefighting_gloves' => 'Firefighting Gloves',
        'structural_firefighting_helmet' => 'Structural Firefighting Helmet',
        'bunker_boots' => 'Bunker Boots',
        'bunker_pants' => 'Bunker Pants',
        'bunker_coat' => 'Bunker Coat',
        'protective_hood' => 'Protective Hood',
        'waist_straps_suspenders' => 'Waist Straps / Suspenders',
        'scba_facepiece' => 'SCBA Facepiece',
        'other' => 'Other',
    ],

    'equipment_reasons' => [
        'lost' => 'Lost',
        'damaged' => 'Damaged',
        'stolen' => 'Stolen',
    ],

    'officer_ranks' => [
        'Lieutenant',
        'Captain',
        'Division Chief',
        'Deputy Fire Chief',
        'Fire Chief',
    ],

    'expiration_thresholds' => [60, 30, 7, 0],
];
