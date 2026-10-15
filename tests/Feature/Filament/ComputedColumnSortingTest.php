<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\AccountStatus;
use App\Filament\Resources\CapitalProjectResource\Pages\ListCapitalProjects;
use App\Filament\Resources\Workgroup\Pages\ListEvaluationSubmissions;
use App\Models\CandidateProduct;
use App\Models\CapitalProject;
use App\Models\EvaluationCategory;
use App\Models\EvaluationSubmission;
use App\Models\ProjectMilestone;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sortable table columns backed by accessors have no database column; sorting
 * them must use an explicit query instead of failing with an SQL error.
 */
final class ComputedColumnSortingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = User::factory()->create(['account_status' => AccountStatus::Active, 'is_admin' => true]);
        $admin->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web']));
        Gate::before(fn (User $user): ?bool => $user->hasRole('super_admin') ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_capital_project_progress_sorts_by_completed_milestone_ratio(): void
    {
        $none = CapitalProject::factory()->create(['name' => 'No milestones']);
        $half = $this->projectWithMilestones('Half complete', completed: 1, open: 1);
        $done = $this->projectWithMilestones('All complete', completed: 2, open: 0);

        Livewire::test(ListCapitalProjects::class)
            ->loadTable()
            ->sortTable('completion_percentage')
            ->assertCanSeeTableRecords([$none, $half, $done], inOrder: true)
            ->sortTable('completion_percentage', 'desc')
            ->assertCanSeeTableRecords([$done, $half, $none], inOrder: true)
            ->assertSee('50%');
    }

    public function test_capital_project_progress_does_not_query_per_row(): void
    {
        foreach (range(1, 6) as $index) {
            $this->projectWithMilestones("Project {$index}", completed: 1, open: 2);
        }

        DB::enableQueryLog();
        Livewire::test(ListCapitalProjects::class)->loadTable()->assertSee('33.33%');
        $milestoneQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'project_milestones'));

        $this->assertLessThanOrEqual(2, $milestoneQueries->count(), 'Progress must come from one aggregate query, not per row.');
    }

    public function test_evaluation_submission_score_sorts_by_stored_overall_score(): void
    {
        $user = auth()->user();
        $workgroup = Workgroup::create(['name' => 'Sort fixture workgroup', 'created_by' => $user->id]);
        $member = WorkgroupMember::create(['workgroup_id' => $workgroup->id, 'user_id' => $user->id, 'role' => 'facilitator', 'is_active' => true]);
        $session = WorkgroupSession::create([
            'workgroup_id' => $workgroup->id, 'name' => 'Sort fixture session', 'status' => 'active',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
        ]);
        $category = EvaluationCategory::firstOrCreate(['name' => 'Sort fixture category'], ['is_active' => true, 'is_rankable' => true]);
        $submissions = collect([72.5, 91.25, 40.0])->map(function (float $score, int $index) use ($session, $category, $member): EvaluationSubmission {
            $product = CandidateProduct::create(['workgroup_session_id' => $session->id, 'category_id' => $category->id, 'name' => "Product {$index}"]);

            return EvaluationSubmission::create([
                'workgroup_member_id' => $member->id, 'candidate_product_id' => $product->id,
                'status' => 'submitted', 'overall_score' => $score,
            ]);
        });

        Livewire::test(ListEvaluationSubmissions::class)
            ->sortTable('total_score')
            ->assertCanSeeTableRecords([$submissions[2], $submissions[0], $submissions[1]], inOrder: true)
            ->sortTable('total_score', 'desc')
            ->assertCanSeeTableRecords([$submissions[1], $submissions[0], $submissions[2]], inOrder: true);
    }

    private function projectWithMilestones(string $name, int $completed, int $open): CapitalProject
    {
        $project = CapitalProject::factory()->create(['name' => $name]);
        foreach (range(1, $completed + $open) as $index) {
            ProjectMilestone::query()->create([
                'capital_project_id' => $project->id,
                'title' => "Milestone {$index}",
                'due_date' => now()->addMonth()->toDateString(),
                'completed' => $index <= $completed,
            ]);
        }

        return $project;
    }
}
