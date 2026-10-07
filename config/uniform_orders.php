<?php

declare(strict_types=1);

$sizes = array_combine(['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'], ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL']);
$size = ['key' => 'size', 'label' => 'Size', 'type' => 'select', 'options' => $sizes, 'required' => true];
$cut = ['key' => 'cut', 'label' => 'Requested cut', 'type' => 'select', 'options' => ['mens' => "Men's", 'womens' => "Women's"], 'required' => false,
    'help' => 'Support Services will confirm vendor availability.'];
// These are measurement safeguards, not a claim about available vendor sizes.
$waist = ['key' => 'waist', 'label' => 'Waist (inches)', 'type' => 'number', 'min' => 1, 'max' => 100, 'step' => 0.5, 'required' => true];
$inseam = ['key' => 'inseam', 'label' => 'Inseam (inches)', 'type' => 'number', 'min' => 1, 'max' => 60, 'step' => 0.5, 'required' => true];
$neck = ['key' => 'neck', 'label' => 'Neck (inches)', 'type' => 'number', 'min' => 1, 'max' => 40, 'step' => 0.5, 'required' => true];
$shoeSize = ['key' => 'shoe_size', 'label' => 'Shoe size (US)', 'type' => 'number', 'min' => 1, 'max' => 30, 'step' => 0.5, 'required' => true,
    'help' => 'Enter your requested size; Support Services will confirm the vendor sizing.'];
$measurementHelp = 'Enter your measurements; Support Services will confirm available vendor sizes.';
$jacketStyles = [
    'vintage' => ['label' => 'MBFD Vintage jacket', 'asset' => 'jacket-vintage'],
    'quarter_zip' => ['label' => '5.11 Quarter Zip', 'asset' => 'jacket-quarter-zip'],
    'softshell' => ['label' => '5.11 Softshell', 'asset' => 'jacket-softshell'],
];

return [
    'version' => '2026-10-07',
    'form_version' => 'uniform-builder-v1',
    'quantity_max' => 999,
    'note_max' => 4000,
    'jacket_styles' => $jacketStyles,
    'legacy_jacket_stock_labels' => ['Winter Jacket'],
    'categories' => [
        'dress' => 'Dress Uniform',
        'work' => 'Work Uniforms',
        'operational' => 'Rescue / Operational',
        'tshirts' => 'T-Shirts',
        'accessories' => 'Accessories',
        'marine' => 'Marine / Specialty',
    ],
    'groups' => [
        'dress_shirts' => 'Class A shirts', 'dress_pants' => 'Class A pants', 'ties' => 'Ties',
        'polos' => 'Polos', 'pants' => 'Work pants', 'jumpsuits' => 'Jumpsuits', 'uniform_shirts' => 'Other uniform shirts',
        'tshirts' => 'T-shirts', 'belts' => 'Work belts', 'footwear' => 'Footwear',
        'jackets' => 'Winter jackets', 'raincoats' => 'Raincoats', 'class_a_coats' => 'Class A coats',
        'marine_shorts' => 'Boating shorts', 'marine_ss' => 'Marine short sleeve shirts',
        'marine_ls' => 'Marine long sleeve shirts', 'marine_shoes' => 'Boating shoes',
    ],
    'profiles' => [
        'combat' => ['label' => 'Combat', 'allowances' => [
            'dress_uniforms' => 1, 'dress_shirts' => 1, 'dress_pants' => 1, 'ties' => 1,
            'work_sets' => 3, 'polos' => 3, 'pants' => 3, 'jumpsuits' => 2, 'footwear' => 1, 'belts' => 1, 'tshirts' => 4,
        ]],
        'rescue' => ['label' => 'Rescue', 'allowances' => [
            'dress_uniforms' => 1, 'dress_shirts' => 1, 'dress_pants' => 1, 'ties' => 1,
            'work_sets' => 2, 'polos' => 2, 'pants' => 2, 'jumpsuits' => 3, 'footwear' => 1, 'belts' => 1, 'tshirts' => 4,
        ]],
        'day_other' => ['label' => 'Day / Other', 'allowances' => [],
            'message' => 'You may select any combination of available uniform items. Per the labor agreement, the total value should not exceed the value provided to a Rescue Division employee. Accurate costs are not currently configured; Support Services will review the value.'],
    ],
    'marine_allowances' => ['marine_shorts' => 3, 'marine_ss' => 3, 'marine_ls' => 3, 'marine_shoes' => 1],
    'three_year_allowances' => ['jackets' => 1, 'raincoats' => 1],
    'three_year_message' => 'One jacket is provided every 3 years from its issue date. Raincoat issue history must be confirmed by Support Services.',
    'swap' => ['from' => 'jumpsuits', 'to' => ['polos', 'pants'], 'rate' => 1],
    'assignment_matching' => [
        'marine' => '/\b(?:marine|fire\s*boat)\b/i',
        'day_other' => '/\b(?:division\s+chief|deputy\s+(?:fire\s+)?chief|fire\s+chief|prevention|support\s+services|public\s+education|administrat\w*|day\s+(?:shift|staff|assignment))\b/i',
        'rescue' => '/\b(?:rescue(?:\s*\d+)?|captain\s*5)\b/i',
        'combat' => '/\b(?:combat|engine(?:\s*\d+)?|ladder(?:\s*\d+)?)\b/i',
    ],
    'rank_recommendations' => ['chief_pattern' => '/\bchief\b/i', 'chief_pants_color' => 'black', 'default_pants_color' => 'navy'],
    'products' => [
        'class_a_shirt' => ['label' => 'Class A Short Sleeve Shirt', 'category' => 'dress', 'group' => 'dress_shirts',
            'fields' => [$neck], 'variant' => ['sleeve' => 'short'], 'asset' => 'class-a-short', 'help' => $measurementHelp],
        'class_a_long_sleeve_shirt' => ['label' => 'Class A Long Sleeve Shirt', 'category' => 'dress', 'group' => 'dress_shirts',
            'fields' => [$neck, ['key' => 'sleeve_length', 'label' => 'Sleeve length (inches)', 'type' => 'number', 'min' => 1, 'max' => 60, 'step' => 0.5, 'required' => true]],
            'variant' => ['sleeve' => 'long'], 'asset' => 'class-a-long', 'help' => $measurementHelp],
        'class_a_pants' => ['label' => 'Class A Pants', 'category' => 'dress', 'group' => 'dress_pants',
            'asset' => 'class-a-pants',
            'fields' => [$waist, $inseam, ['key' => 'color', 'label' => 'Requested color', 'type' => 'select', 'options' => ['navy' => 'Navy', 'black' => 'Black'], 'required' => true]],
            'help' => $measurementHelp],
        'tie' => ['label' => 'Uniform Tie', 'category' => 'dress', 'group' => 'ties', 'fields' => [], 'asset' => 'tie'],
        'work_boots' => ['label' => 'Footwear — Boots or Dress Shoes', 'category' => 'dress', 'group' => 'footwear',
            'fields' => [['key' => 'footwear_type', 'label' => 'Footwear type', 'type' => 'select', 'options' => ['boots' => 'Boots', 'dress_shoes' => 'Dress shoes'], 'required' => true], $shoeSize, $cut,
                ['key' => 'width', 'label' => 'Requested width', 'type' => 'text', 'max_length' => 30, 'required' => false, 'help' => 'Use the width shown on your current footwear, if known.']],
            'help' => 'One pair: boots or dress shoes, your choice. Vendor availability will be confirmed.'],
        'polo_shirt' => ['label' => 'Short Sleeve Polo', 'category' => 'work', 'group' => 'polos',
            'fields' => [$size, $cut], 'variant' => ['sleeve' => 'short'], 'asset' => 'polo-short'],
        'long_sleeve_polo' => ['label' => 'Long Sleeve Polo', 'category' => 'work', 'group' => 'polos',
            'fields' => [$size, $cut], 'variant' => ['sleeve' => 'long'], 'asset' => 'polo-long'],
        'uniform_pants' => ['label' => '5.11 Tactical Pants', 'category' => 'work', 'group' => 'pants',
            'fields' => [$waist, $inseam, $cut], 'asset' => 'tactical-pants', 'help' => $measurementHelp],
        'uniform_shirt' => ['label' => 'Uniform Shirt', 'category' => 'work', 'group' => 'uniform_shirts',
            'fields' => [$size, $cut], 'help' => 'Existing uniform catalog item. Support Services will confirm the style.'],
        'jumpsuit' => ['label' => 'Jumpsuit', 'category' => 'operational', 'group' => 'jumpsuits', 'asset' => 'jumpsuit',
            'fields' => [['key' => 'jumpsuit_chest', 'label' => 'Chest (inches)', 'type' => 'number', 'min' => 38, 'max' => 56, 'step' => 1, 'required' => true],
                ['key' => 'jumpsuit_length', 'label' => 'Length / fit', 'type' => 'select', 'options' => ['Short' => 'Short', 'Regular' => 'Regular', 'Long' => 'Long', 'Tall' => 'Tall'], 'required' => true]],
            'help' => 'Chest 38–56 and these length labels follow the supplied ordering notes. Support Services will confirm the vendor combination.'],
        't_shirt' => ['label' => 'Short Sleeve T-Shirt', 'category' => 'tshirts', 'group' => 'tshirts',
            'fields' => [$size], 'variant' => ['sleeve' => 'short'], 'asset' => 'tshirt-short'],
        'long_sleeve_shirt' => ['label' => 'Long Sleeve T-Shirt', 'category' => 'tshirts', 'group' => 'tshirts',
            'fields' => [$size], 'variant' => ['sleeve' => 'long'], 'asset' => 'tshirt-long'],
        'belt' => ['label' => 'JUKMO Work Belt', 'category' => 'accessories', 'group' => 'belts', 'asset' => 'belt',
            'fields' => [['key' => 'size', 'label' => 'Size', 'type' => 'select', 'options' => ['S' => 'S', 'M' => 'M', 'L' => 'L', 'XL' => 'XL', 'XXL' => 'XXL'], 'required' => true]]],
        'jacket' => ['label' => 'Jacket', 'category' => 'accessories', 'group' => 'jackets', 'quantity_max' => 1,
            'asset' => 'jacket-vintage',
            'fields' => [['key' => 'jacket_style', 'label' => 'Jacket style', 'type' => 'select', 'options' => array_map(fn (array $style): string => $style['label'], $jacketStyles), 'required' => true], $size],
            'frequency' => 'every_3_years', 'help' => 'Choose one style. The 3-year cycle begins when your jacket is issued.'],
        'raincoat' => ['label' => 'Raincoat', 'category' => 'accessories', 'group' => 'raincoats', 'fields' => [$size], 'frequency' => 'every_3_years', 'asset' => 'raincoat'],
        'class_a_coat' => ['label' => 'Class A Coat', 'category' => 'dress', 'group' => 'class_a_coats',
            'fields' => [['key' => 'size', 'label' => 'Requested size', 'type' => 'text', 'max_length' => 30, 'required' => true]],
            'help' => 'Existing uniform catalog item. Enter your current coat size for Support Services review.'],
        'marine_shorts' => ['label' => 'Boating Shorts', 'category' => 'marine', 'group' => 'marine_shorts', 'fields' => [$waist, $cut], 'help' => $measurementHelp],
        'marine_short_sleeve_shirt' => ['label' => 'Marine Short Sleeve Shirt', 'category' => 'marine', 'group' => 'marine_ss', 'fields' => [$size, $cut], 'variant' => ['sleeve' => 'short']],
        'marine_long_sleeve_shirt' => ['label' => 'Marine Long Sleeve Shirt', 'category' => 'marine', 'group' => 'marine_ls', 'fields' => [$size, $cut], 'variant' => ['sleeve' => 'long']],
        'boating_shoes' => ['label' => 'Boating Shoes', 'category' => 'marine', 'group' => 'marine_shoes', 'fields' => [$shoeSize, $cut,
            ['key' => 'width', 'label' => 'Requested width', 'type' => 'text', 'max_length' => 30, 'required' => false]]],
    ],
];
