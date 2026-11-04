<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Livewire\Dashboards\DashboardBoard;
use App\Livewire\Dashboards\LatestActivity;
use App\Models\User;
use App\Services\Activity\OperationsActivityPreferences;
use App\Services\Activity\OperationsIssueSort;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueSourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Dashboards\FakeIssueSource;
use Tests\TestCase;

final class LatestActivitySortPreferenceTest extends TestCase
{
    use LatestActivityFixtures;
    use RefreshDatabase;

    private User $admin;

    private OperationsActivityPreferences $prefs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpActivity();
        $this->admin = $this->superAdmin();
        $this->actingAsCanonicalUser($this->admin);
        $this->withoutVite();
        $this->prefs = new OperationsActivityPreferences;
    }

    private function rowFor(User $user): ?object
    {
        return DB::table('user_dashboard_preferences')->where('user_id', $user->id)->first();
    }

    public function test_nothing_is_stored_until_someone_chooses_a_non_default_order(): void
    {
        $this->assertSame('newest_reported', $this->prefs->issueSort($this->admin));
        $this->assertSame('latest_update', $this->prefs->activitySort($this->admin));
        $this->assertNull($this->rowFor($this->admin));

        $this->prefs->saveIssueSort($this->admin, 'newest_reported');
        $this->prefs->saveActivitySort($this->admin, 'latest_update');
        $this->assertNull($this->rowFor($this->admin), 'the default never writes a row');
    }

    public function test_a_choice_is_saved_read_back_and_removed_when_it_returns_to_the_default(): void
    {
        $this->prefs->saveIssueSort($this->admin, 'oldest_unresolved');
        $this->assertSame('oldest_unresolved', $this->prefs->issueSort($this->admin));
        $this->assertSame('oldest_unresolved', $this->rowFor($this->admin)->issue_sort);
        $this->assertNull($this->rowFor($this->admin)->activity_sort);

        $this->prefs->saveIssueSort($this->admin, 'newest_reported');
        $this->assertNull($this->rowFor($this->admin), 'back to the default removes the row');
        $this->assertSame('newest_reported', $this->prefs->issueSort($this->admin));
    }

    public function test_the_two_choices_are_kept_apart_and_the_row_stays_while_either_is_not_default(): void
    {
        $this->prefs->saveIssueSort($this->admin, 'location');
        $this->prefs->saveActivitySort($this->admin, 'needs_action_first');
        $row = $this->rowFor($this->admin);
        $this->assertSame(['location', 'needs_action_first'], [$row->issue_sort, $row->activity_sort]);

        $this->prefs->saveActivitySort($this->admin, 'latest_update');
        $row = $this->rowFor($this->admin);
        $this->assertSame(['location', null], [$row->issue_sort, $row->activity_sort]);

        $this->prefs->saveIssueSort($this->admin, 'latest_update');
        $this->prefs->saveIssueSort($this->admin, 'newest_reported');
        $this->assertNull($this->rowFor($this->admin));
    }

    public function test_an_unknown_value_reads_and_saves_as_the_default(): void
    {
        $this->prefs->saveIssueSort($this->admin, 'sideways');
        $this->assertNull($this->rowFor($this->admin));

        DB::table('user_dashboard_preferences')->insert(['user_id' => $this->admin->id, 'issue_sort' => 'sideways', 'activity_sort' => 'odd', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('newest_reported', $this->prefs->issueSort($this->admin));
        $this->assertSame('latest_update', $this->prefs->activitySort($this->admin));
    }

    public function test_one_persons_choice_never_reaches_another(): void
    {
        $other = $this->reviewerAdmin();
        $this->prefs->saveIssueSort($this->admin, 'location');

        $this->assertSame('location', $this->prefs->issueSort($this->admin));
        $this->assertSame('newest_reported', $this->prefs->issueSort($other));
        $this->assertNull($this->rowFor($other));
    }

    public function test_the_overview_opens_with_the_saved_order_and_the_other_boards_ignore_it(): void
    {
        $this->prefs->saveIssueSort($this->admin, 'location');

        Livewire::test(DashboardBoard::class, ['dashboard' => 'overview'])->assertSet('issueSort', 'location');
        Livewire::test(DashboardBoard::class, ['dashboard' => 'vehicle-checkout'])->assertSet('issueSort', 'newest_reported');
    }

    public function test_choosing_an_order_on_the_overview_saves_it_and_choosing_the_default_clears_it(): void
    {
        $board = Livewire::test(DashboardBoard::class, ['dashboard' => 'overview']);

        $board->set('issueSort', 'oldest_unresolved');
        $this->assertSame('oldest_unresolved', $this->rowFor($this->admin)->issue_sort);

        $board->set('issueSort', 'newest_reported');
        $this->assertNull($this->rowFor($this->admin));

        $board->set('issueSort', 'sideways')->assertSet('issueSort', 'newest_reported');
        $this->assertNull($this->rowFor($this->admin));
    }

    public function test_the_other_boards_neither_show_the_select_nor_save_it(): void
    {
        Livewire::test(DashboardBoard::class, ['dashboard' => 'overview'])->assertSee('Sort lists by')->assertSee('Oldest unresolved');

        $vehicle = Livewire::test(DashboardBoard::class, ['dashboard' => 'vehicle-checkout']);
        $vehicle->assertDontSee('Sort lists by');
        $vehicle->set('issueSort', 'location');
        $this->assertNull($this->rowFor($this->admin));
    }

    /** @return list<string> issue ids in the order the vehicle lane lists them */
    private function laneOrder(string $sort): array
    {
        Cache::flush();
        $now = CarbonImmutable::now();
        $row = static fn (string $id, string $subject, string $severity, string $stage, CarbonImmutable $at) => FakeIssueSource::issue('fake_vehicle', $id, [
            'subject' => $subject, 'severity' => $severity, 'stage' => $stage, 'occurredAt' => $at,
        ]);
        $this->app->instance(IssueSourceRegistry::class, new IssueSourceRegistry([new FakeIssueSource('fake_vehicle', [DashboardView::Overview, DashboardView::VehicleCheckout], [
            $row('1', 'Rescue 2', 'low', 'awaiting_response', $now->subHour()),
            $row('2', 'Engine 1', 'high', 'awaiting_response', $now->subHours(5)),
            $row('3', 'Ladder 3', 'medium', 'awaiting_response', $now->subMinutes(10)),
            $row('4', 'Engine 10', 'low', 'resolved', $now->subMinutes(30)),
            $row('5', 'Engine 2', 'medium', 'in_progress', $now->subHours(3)),
        ])]));

        $html = Livewire::test(DashboardBoard::class, ['dashboard' => 'overview'])->set('issueSort', $sort)->html();
        preg_match_all('/data-issue-ref="fake_vehicle:(\d)"/', $html, $matches);

        return $matches[1];
    }

    public function test_every_order_lists_the_same_rows_and_only_changes_their_sequence(): void
    {
        $this->assertSame(['2', '3', '5', '1', '4'], $this->laneOrder('newest_reported'), 'default: open first, worst first, newest first');
        $this->assertSame(['3', '4', '1', '5', '2'], $this->laneOrder('latest_update'), 'purely by time, newest first');
        $this->assertSame(['2', '5', '1', '3', '4'], $this->laneOrder('oldest_unresolved'), 'open ones, longest waiting first, closed last');
        $this->assertSame(['2', '5', '3', '1', '4'], $this->laneOrder('highest_priority'), 'worst priority, then longest waiting');
        $this->assertSame(['2', '5', '4', '3', '1'], $this->laneOrder('location'), 'unit names in natural order');

        foreach (array_keys(OperationsIssueSort::OPTIONS) as $sort) {
            $order = $this->laneOrder($sort);
            sort($order);
            $this->assertSame(['1', '2', '3', '4', '5'], $order, $sort);
        }
    }

    public function test_the_activity_order_is_the_cards_order_too(): void
    {
        $defect = $this->defectIn('Fog nozzle');
        $this->defectEvent($defect, 'reported', [], 30);
        $this->defectEvent($defect, 'resolved', ['details' => ['resolution_notes' => 'Replaced']], 5);
        $this->prefs->saveActivitySort($this->admin, 'needs_action_first');
        $this->forgetFeeds();

        $html = Livewire::test(LatestActivity::class, ['filter' => 'all'])->html();

        $this->assertLessThan(strpos($html, '>Resolved</span>'), strpos($html, '>New issue</span>'), 'the issue that needs action comes before the newer resolution');
    }
}
