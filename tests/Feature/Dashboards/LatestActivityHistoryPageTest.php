<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Filament\Admin\Pages\OperationsActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class LatestActivityHistoryPageTest extends TestCase
{
    use LatestActivityFixtures;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpActivity();
        $this->admin = $this->superAdmin();
        $this->actingAsCanonicalUser($this->admin);
        $this->withoutVite();
    }

    public function test_the_page_is_a_registered_admin_page_in_the_operations_group(): void
    {
        $this->assertSame('/admin/activity-history', parse_url(OperationsActivity::getUrl(panel: 'admin'), PHP_URL_PATH));
        $this->assertSame('Operations', OperationsActivity::getNavigationGroup());
        $this->assertSame('Activity history', OperationsActivity::getNavigationLabel());

        $this->get('/admin/activity-history')->assertOk()->assertSee('Activity history');
    }

    public function test_only_people_who_can_see_some_activity_may_open_it(): void
    {
        $this->actingAsCanonicalUser($this->adminCan([]));
        $this->get('/admin/activity-history')->assertForbidden();
        Livewire::test(OperationsActivity::class)->assertForbidden();

        $this->actingAsCanonicalUser($this->plainMember());
        $this->assertFalse(OperationsActivity::canAccess());

        $this->actingAsCanonicalUser($this->adminCan(['admin.stations.view']));
        $this->assertTrue(OperationsActivity::canAccess());
        $this->get('/admin/activity-history')->assertOk();
    }

    public function test_it_lists_thirty_days_newest_first_and_only_what_the_person_may_see(): void
    {
        $this->defectEvent($this->defectIn('Fog nozzle'), 'reported', [], 60 * 24 * 25);
        $this->stationUpdate($this->stationRequest(['title' => 'Kitchen sink leak']), ['status' => 'pending'], 10);

        $html = Livewire::test(OperationsActivity::class)->assertSee('2 events')->html();
        $this->assertLessThan(strpos($html, 'Fog nozzle'), strpos($html, 'Kitchen sink leak'));

        $this->actingAsCanonicalUser($this->adminCan(['admin.stations.view']));
        Livewire::test(OperationsActivity::class)->assertSee('Kitchen sink leak')->assertDontSee('Fog nozzle')->assertSee('1 event');
    }

    public function test_search_area_and_show_narrow_the_list(): void
    {
        $this->defectEvent($this->defectIn('Fog nozzle'), 'reported', [], 20);
        $this->stationUpdate($this->stationRequest(['title' => 'Kitchen sink leak']), ['status' => 'pending'], 10);
        $this->issueMessage($this->defectIn('Gated wye'), 'member', 'Where is the replacement?', minutesAgo: 5);

        $page = Livewire::test(OperationsActivity::class);
        $page->set('search', 'sink')->assertSee('Kitchen sink leak')->assertDontSee('Fog nozzle');
        $page->set('search', 'nothing like this')->assertSee('Nothing matches.');
        $page->set('search', '')->set('area', 'apparatus')->assertSee('Fog nozzle')->assertDontSee('Kitchen sink leak');
        $page->set('area', 'all')->set('kind', 'messages')->assertSee('Where is the replacement?')->assertDontSee('Fog nozzle');
        $page->set('kind', 'needs_action')->assertSee('Fog nozzle')->assertSee('Kitchen sink leak');
        $page->set('area', 'bogus')->assertSet('area', 'all');
    }

    public function test_the_filters_come_from_the_link_on_the_overview(): void
    {
        $this->defectEvent($this->defectIn('Fog nozzle'), 'reported', [], 20);
        $this->stationUpdate($this->stationRequest(['title' => 'Kitchen sink leak']), ['status' => 'pending'], 10);

        Livewire::withQueryParams(['area' => 'stations'])->test(OperationsActivity::class)
            ->assertSet('area', 'stations')->assertSee('Kitchen sink leak')->assertDontSee('Fog nozzle');
    }

    public function test_it_pages_fifty_at_a_time(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->stationUpdate($this->stationRequest(['title' => 'Request number '.$i]), ['status' => 'pending'], $i);
        }

        $page = Livewire::test(OperationsActivity::class)->assertSee('55 events');
        $this->assertSame(50, substr_count($page->html(), 'class="mbfd-dash-activity__item"'));
        $page->assertSee('Request number 1')->assertDontSee('Request number 55');

        $page->call('gotoPage', 2);
        $this->assertSame(5, substr_count($page->html(), 'class="mbfd-dash-activity__item"'));
        $page->assertSee('Request number 55');
    }

    public function test_the_order_select_saves_the_activity_order_and_the_default_clears_it(): void
    {
        $defect = $this->defectIn('Fog nozzle');
        $this->defectEvent($defect, 'reported', [], 30);
        $this->defectEvent($defect, 'resolved', ['details' => ['resolution_notes' => 'Replaced']], 5);

        $page = Livewire::test(OperationsActivity::class);
        $html = $page->html();
        $this->assertLessThan(strpos($html, '>New issue</span>'), strpos($html, '>Resolved</span>'), 'newest first by default');

        $page->set('sort', 'needs_action_first');
        $this->assertSame('needs_action_first', DB::table('user_dashboard_preferences')->where('user_id', $this->admin->id)->value('activity_sort'));
        $html = $page->html();
        $this->assertLessThan(strpos($html, '>Resolved</span>'), strpos($html, '>New issue</span>'));

        $page->set('sort', 'latest_update');
        $this->assertSame(0, DB::table('user_dashboard_preferences')->where('user_id', $this->admin->id)->count());
    }

    public function test_rows_are_the_same_links_as_the_card_and_change_nothing(): void
    {
        $this->defectEvent($this->defectIn('Fog nozzle'), 'reported', [], 5);

        $html = Livewire::test(OperationsActivity::class)->html();

        $this->assertStringContainsString('class="mbfd-dash-activity__link"', $html);
        $this->assertStringNotContainsString('wire:click="delete', $html);
        $this->assertStringNotContainsString('type="submit"', $html);
    }
}
