<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Filament\Admin\Pages\OperationsActivity;
use App\Filament\Support\InspectionWorkspace;
use App\Livewire\Dashboards\DashboardBoard;
use App\Livewire\Dashboards\LatestActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

final class LatestActivityComponentTest extends TestCase
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

    private function card(string $filter = 'all'): Testable
    {
        $this->forgetFeeds();

        return Livewire::test(LatestActivity::class, ['filter' => $filter]);
    }

    public function test_the_overview_board_includes_the_card_and_the_other_boards_do_not(): void
    {
        $overview = Livewire::test(DashboardBoard::class, ['dashboard' => 'overview']);
        $overview->assertSeeHtml('data-card="latest-activity"')->assertSee('Latest activity')->assertSeeLivewire(LatestActivity::class);

        foreach (['vehicle-checkout', 'station-operations'] as $other) {
            Livewire::test(DashboardBoard::class, ['dashboard' => $other])
                ->assertDontSeeHtml('data-card="latest-activity"')
                ->assertDontSeeLivewire(LatestActivity::class);
        }
    }

    public function test_the_card_sits_between_the_chips_and_the_lanes_on_the_page(): void
    {
        $html = $this->get('/admin')->assertOk()->getContent();

        $chips = strpos($html, 'class="mbfd-dash-chips"');
        $card = strpos($html, 'data-card="latest-activity"');
        $lanes = strpos($html, 'class="mbfd-dash-body');
        $this->assertNotFalse($chips);
        $this->assertNotFalse($card);
        $this->assertNotFalse($lanes);
        $this->assertLessThan($card, $chips);
        $this->assertLessThan($lanes, $card);
    }

    public function test_display_mode_never_renders_the_card(): void
    {
        $this->defectEvent($this->defectIn(), 'reported', [], 5);

        $display = $this->get('/admin?display=1')->assertOk()->getContent();
        $this->assertStringNotContainsString('latest-activity', $display);
        $this->assertStringNotContainsString('Latest activity', $display);
        $this->assertStringNotContainsString('Riley Reporter', $display);

        $this->get('/admin')->assertOk()->assertSee('Latest activity');
    }

    public function test_rows_are_links_with_kind_actor_status_and_the_needs_action_badge(): void
    {
        $defect = $this->defectIn('Fog nozzle');
        $this->defectEvent($defect, 'reported', [], 5);

        $html = $this->card()->assertSee('New issue')->assertSee('Needs action')->assertSee('Riley Reporter')->assertSee('R2')
            ->assertSee('Missing: Fog nozzle')->assertSee('New — not yet acknowledged')->html();

        $url = InspectionWorkspace::url((int) $this->activityInspection->id, 'issue-'.$defect->id);
        $this->assertStringContainsString('<a href="'.e($url).'" class="mbfd-dash-activity__link">', $html);
        $this->assertStringContainsString('<time class="mbfd-dash-activity__time" datetime="', $html);
        $this->assertStringNotContainsString('type="submit"', $html, 'a read-only card');
        $this->assertStringNotContainsString('wire:submit', $html);
    }

    public function test_the_card_is_polled_while_visible(): void
    {
        $this->defectEvent($this->defectIn(), 'reported', [], 5);

        $this->card()->assertSeeHtml('wire:poll.30s.visible="tick"')->assertSeeHtml('data-activity-list');
    }

    public function test_an_empty_card_says_so_in_plain_words(): void
    {
        $this->card()->assertSee('No activity in the last 14 days.')
            ->call('setKind', 'needs_action')->assertSee('Nothing needs action right now.')
            ->call('setKind', 'messages')->assertSee('No messages in the last 14 days.');
    }

    public function test_the_chip_filter_decides_what_the_card_lists(): void
    {
        $this->defectEvent($this->defectIn('Fog nozzle'), 'reported', [], 10);
        $this->stationUpdate($this->stationRequest(['title' => 'Kitchen sink leak']), ['status' => 'pending'], 5);

        $this->card('all')->assertSee('Fog nozzle')->assertSee('Kitchen sink leak');
        $this->card('apparatus')->assertSee('Fog nozzle')->assertDontSee('Kitchen sink leak');
        $this->card('stations')->assertSee('Kitchen sink leak')->assertDontSee('Fog nozzle');
        $this->card('personnel')->assertDontSee('Fog nozzle')->assertDontSee('Kitchen sink leak');
        $this->card('rubbish')->assertSee('Fog nozzle')->assertSee('Kitchen sink leak');
    }

    public function test_the_overview_chip_in_the_url_reaches_the_card(): void
    {
        $this->defectEvent($this->defectIn('Fog nozzle'), 'reported', [], 10);
        $this->stationUpdate($this->stationRequest(['title' => 'Kitchen sink leak']), ['status' => 'pending'], 5);
        $this->forgetFeeds();

        Livewire::withQueryParams(['filter' => 'stations'])->test(DashboardBoard::class, ['dashboard' => 'overview'])
            ->assertSee('Kitchen sink leak')->assertDontSee('Fog nozzle');
    }

    public function test_the_mini_filter_shows_what_needs_action_or_only_messages(): void
    {
        $defect = $this->defectIn('Fog nozzle');
        $this->defectEvent($defect, 'reported', [], 20);
        $this->defectEvent($defect, 'acknowledged', ['member_message' => 'We have it on order'], 10);
        $this->issueMessage($defect, 'member', 'When does it arrive?', minutesAgo: 5);

        $card = $this->card();
        $card->assertSee('Needs action')->assertSee('· 2');
        $card->call('setKind', 'needs_action')->assertSee('New issue')->assertSee('Member message')->assertDontSee('Acknowledged')
            ->assertSeeHtml('aria-pressed="true"');
        $card->call('setKind', 'messages')->assertSee('Member message')->assertSee('Acknowledged')->assertDontSee('New issue');
        $card->call('setKind', 'nonsense')->assertSet('kind', 'all')->assertSee('New issue');
    }

    public function test_a_poll_is_skipped_while_focus_is_inside_the_list_and_applied_otherwise(): void
    {
        $defect = $this->defectIn('Fog nozzle');
        $this->defectEvent($defect, 'reported', [], 20);

        $paused = $this->card();
        $paused->set('paused', true);
        $this->defectEvent($defect, 'resolved', ['details' => ['resolution_notes' => 'Replaced from stock']], 1);
        $this->forgetFeeds();
        $paused->call('tick')->assertDontSee('Replaced from stock');

        $active = $this->card();
        $this->defectEvent($defect, 'reopened', ['details' => ['reason' => 'Nozzle cracked again']], 1);
        $this->forgetFeeds();
        $active->call('tick')->assertSee('Nozzle cracked again');
    }

    public function test_the_focus_handlers_are_on_the_list_and_not_on_the_controls(): void
    {
        $this->defectEvent($this->defectIn(), 'reported', [], 5);

        $html = $this->card()->html();

        $this->assertMatchesRegularExpression('#<ul[^>]*data-activity-list[^>]*x-on:focusin="\$wire\.paused = true"[^>]*x-on:focusout="if \(! \$el\.contains\(\$event\.relatedTarget\)\) \$wire\.paused = false"#s', $html);
        $this->assertSame(1, substr_count($html, 'x-on:focusin'));
    }

    public function test_people_without_the_admin_panel_cannot_load_the_card(): void
    {
        $this->actingAsCanonicalUser($this->plainMember());

        Livewire::test(LatestActivity::class, ['filter' => 'all'])->assertForbidden();
    }

    public function test_a_domain_the_user_cannot_see_is_not_on_their_card(): void
    {
        $this->defectEvent($this->defectIn('Fog nozzle'), 'reported', [], 10);
        $this->stationUpdate($this->stationRequest(['title' => 'Kitchen sink leak']), ['status' => 'pending'], 5);
        $this->actingAsCanonicalUser($this->adminCan(['admin.stations.view']));

        $this->card()->assertSee('Kitchen sink leak')->assertDontSee('Fog nozzle');
    }

    public function test_view_all_activity_links_to_the_history_page_with_the_current_chip(): void
    {
        $this->card('stations')->assertSee('View all activity')
            ->assertSeeHtml('href="'.e(OperationsActivity::getUrl(['area' => 'stations'], panel: 'admin')).'"');

        $this->actingAsCanonicalUser($this->adminCan([]));
        $this->assertFalse(OperationsActivity::canAccess());
        $this->card()->assertDontSee('View all activity');
    }

    public function test_the_card_query_count_does_not_grow_with_the_number_of_events(): void
    {
        $this->seedEvents(3);
        $this->queriesForCard(); // the first load in a request also reads roles and permissions once
        $few = $this->queriesForCard();

        $this->seedEvents(22);
        $many = $this->queriesForCard();

        $this->assertSame($few, $many);
        $this->assertLessThanOrEqual(30, $many);
    }

    public function test_the_board_still_counts_the_same_queries_with_the_card_beside_it(): void
    {
        Livewire::test(DashboardBoard::class, ['dashboard' => 'overview']);
        $this->seedEvents(3);
        $this->forgetFeeds();
        $few = $this->queriesFor(fn () => Livewire::test(DashboardBoard::class, ['dashboard' => 'overview'])->call('refresh'));
        $this->seedEvents(22);
        $this->forgetFeeds();
        $many = $this->queriesFor(fn () => Livewire::test(DashboardBoard::class, ['dashboard' => 'overview'])->call('refresh'));

        $this->assertSame($few, $many);
    }

    private function queriesForCard(): int
    {
        $this->forgetFeeds();
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(LatestActivity::class, ['filter' => 'all']);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** Events in every domain: defect events, issue messages, station updates, personnel updates and a conversation. */
    private function seedEvents(int $each): void
    {
        for ($i = 0; $i < $each; $i++) {
            $defect = $this->defectIn('Item '.$this->fixtureCounter.'-'.$i, $i % 2 === 0 ? 'captain5' : null);
            $this->defectEvent($defect, 'reported', [], 100 + $i);
            $this->issueMessage($defect, 'member', 'Question '.$i, minutesAgo: 90 + $i);
            $this->stationUpdate($this->stationRequest(['title' => 'Request '.$this->fixtureCounter.'-'.$i]), ['status' => 'pending'], 80 + $i);
            $this->personnelUpdate($this->personnelRequest('UR-'.$this->fixtureCounter.'-'.$i), 'submitted', [], 70 + $i);
            $this->memberSends($this->messagingMember('Member '.$this->fixtureCounter.'-'.$i), 'Hello '.$i);
        }
    }
}
