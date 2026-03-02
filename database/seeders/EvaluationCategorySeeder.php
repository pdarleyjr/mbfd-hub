<?php

namespace Database\Seeders;

use App\Models\EvaluationCategory;
use Illuminate\Database\Seeder;

class EvaluationCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Forcible Entry',
                'description' => 'Evaluation of forcible entry tools and techniques',
                'is_rankable' => true,
                'display_order' => 1,
                'is_active' => true,
            ],
            [
                'name' => 'Extrication',
                'description' => 'Evaluation of extrication equipment and procedures',
                'is_rankable' => true,
                'display_order' => 2,
                'is_active' => true,
            ],
            [
                'name' => 'Stabilization',
                'description' => 'Evaluation of stabilization equipment and techniques',
                'is_rankable' => true,
                'display_order' => 3,
                'is_active' => true,
            ],
            [
                'name' => 'Appliances',
                'description' => 'Evaluation of firefighting appliances and fittings',
                'is_rankable' => true,
                'display_order' => 4,
                'is_active' => true,
            ],
            [
                'name' => 'Training',
                'description' => 'Training equipment and materials evaluation',
                'is_rankable' => true,
                'display_order' => 5,
                'is_active' => true,
            ],
            [
                'name' => 'Instructors',
                'description' => 'Instructor evaluation and certification',
                'is_rankable' => false,
                'display_order' => 6,
                'is_active' => true,
            ],
        ];

        foreach ($categories as $category) {
            EvaluationCategory::firstOrCreate(
                ['name' => $category['name']],
                $category
            );
        }
    }
}
