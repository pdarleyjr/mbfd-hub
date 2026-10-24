<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CapitalProject;
use App\Models\Under25kProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Project status values are lower-case snake_case (`completed`, `on_hold`) in the enum
 * and the admin form, but older rows and imports hold 'Completed' / 'On Hold'.
 */
final class ProjectStatusCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_under_25k_active_scope_excludes_completed_projects_in_either_case(): void
    {
        $statuses = ['Completed', 'completed', 'COMPLETED', 'On Hold', 'on_hold', 'in_progress', 'Planning', null];
        foreach ($statuses as $index => $status) {
            Under25kProject::query()->create(['project_number' => 'FIRE-'.$index, 'name' => 'Fixture '.$index, 'status' => $status]);
        }

        $active = Under25kProject::query()->active()->orderBy('project_number')->pluck('status')->all();

        $this->assertSame(['On Hold', 'on_hold', 'in_progress', 'Planning', null], $active);
    }

    public function test_overdue_check_skips_completed_and_on_hold_capital_projects(): void
    {
        $overdue = ['target_completion_date' => now()->subWeek(), 'actual_completion' => null];
        CapitalProject::factory()->create(['status' => 'in_progress', 'name' => 'Late and active'] + $overdue);
        CapitalProject::factory()->create(['status' => 'on_hold', 'name' => 'Late but paused'] + $overdue);
        CapitalProject::factory()->create(['status' => 'completed', 'name' => 'Late but done'] + $overdue);

        $this->artisan('projects:check-overdue')
            ->expectsOutputToContain('Found 1 overdue projects')
            ->assertSuccessful();
    }
}
