<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Workgroup\Pages\Links;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use App\Support\Workgroups\WorkgroupContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WorkgroupReportAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['workgroup.ai_worker_url' => 'https://workgroup-ai.example.test']);
        Http::fake();
    }

    public function test_static_ownerless_reports_require_explicit_global_access(): void
    {
        $member = User::factory()->create();
        $this->makeSession($member, 'A');

        foreach ([
            'workgroup.analysis-report',
            'workgroup.data-dashboard',
            'workgroup.l1-inventory',
            'workgroup.final-presentation',
            'workgroup.evaluation-report',
            'workgroup.final-recommendations',
            'workgroup.workgroup-summary',
        ] as $route) {
            $this->actingAs($member)
                ->get(route($route))
                ->assertNotFound();
        }

        $roleOnlyAdmin = User::factory()->create();
        $roleOnlyAdmin->assignRole(Role::findOrCreate('admin', 'web'));

        $this->actingAs($roleOnlyAdmin)
            ->get(route('workgroup.analysis-report'))
            ->assertNotFound();

        $globalViewer = User::factory()->create();
        $globalViewer->givePermissionTo(Permission::findOrCreate('workgroup.global_access', 'web'));

        $this->actingAs($globalViewer)
            ->get(route('workgroup.analysis-report'))
            ->assertOk();
    }

    public function test_links_page_hides_ownerless_reports_without_explicit_global_access(): void
    {
        $member = User::factory()->create();
        $this->makeSession($member, 'A');

        $this->actingAs($member);
        $this->assertFalse(Links::canAccess());

        $globalViewer = User::factory()->create();
        $globalViewer->givePermissionTo(Permission::findOrCreate('workgroup.global_access', 'web'));

        $this->actingAs($globalViewer);
        $this->assertTrue(Links::canAccess());
    }

    public function test_dynamic_report_routes_reject_cross_workgroup_and_missing_sessions(): void
    {
        $memberA = User::factory()->create();
        $sessionA = $this->makeSession($memberA, 'A');

        $memberB = User::factory()->create();
        $sessionB = $this->makeSession($memberB, 'B');

        Cache::put("workgroup_ai_exec_report_{$sessionB->id}", ['report' => '<p>Workgroup B executive report</p>']);
        Cache::put("workgroup_saver_report_{$sessionB->workgroup_id}_{$sessionB->id}", '<p>Workgroup B SAVER report</p>');

        $this->actingAs($memberA)
            ->get(route('workgroup.export.csv', ['tableKey' => 'finalists', 'session_id' => $sessionB->id]))
            ->assertNotFound();

        $this->actingAs($memberA)
            ->get(route('reports.executive.pdf', ['session_id' => $sessionB->id]))
            ->assertNotFound();

        $this->actingAs($memberA)
            ->get(route('reports.saver.pdf', ['session_id' => $sessionB->id]))
            ->assertNotFound();

        $this->actingAs($memberA)
            ->get(route('workgroup.saver-report', ['session_id' => $sessionB->id]))
            ->assertNotFound();

        $this->actingAs($memberA)
            ->get(route('workgroup.export.csv', ['tableKey' => 'finalists']))
            ->assertNotFound();

        $this->actingAs($memberA)
            ->get(route('workgroup.export.csv', ['tableKey' => 'unrecognized', 'session_id' => $sessionA->id]))
            ->assertNotFound();

        $this->actingAs($memberA)
            ->get(route('reports.executive.pdf'))
            ->assertNotFound();

        $roleOnlyAdmin = User::factory()->create();
        $roleOnlyAdmin->assignRole(Role::findOrCreate('admin', 'web'));

        $this->actingAs($roleOnlyAdmin)
            ->get(route('workgroup.export.csv', ['tableKey' => 'finalists', 'session_id' => $sessionA->id]))
            ->assertNotFound();

        $this->assertDatabaseHas('workgroup_sessions', ['id' => $sessionA->id]);
        Http::assertNothingSent();
    }

    public function test_dynamic_reports_require_the_active_workgroup_context_for_multi_workgroup_members(): void
    {
        $member = User::factory()->create();
        $sessionA = $this->makeSession($member, 'A');
        $sessionB = $this->makeSession($member, 'B');
        app(WorkgroupContext::class)->select($member, $sessionA->workgroup_id);

        Cache::put(
            "workgroup_saver_report_{$sessionB->workgroup_id}_{$sessionB->id}",
            '<p>Workgroup B session report</p>',
        );

        $this->actingAs($member)
            ->get(route('workgroup.saver-report', ['session_id' => $sessionB->id]))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_pdf_exports_do_not_generate_ai_content_on_get_cache_misses(): void
    {
        $member = User::factory()->create();
        $session = $this->makeSession($member, 'A');

        $this->actingAs($member)
            ->get(route('reports.executive.pdf', ['session_id' => $session->id]))
            ->assertNotFound();

        $this->actingAs($member)
            ->get(route('reports.saver.pdf', ['session_id' => $session->id]))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_authorized_session_can_read_a_cached_saver_report_without_ai_generation(): void
    {
        $member = User::factory()->create();
        $session = $this->makeSession($member, 'A');
        Cache::put(
            "workgroup_saver_report_{$session->workgroup_id}_{$session->id}",
            '<p>Cached session-specific SAVER report</p>',
        );

        $this->actingAs($member)
            ->get(route('workgroup.saver-report', ['session_id' => $session->id]))
            ->assertOk()
            ->assertSee('Cached session-specific SAVER report');

        Http::assertNothingSent();
    }

    public function test_authorized_session_can_download_cached_pdf_reports_without_ai_generation(): void
    {
        $member = User::factory()->create();
        $session = $this->makeSession($member, 'A');
        Cache::put("workgroup_ai_exec_report_{$session->id}", ['report' => '<h2>Cached executive report</h2>']);
        Cache::put("workgroup_saver_report_{$session->workgroup_id}_{$session->id}", '<h2>Cached SAVER report</h2>');

        $this->actingAs($member)
            ->get(route('reports.executive.pdf', ['session_id' => $session->id]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($member)
            ->get(route('reports.saver.pdf', ['session_id' => $session->id]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        Http::assertNothingSent();
    }

    private function makeSession(User $user, string $suffix): WorkgroupSession
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

        return WorkgroupSession::create([
            'workgroup_id' => $workgroup->id,
            'name' => 'Session '.$suffix,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'active',
        ]);
    }
}
