<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\InspectionResource;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Models\User;
use App\Support\HubNavigation;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Vehicle detail and checkout issue pages opened from the admin dashboard: an obvious Back that
 * returns to the originating page, a stable breadcrumb, a deterministic fallback to the right
 * list on a direct link or refresh, and no path into the admin panel for a non-admin.
 */
final class VehicleIssueBackNavigationTest extends TestCase
{
    use RefreshDatabase;

    private Apparatus $apparatus;

    private ApparatusInspection $inspection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $station = Station::query()->create(['station_number' => 1, 'name' => 'Station 1', 'address' => '1051 Jefferson Avenue', 'is_active' => true]);
        $this->apparatus = Apparatus::query()->create([
            'station_id' => $station->id, 'unit_id' => 'E1', 'name' => 'Engine E1', 'type' => 'Engine', 'vehicle_number' => '1001',
            'designation' => 'E1', 'slug' => Str::slug('engine-e1'), 'make' => 'Pierce', 'model' => 'Enforcer', 'year' => 2020,
            'status' => 'In Service', 'current_engine_hours' => 100.0, 'current_miles' => 10_000,
        ]);
        $this->inspection = ApparatusInspection::query()->create([
            'apparatus_id' => $this->apparatus->id, 'client_submission_id' => (string) Str::uuid(),
            'inspection_reference' => 'INS-BACKNAV', 'operator_name' => 'Pat Rivera', 'rank' => 'Lieutenant', 'shift' => 'A',
            'unit_number' => 'E1', 'vehicle_number' => '1001', 'designation_at_time' => 'E1',
            'review_status' => 'approved', 'processing_status' => 'accepted', 'engine_hours' => 101.5, 'miles' => 10010,
            'results' => [], 'completed_at' => now()->utc(),
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->create(['account_status' => 'active']);
        $admin->assignRole($role);
        $admin->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($admin->fresh());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function inspectionPath(): string
    {
        return '/admin/inspections/'.$this->inspection->id;
    }

    /** The href and label of the Back button rendered on the page. */
    private function backOf(string $html): array
    {
        $this->assertSame(1, preg_match('/<a[^>]*href="([^"]*)"[^>]*data-hub-back[^>]*>/s', $html, $href), 'a Back button is rendered');
        $this->assertSame(1, preg_match('/<a[^>]*data-hub-back[^>]*>.*?<span class="hub-back-label">([^<]*)<\/span>/s', $html, $label));

        return ['url' => html_entity_decode($href[1]), 'label' => trim($label[1])];
    }

    /** The visible breadcrumb trail, as text. */
    private function trail(string $html): string
    {
        $start = strpos($html, '<nav class="fi-breadcrumbs');
        $this->assertNotFalse($start, 'a breadcrumb is rendered');

        return trim((string) preg_replace('/\s+/', ' ', strip_tags(substr($html, $start, 5000))));
    }

    // ------------------------------------------------------------ withReturnTo

    public function test_with_return_to_puts_the_target_before_the_fragment_and_keeps_existing_state(): void
    {
        $this->assertSame('/admin/inspections/5?return_to=%2Fadmin#issue-9', HubNavigation::withReturnTo('/admin/inspections/5#issue-9', '/admin'));
        $this->assertSame('/admin/x?a=1&return_to=%2Fadmin', HubNavigation::withReturnTo('/admin/x?a=1', '/admin'));
        $this->assertSame('/admin/x?return_to=%2Fadmin%2Fdefects', HubNavigation::withReturnTo('/admin/x?return_to=%2Fadmin%2Fdefects', '/admin'), 'an explicit return_to is never overwritten');
        $this->assertSame('/admin/x', HubNavigation::withReturnTo('/admin/x', 'https://evil.example/'), 'an unsafe target leaves the link alone');
        $this->assertSame('/admin/x', HubNavigation::withReturnTo('/admin/x', '//evil.example'));
    }

    // ------------------------------------------------------------ the inspection (checkout issue) page

    public function test_opened_from_the_dashboard_back_returns_to_admin_and_the_breadcrumb_is_stable(): void
    {
        $html = $this->get($this->inspectionPath().'?return_to=%2Fadmin')->assertOk()->getContent();

        $this->assertSame(['url' => '/admin', 'label' => 'Back to Admin'], $this->backOf($html));
        $this->assertStringContainsString('fi-breadcrumbs', $html);
        $this->assertStringStartsWith('Admin Daily Checkouts Inspection INS-BACKNAV', $this->trail($html));
    }

    public function test_a_direct_link_or_refresh_falls_back_to_the_daily_checkouts_list(): void
    {
        $html = $this->get($this->inspectionPath())->assertOk()->getContent();
        $back = $this->backOf($html);

        $this->assertSame(InspectionResource::getUrl('index', isAbsolute: false), parse_url($back['url'], PHP_URL_PATH));
        $this->assertSame('Back to Daily Checkouts', $back['label']);

        // A refresh is the same request: same destination, no reliance on history.
        $this->assertSame($back, $this->backOf($this->get($this->inspectionPath())->assertOk()->getContent()));
    }

    public function test_back_keeps_the_filters_of_the_list_the_user_came_from(): void
    {
        $list = InspectionResource::getUrl('index', isAbsolute: false);
        $html = $this->withSession(['_previous' => ['url' => url($list.'?page=2&tableSearch=E1')]])
            ->get($this->inspectionPath())->assertOk()->getContent();

        $back = $this->backOf($html);
        $this->assertSame($list.'?page=2&tableSearch=E1', $back['url']);
        $this->assertSame('Back to Daily Checkouts', $back['label']);
    }

    public function test_an_unsafe_return_to_is_ignored_and_the_list_is_used(): void
    {
        $html = $this->get($this->inspectionPath().'?return_to='.rawurlencode('https://evil.example/admin'))->assertOk()->getContent();

        $back = $this->backOf($html);
        $this->assertStringNotContainsString('evil.example', $back['url']);
        $this->assertSame('Back to Daily Checkouts', $back['label']);
    }

    // ------------------------------------------------------------ the apparatus (vehicle) page

    public function test_the_vehicle_detail_opened_from_the_dashboard_goes_back_to_admin_and_falls_back_to_the_apparatus_list(): void
    {
        $path = ApparatusResource::getUrl('view', ['record' => $this->apparatus], isAbsolute: false);

        $fromBoard = $this->get($path.'?return_to=%2Fadmin')->assertOk()->getContent();
        $this->assertSame(['url' => '/admin', 'label' => 'Back to Admin'], $this->backOf($fromBoard));
        $this->assertStringStartsWith('Admin Fire Apparatus', $this->trail($fromBoard));

        $direct = $this->backOf($this->get($path)->assertOk()->getContent());
        $this->assertSame(ApparatusResource::getUrl('index', isAbsolute: false), parse_url($direct['url'], PHP_URL_PATH));
        $this->assertSame('Back to Fire Apparatus', $direct['label']);
    }

    // ------------------------------------------------------------ the dashboard links

    public function test_dashboard_board_links_to_vehicles_and_checkouts_carry_the_admin_return_target(): void
    {
        // The Station Operations board widget now lives on the Logistics Board; its links still return to /admin.
        $html = $this->get('/admin/logistics-board')->assertOk()->getContent();

        $this->assertStringContainsString($this->inspectionPath().'?return_to=%2Fadmin', html_entity_decode($html));
        $this->assertStringContainsString('/admin/apparatuses/'.$this->apparatus->getRouteKey().'?return_to=%2Fadmin', html_entity_decode($html));
    }

    // ------------------------------------------------------------ never into admin for a non-admin

    public function test_a_member_without_admin_access_is_not_routed_into_the_admin_panel_by_return_to(): void
    {
        $member = User::factory()->create(['account_status' => 'active']);
        $this->actingAs($member);

        $this->get($this->inspectionPath().'?return_to=%2Fadmin')->assertStatus(403);
        $this->get('/admin')->assertStatus(403);
    }
}
