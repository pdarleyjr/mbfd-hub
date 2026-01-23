<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\CapitalProject;
use App\Models\ProjectMilestone;
use App\Enums\ProjectStatus;
use App\Enums\ProjectPriority;
use Carbon\Carbon;

class CapitalProjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing projects
        CapitalProject::query()->delete();
        
        $projects = [
            [
                'project_number' => '66727',
                'name' => 'FIRE STATION #4 – REPL. EXHAUST SYS',
                'description' => 'Replacement of vehicle exhaust system at Fire Station #4 to improve air quality and safety. Includes removal of old system, installation of new exhaust extraction equipment, and testing.',
                'budget_amount' => 22946.00,
                'priority' => ProjectPriority::Medium,
                'start_date' => Carbon::now()->addWeeks(2),
                'target_completion_date' => Carbon::now()->addMonths(6),
            ],
            [
                'project_number' => '67927',
                'name' => 'FIRE STATION #1 – REPL. EXHAUST SYS',
                'description' => 'Complete replacement of the vehicle exhaust extraction system at Fire Station #1. This is a high-priority project to ensure firefighter health and safety by eliminating diesel exhaust exposure in the apparatus bay.',
                'budget_amount' => 285000.00,
                'priority' => ProjectPriority::High,
                'start_date' => Carbon::now()->addMonth(),
                'target_completion_date' => Carbon::now()->addMonths(9),
            ],
            [
                'project_number' => '63631',
                'name' => 'FIRE STATION #2 – RESTROOM/PLUMBING',
                'description' => 'Major renovation of restroom facilities and plumbing infrastructure at Fire Station #2. Includes replacement of aging pipes, fixtures, ADA-compliant upgrades, and modernization of facilities.',
                'budget_amount' => 255000.00,
                'priority' => ProjectPriority::High,
                'start_date' => Carbon::now()->addWeeks(3),
                'target_completion_date' => Carbon::now()->addMonths(10),
            ],
            [
                'project_number' => '63731',
                'name' => 'FIRE STATION #4 – ROOF REPLACEMENT',
                'description' => 'Critical roof replacement project for Fire Station #4. The existing roof has reached end of life and requires complete replacement to prevent water damage and maintain structural integrity. Highest priority infrastructure project.',
                'budget_amount' => 357000.00,
                'priority' => ProjectPriority::Critical,
                'start_date' => Carbon::now(),
                'target_completion_date' => Carbon::now()->addMonths(12),
            ],
            [
                'project_number' => '65127',
                'name' => 'FIRE STATION #2 – REPL. EXHAUST SYS',
                'description' => 'Replacement of vehicle exhaust extraction system at Fire Station #2. Part of department-wide initiative to upgrade all station exhaust systems for improved air quality and firefighter health.',
                'budget_amount' => 200000.00,
                'priority' => ProjectPriority::Medium,
                'start_date' => Carbon::now()->addMonths(2),
                'target_completion_date' => Carbon::now()->addMonths(8),
            ],
            [
                'project_number' => '66527',
                'name' => 'FIRE STATION #3 – REPL. EXHAUST SYS',
                'description' => 'Replacement of vehicle exhaust extraction system at Fire Station #3. This project will eliminate diesel exhaust exposure in the apparatus bay and improve overall air quality for personnel.',
                'budget_amount' => 228000.00,
                'priority' => ProjectPriority::Medium,
                'start_date' => Carbon::now()->addMonths(2),
                'target_completion_date' => Carbon::now()->addMonths(8),
            ],
            [
                'project_number' => '66727-B',
                'name' => 'FIRE STATION #4 – REPL. EXHAUST SYS',
                'description' => 'Secondary exhaust system replacement project for Fire Station #4 apparatus bay expansion. Complements the initial exhaust system project to cover additional bays.',
                'budget_amount' => 177054.00,
                'priority' => ProjectPriority::Medium,
                'start_date' => Carbon::now()->addMonths(3),
                'target_completion_date' => Carbon::now()->addMonths(9),
            ],
            [
                'project_number' => '60626',
                'name' => 'FIRE STATION #2 – VEHICLE AWNING REPL',
                'description' => 'Replacement of vehicle awning structure at Fire Station #2. The existing awning provides weather protection for apparatus and personnel during vehicle operations. Project includes structural improvements and modern materials.',
                'budget_amount' => 237357.00,
                'priority' => ProjectPriority::High,
                'start_date' => Carbon::now()->addMonth(),
                'target_completion_date' => Carbon::now()->addMonths(10),
            ],
        ];

        foreach ($projects as $projectData) {
            $project = CapitalProject::create([
                'project_number' => $projectData['project_number'],
                'name' => $projectData['name'],
                'description' => $projectData['description'],
                'budget_amount' => $projectData['budget_amount'],
                'status' => ProjectStatus::Pending,
                'priority' => $projectData['priority'],
                'start_date' => $projectData['start_date'],
                'target_completion_date' => $projectData['target_completion_date'],
            ]);

            // Add milestones for high-priority and critical projects
            if (in_array($projectData['project_number'], ['67927', '63631', '63731'])) {
                $this->createMilestones($project);
            }
        }

        $this->command->info('Successfully seeded ' . count($projects) . ' capital projects.');
    }

    private function createMilestones(CapitalProject $project): void
    {
        $startDate = $project->start_date;
        $endDate = $project->target_completion_date;
        $duration = $startDate->diffInMonths($endDate);

        $milestones = [
            [
                'name' => 'Design Phase Complete',
                'description' => 'Architectural and engineering designs finalized and approved.',
                'due_date' => $startDate->copy()->addMonths(max(1, round($duration * 0.2))),
            ],
            [
                'name' => 'Permits Obtained',
                'description' => 'All necessary building permits and approvals secured.',
                'due_date' => $startDate->copy()->addMonths(max(2, round($duration * 0.3))),
            ],
            [
                'name' => 'Construction Started',
                'description' => 'Construction phase begins with contractor mobilization.',
                'due_date' => $startDate->copy()->addMonths(max(3, round($duration * 0.4))),
            ],
            [
                'name' => 'Final Inspection',
                'description' => 'Final walkthrough and inspection completed, punch list items addressed.',
                'due_date' => $startDate->copy()->addMonths(max(4, round($duration * 0.9))),
            ],
        ];

        foreach ($milestones as $milestoneData) {
            ProjectMilestone::create([
                'capital_project_id' => $project->id,
                'title' => $milestoneData['name'],
                'description' => $milestoneData['description'],
                'due_date' => $milestoneData['due_date'],
                'completed' => false,
            ]);
        }
    }
}
