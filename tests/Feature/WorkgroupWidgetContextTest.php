<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Workgroup\Widgets\CategoryRankingsWidget;
use App\Filament\Workgroup\Widgets\FinalistsWidget;
use App\Filament\Workgroup\Widgets\SessionProgressWidget;
use App\Filament\Workgroup\Widgets\WorkgroupStatsWidget;
use App\Models\CandidateProduct;
use App\Models\EvaluationCategory;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use App\Support\Workgroups\WorkgroupContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkgroupWidgetContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_dashboard_widgets_use_the_selected_workgroup_context(): void
    {
        $user = User::factory()->create();
        $contextB = $this->makeContext($user, 'B');
        $contextA = $this->makeContext($user, 'A');

        app(WorkgroupContext::class)->select($user, $contextA['workgroup']->id);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        Livewire::test(WorkgroupStatsWidget::class)
            ->assertSee('Workgroup A')
            ->assertDontSee('Workgroup B');

        Livewire::test(SessionProgressWidget::class)
            ->assertSee('Session A')
            ->assertDontSee('Session B');

        Livewire::test(FinalistsWidget::class)
            ->assertSee('Product A')
            ->assertDontSee('Product B');

        Livewire::test(CategoryRankingsWidget::class)
            ->assertSee('Product A')
            ->assertDontSee('Product B');

        $this->assertNotSame($contextA['session']->id, $contextB['session']->id);
    }

    /** @return array{workgroup: Workgroup, session: WorkgroupSession} */
    private function makeContext(User $user, string $suffix): array
    {
        $workgroup = Workgroup::create([
            'name' => 'Workgroup '.$suffix,
            'created_by' => $user->id,
        ]);
        WorkgroupMember::create([
            'workgroup_id' => $workgroup->id,
            'user_id' => $user->id,
            'role' => 'member',
            'is_active' => true,
        ]);
        $session = WorkgroupSession::create([
            'workgroup_id' => $workgroup->id,
            'name' => 'Session '.$suffix,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'active',
        ]);
        $category = EvaluationCategory::firstOrCreate([
            'name' => 'Category '.$suffix,
        ], [
            'is_active' => true,
            'is_rankable' => true,
        ]);
        CandidateProduct::create([
            'workgroup_session_id' => $session->id,
            'category_id' => $category->id,
            'name' => 'Product '.$suffix,
        ]);

        return compact('workgroup', 'session');
    }
}
