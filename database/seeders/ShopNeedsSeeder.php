<?php

namespace Database\Seeders;

use App\Models\ShopWork;
use Illuminate\Database\Seeder;

class ShopNeedsSeeder extends Seeder
{
    public function run(): void
    {
        // Clear existing shop works
        ShopWork::truncate();

        $items = [
            // Lifting Equipment
            ['project_name' => '2-Post Car Lift (10,000 lb capacity)', 'category' => 'Lifting Equipment', 'quantity' => 1, 'estimated_cost' => 4500, 'priority' => 1],
            ['project_name' => '4-Post Truck Lift (30,000 lb capacity)', 'category' => 'Lifting Equipment', 'quantity' => 1, 'estimated_cost' => 18000, 'priority' => 2],
            ['project_name' => 'Heavy-Duty Mobile Column Lifts (Set of 4)', 'category' => 'Lifting Equipment', 'quantity' => 1, 'estimated_cost' => 25000, 'priority' => 3],
            ['project_name' => 'Transmission Jack (1,500 lb capacity)', 'category' => 'Lifting Equipment', 'quantity' => 1, 'estimated_cost' => 800, 'priority' => 4],
            ['project_name' => 'Engine Hoist/Cherry Picker (2-ton)', 'category' => 'Lifting Equipment', 'quantity' => 1, 'estimated_cost' => 600, 'priority' => 5],
            
            // Jacks & Stands
            ['project_name' => 'Floor Jack (3-ton)', 'category' => 'Jacks & Stands', 'quantity' => 2, 'estimated_cost' => 400, 'priority' => 6],
            ['project_name' => 'Floor Jack (20-ton for heavy apparatus)', 'category' => 'Jacks & Stands', 'quantity' => 1, 'estimated_cost' => 1200, 'priority' => 7],
            ['project_name' => 'Jack Stands (6-ton pair)', 'category' => 'Jacks & Stands', 'quantity' => 2, 'estimated_cost' => 150, 'priority' => 8],
            ['project_name' => 'Jack Stands (25-ton pair)', 'category' => 'Jacks & Stands', 'quantity' => 2, 'estimated_cost' => 500, 'priority' => 9],
            ['project_name' => 'Bottle Jacks (assorted capacities)', 'category' => 'Jacks & Stands', 'quantity' => 4, 'estimated_cost' => 300, 'priority' => 10],
            
            // Tools & Maintenance Equipment
            ['project_name' => 'Pneumatic Impact Wrench Set', 'category' => 'Tools & Maintenance Equipment', 'quantity' => 1, 'estimated_cost' => 800, 'priority' => 11],
            ['project_name' => 'Brake Lathe', 'category' => 'Tools & Maintenance Equipment', 'quantity' => 1, 'estimated_cost' => 5000, 'priority' => 12],
            ['project_name' => 'Tire Changer (Heavy-duty)', 'category' => 'Tools & Maintenance Equipment', 'quantity' => 1, 'estimated_cost' => 8000, 'priority' => 13],
            ['project_name' => 'Wheel Balancer', 'category' => 'Tools & Maintenance Equipment', 'quantity' => 1, 'estimated_cost' => 3500, 'priority' => 14],
            ['project_name' => 'Parts Washer', 'category' => 'Tools & Maintenance Equipment', 'quantity' => 1, 'estimated_cost' => 1500, 'priority' => 15],
            ['project_name' => 'Diagnostic Scanner (Heavy-Duty Commercial)', 'category' => 'Tools & Maintenance Equipment', 'quantity' => 1, 'estimated_cost' => 6000, 'priority' => 16],
        ];

        foreach ($items as $item) {
            ShopWork::create([
                'project_name' => $item['project_name'],
                'category' => $item['category'],
                'quantity' => $item['quantity'],
                'estimated_cost' => $item['estimated_cost'],
                'priority' => $item['priority'],
                'status' => 'Pending',
                'description' => null,
                'notes' => null,
            ]);
        }
    }
}
