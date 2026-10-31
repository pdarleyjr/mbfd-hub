<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Filament\Pages\IssueGroupPage;
use App\Livewire\Dashboards\DashboardBoard;
use App\Livewire\Dashboards\IssueGroupBoard;
use App\Models\User;
use App\Services\Dashboards\DashboardReadModel;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueAction;
use App\Support\Dashboards\IssueDetail;
use App\Support\Dashboards\IssueSourceRegistry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Dashboards\FakeIssueSource;
use Tests\TestCase;

final class IssueGroupBoardTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = 'apparatus:2';

    private FakeIssueSource $source;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_status' => 'active']);
        $this->admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        $rows = [
            FakeIssueSource::issue('fake_vehicle', '1', ['subject' => 'E 2', 'title' => 'Missing: Fog Nozzle', 'version' => 'v1'])->inGroup(self::GROUP),
            FakeIssueSource::issue('fake_vehicle', '2', ['subject' => 'E 2', 'title' => 'Missing: Gated Wye', 'version' => 'v1'])->inGroup(self::GROUP),
            FakeIssueSource::issue('fake_vehicle', '3', ['subject' => 'E 2', 'title' => 'Closed earlier', 'stage' => 'resolved', 'version' => 'v1'])->inGroup(self::GROUP),
            FakeIssueSource::issue('fake_vehicle', '4', ['subject' => 'R 1', 'title' => 'Another unit', 'version' => 'v1'])->inGroup('apparatus:9'),
        ];
        $this->source = new FakeIssueSource('fake_vehicle', [DashboardView::Overview, DashboardView::VehicleCheckout], $rows);
        foreach ($rows as $row) {
            $this->source->details[$row->id] = new IssueDetail(
                issue: $row,
                body: null,
                facts: [],
                timeline: [],
                actions: $row->id === '3' ? [] : [
                    new IssueAction('acknowledge', 'Acknowledge', 'primary', [['name' => 'member_message', 'label' => 'Message', 'type' => 'textarea', 'required' => false, 'max' => 50]], bulk: IssueAction::BULK_DIRECT),
                    new IssueAction('resolve', 'Resolve', 'primary', [['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => true, 'max' => 50]], bulk: IssueAction::BULK_FORM),
                    new IssueAction('verify', 'Verify', 'primary'),
                ],
            );
        }
        $this->app->instance(IssueSourceRegistry::class, new IssueSourceRegistry([$this->source]));
    }

    private function board(): Testable
    {
        return Livewire::test(IssueGroupBoard::class, ['group' => self::GROUP]);
    }

    public function test_the_page_opens_for_a_valid_group_and_refuses_anything_else(): void
    {
        $this->get(IssueGroupPage::getUrl(['group' => self::GROUP], panel: 'admin'))->assertOk()->assertSee('E 2')->assertSee('Missing: Fog Nozzle');
        $this->get(IssueGroupPage::getUrl(['group' => 'apparatus:1 OR 1=1'], panel: 'admin'))->assertNotFound();
        $this->get(IssueGroupPage::getUrl(panel: 'admin'))->assertNotFound();
    }

    public function test_a_member_without_panel_access_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->create(['account_status' => 'active']));

        $this->get(IssueGroupPage::getUrl(['group' => self::GROUP], panel: 'admin'))->assertForbidden();
    }

    public function test_it_lists_only_this_units_issues_selects_the_open_ones_and_offers_only_bulk_actions(): void
    {
        $board = $this->board();

        $board->assertSee('Missing: Fog Nozzle')->assertSee('Missing: Gated Wye')->assertSee('Closed earlier')->assertDontSee('Another unit');
        $this->assertSame(['fake_vehicle:1', 'fake_vehicle:2'], $board->get('selected'));
        $board->assertSee('Acknowledge 2');
        $this->assertStringNotContainsString('data-bulk-action="verify"', $board->html());
        $this->assertStringNotContainsString('data-bulk-open="verify"', $board->html());
    }

    public function test_acknowledge_goes_to_every_selected_issue_with_the_version_shown(): void
    {
        $board = $this->board();

        $board->call('apply', 'fake_vehicle', 'acknowledge', $board->get('token'));

        $this->assertSame(['1', '2'], array_column($this->source->performed, 'id'));
        $this->assertSame(['v1', 'v1'], array_column($this->source->performed, 'version'));
        $this->assertSame('success', $board->get('notice')['tone']);
    }

    public function test_the_one_click_button_never_sends_text_left_in_a_closed_form(): void
    {
        $board = $this->board()->set('input.fake_vehicle.acknowledge.member_message', 'Half written');

        $board->call('apply', 'fake_vehicle', 'acknowledge', $board->get('token'), false);

        $this->assertSame('', $this->source->performed[0]['input']['member_message']);
        $this->assertSame('Half written', $board->get('input')['fake_vehicle']['acknowledge']['member_message']);
    }

    public function test_actions_are_checked_once_per_status_and_only_for_open_issues(): void
    {
        $this->board();

        $this->assertSame(1, $this->source->detailCalls);
    }

    public function test_scalar_junk_in_the_typed_input_cannot_break_a_finished_action(): void
    {
        $board = $this->board()->set('input.fake_vehicle', 'junk');

        $board->call('apply', 'fake_vehicle', 'acknowledge', $board->get('token'))->assertOk();

        $this->assertCount(2, $this->source->performed);
    }

    public function test_a_source_that_cannot_be_read_keeps_the_list_and_says_so(): void
    {
        $board = $this->board();
        $this->source->onRows = static function (): never {
            throw new \RuntimeException('down');
        };

        $board->call('sourcesChanged');

        $board->assertSee('Missing: Fog Nozzle')->assertSee('could not be loaded')->assertDontSee('Nothing is open');
        $this->assertSame(['fake_vehicle:1', 'fake_vehicle:2'], $board->get('selected'));

        app(DashboardReadModel::class)->forget($this->admin);
        Livewire::test(IssueGroupBoard::class, ['group' => self::GROUP])->assertSee('could not be loaded')->assertDontSee('Nothing is open');
    }

    public function test_a_poll_does_not_invalidate_a_click_on_its_way(): void
    {
        $board = $this->board();
        $token = $board->get('token');

        $board->call('sourcesChanged');

        $this->assertSame($token, $board->get('token'));
    }

    public function test_a_narrowed_selection_only_touches_what_is_selected(): void
    {
        $board = $this->board()->set('selected', ['fake_vehicle:2']);

        $board->call('apply', 'fake_vehicle', 'acknowledge', $board->get('token'));

        $this->assertSame(['2'], array_column($this->source->performed, 'id'));
    }

    public function test_a_tampered_selection_can_reach_neither_another_unit_nor_a_closed_issue(): void
    {
        $board = $this->board()->set('selected', ['fake_vehicle:4', 'fake_vehicle:3', 'nonsense', ['x']]);

        $board->call('apply', 'fake_vehicle', 'acknowledge', $board->get('token'));

        $this->assertSame([], $this->source->performed);
        $this->assertSame('warning', $board->get('notice')['tone']);
    }

    public function test_a_replayed_token_does_nothing_and_a_required_field_is_enforced(): void
    {
        $board = $this->board();
        $token = $board->get('token');

        $board->call('apply', 'fake_vehicle', 'resolve', $token);
        $this->assertSame([], $this->source->performed);
        $this->assertSame('Notes is required.', $board->get('fieldErrors')['fake_vehicle.resolve.notes']);

        $board->set('input.fake_vehicle.resolve.notes', 'Replaced')->call('apply', 'fake_vehicle', 'resolve', $token);
        $this->assertCount(2, $this->source->performed);
        $this->assertSame('Replaced', $this->source->performed[0]['input']['notes']);

        $board->call('apply', 'fake_vehicle', 'resolve', $token);
        $this->assertCount(2, $this->source->performed);
    }

    public function test_an_action_a_policy_no_longer_offers_is_refused(): void
    {
        $board = $this->board();

        $board->call('apply', 'fake_vehicle', 'verify', $board->get('token'));

        $this->assertSame([], $this->source->performed);
        $this->assertSame('warning', $board->get('notice')['tone']);
    }

    public function test_one_changed_or_refused_issue_is_reported_and_keeps_the_typed_text(): void
    {
        $this->source->performUsing = static fn (string $id): ActionResult => $id === '1' ? ActionResult::conflict() : ActionResult::done('Saved.');
        $board = $this->board()->set('input.fake_vehicle.acknowledge.member_message', 'On the way');

        $board->call('apply', 'fake_vehicle', 'acknowledge', $board->get('token'));

        $notice = $board->get('notice');
        $this->assertSame('warning', $notice['tone']);
        $this->assertStringContainsString('1 issue updated', $notice['text']);
        $this->assertStringContainsString('1 changed since this page loaded', $notice['text']);
        $this->assertSame('On the way', $board->get('input')['fake_vehicle']['acknowledge']['member_message']);
        // Unselected, so pressing the button again cannot overwrite that change unseen.
        $this->assertSame(['fake_vehicle:2'], $board->get('selected'));
    }

    public function test_a_failure_on_one_issue_does_not_stop_the_rest(): void
    {
        $this->source->performUsing = static function (string $id): ActionResult {
            if ($id === '1') {
                throw new \RuntimeException('boom');
            }

            return ActionResult::done('Saved.');
        };
        $board = $this->board();

        $board->call('apply', 'fake_vehicle', 'acknowledge', $board->get('token'));

        $this->assertCount(2, $this->source->performed);
        $this->assertStringContainsString('1 could not be updated', $board->get('notice')['text']);
    }

    public function test_select_all_toggles_and_polling_never_performs_or_reloads_details_without_a_change(): void
    {
        $board = $this->board();

        $board->call('toggleSection', 'fake_vehicle');
        $this->assertSame([], $board->get('selected'));
        $board->call('toggleSection', 'fake_vehicle');
        $this->assertSame(['fake_vehicle:1', 'fake_vehicle:2'], $board->get('selected'));

        $details = $this->source->detailCalls;
        $board->call('refreshIfChanged');
        $this->assertSame($details, $this->source->detailCalls);
        $this->assertSame([], $this->source->performed);
    }

    public function test_a_group_with_nothing_left_says_so(): void
    {
        Livewire::test(IssueGroupBoard::class, ['group' => 'apparatus:77'])->assertSee('Nothing is open here now');
    }

    public function test_the_dashboard_row_links_to_this_page_and_a_lone_issue_still_opens_the_panel(): void
    {
        $html = Livewire::test(DashboardBoard::class, ['dashboard' => 'overview'])->html();

        $this->assertStringContainsString('data-group-key="apparatus:2"', $html);
        $this->assertStringContainsString('dashboards/item?group=apparatus%3A2', $html);
        $this->assertStringContainsString('2 open issues', $html);
        $this->assertStringContainsString('data-issue-ref="fake_vehicle:4"', $html);
        $this->assertStringNotContainsString('data-issue-ref="fake_vehicle:1"', $html);
    }
}
