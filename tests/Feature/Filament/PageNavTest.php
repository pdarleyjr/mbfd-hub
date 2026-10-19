<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Employee\Pages\MyVehicleInspections;
use App\Filament\Employee\Pages\MyVehicleInspectionView;
use App\Filament\Employee\Pages\VideoConferencing;
use App\Filament\Pages\Dashboard as AdminDashboard;
use App\Filament\Resources\StationResource\Pages\EditStation;
use App\Filament\Resources\StationResource\Pages\ListStations;
use App\Filament\Support\PageNav;
use App\Filament\Training\Pages\Dashboard as TrainingDashboard;
use App\Models\Station;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Shared page navigation contract: Back + Hub Home everywhere, plus Admin Home in the Admin panel. */
final class PageNavTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $actor = User::factory()->create(['account_status' => 'active']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($actor);
    }

    public function test_admin_dashboard_list_and_resource_pages_render_back_admin_home_and_hub_home(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $station = Station::query()->create(['station_number' => 1, 'name' => 'Station 1', 'address' => '1 Main St', 'city' => 'Miami Beach', 'state' => 'FL', 'zip_code' => '33139', 'is_active' => true]);

        foreach ([
            [AdminDashboard::class, []],
            [ListStations::class, []],
            [EditStation::class, ['record' => $station->id]],
        ] as [$page, $params]) {
            $html = Livewire::test($page, $params)->html();
            $this->assertStringContainsString('data-hub-page-nav', $html, $page);
            $this->assertStringContainsString('data-hub-back', $html, $page);
            // Filament renders valueless data attributes as name="name".
            $this->assertSame(1, substr_count($html, 'data-hub-admin-home="'), $page);
            $this->assertSame(1, substr_count($html, 'data-hub-home="'), $page);
            $this->assertSame(1, substr_count($html, 'data-hub-page-nav'), $page);
            $this->assertStringContainsString('aria-label="Admin Home"', $html, $page);
            $this->assertStringContainsString('aria-label="Hub Home"', $html, $page);
        }
    }

    public function test_back_never_points_at_the_current_page_and_parents_are_logical(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $dashboard = PageNav::resolve([AdminDashboard::class]);
        $this->assertSame('/', $dashboard['back']['url']);
        $this->assertSame('Back', $dashboard['back']['label']);
        $this->assertSame('/admin', $dashboard['adminHome']);
        $this->assertSame('/', $dashboard['hubHome']);

        $list = PageNav::resolve([ListStations::class]);
        $this->assertSame('/admin', $list['back']['url']);
    }

    public function test_non_admin_panels_get_back_and_hub_home_without_admin_home(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('training'));
        $nav = PageNav::resolve([TrainingDashboard::class]);

        $this->assertNull($nav['adminHome']);
        $this->assertSame('/', $nav['hubHome']);
        $this->assertSame('/', $nav['back']['url']);
        $this->assertStringNotContainsString('data-hub-admin-home', view('filament.partials.page-nav', ['nav' => $nav])->render());
    }

    public function test_a_child_page_declares_its_parent_so_back_returns_to_the_list_not_the_dashboard(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        $this->requestAt('/employee/my-vehicle-inspections/5');

        $nav = PageNav::resolve([MyVehicleInspectionView::class]);

        $this->assertSame('/employee/my-vehicle-inspections', $nav['back']['url']);
        $this->assertSame('Back to my inspections', $nav['back']['label']);
        $this->assertNotSame('/employee/dashboard', $nav['back']['url']);
        $this->assertSame('/employee/my-vehicle-inspections', MyVehicleInspectionView::navigationParentPath());
        $this->assertSame('/employee/my-vehicle-inspections', parse_url(MyVehicleInspections::getUrl(panel: 'employee'), PHP_URL_PATH));
    }

    public function test_a_validated_return_to_restores_the_list_filters_and_page_and_unsafe_ones_fall_back_to_the_parent(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        $list = '/employee/my-vehicle-inspections?tableFilters[review_status][value]=pending_review&page=2';

        $this->requestAt('/employee/my-vehicle-inspections/5?return_to='.rawurlencode($list));
        $this->assertSame($list, PageNav::resolve([MyVehicleInspectionView::class])['back']['url']);
        $this->assertSame('Back to my inspections', PageNav::resolve([MyVehicleInspectionView::class])['back']['label']);

        foreach (['https://evil.test/employee/dashboard', '//evil.test', 'javascript:alert(1)', '/employee/my-vehicle-inspections/5'] as $unsafe) {
            $this->requestAt('/employee/my-vehicle-inspections/5?return_to='.rawurlencode($unsafe));
            $this->assertSame('/employee/my-vehicle-inspections', PageNav::resolve([MyVehicleInspectionView::class])['back']['url'], $unsafe);
        }
    }

    public function test_pages_that_do_not_declare_a_parent_keep_the_existing_employee_and_panel_behaviour(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        $this->requestAt('/employee/video-conferencing');

        $this->assertSame('/employee/dashboard', PageNav::resolve([VideoConferencing::class])['back']['url']);
    }

    private function requestAt(string $uri): void
    {
        $request = Request::create('http://localhost'.$uri);
        $request->setLaravelSession($this->app['session.store']);
        $this->app->instance('request', $request);
    }

    public function test_excluded_and_guest_contexts_render_nothing(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        auth('web')->logout();

        $this->assertNull(PageNav::resolve([AdminDashboard::class]));
        $this->assertSame('', trim(view('filament.partials.page-nav', ['nav' => null])->render()));
    }
}
