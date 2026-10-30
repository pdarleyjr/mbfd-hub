<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Livewire\Dashboards\DashboardBoard;
use App\Livewire\Dashboards\IssueActionPanel;
use App\Models\User;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueAction;
use App\Support\Dashboards\IssueDetail;
use App\Support\Dashboards\IssueSourceRegistry;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Dashboards\FakeIssueSource;
use Tests\TestCase;

final class IssueActionPanelTest extends TestCase
{
    use RefreshDatabase;

    private FakeIssueSource $source;

    private string $version = 'v1';

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['account_status' => 'active']);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->source = new FakeIssueSource('fake_vehicle', [DashboardView::Overview, DashboardView::VehicleCheckout]);
        $this->source->details['7'] = fn (): IssueDetail => $this->detail();
        $this->app->instance(IssueSourceRegistry::class, new IssueSourceRegistry([$this->source]));
    }

    /** @param  list<IssueAction>|null  $actions */
    private function detail(?array $actions = null, ?string $readOnly = null, array $overrides = []): IssueDetail
    {
        return new IssueDetail(
            issue: FakeIssueSource::issue('fake_vehicle', '7', array_merge([
                'title' => 'Oxygen cylinder low', 'subject' => 'Rescue 2', 'imageKey' => 'apparatus:rescue',
                'actorLabel' => 'Fixture Reporter', 'severity' => 'high', 'statusLabel' => 'New', 'version' => $this->version,
                'occurredAt' => CarbonImmutable::now()->subMinutes(5), 'sourceUrl' => '/admin/defects/7',
            ], $overrides)),
            body: $overrides['body'] ?? 'Cylinder 2 at 900 psi.',
            facts: $overrides['facts'] ?? [['label' => 'Station', 'value' => 'Station 1']],
            timeline: $overrides['timeline'] ?? [['at' => CarbonImmutable::now()->subMinutes(5), 'actor' => 'Fixture Reporter', 'text' => 'Reported at checkout']],
            actions: $actions ?? [
                new IssueAction('acknowledge', 'Acknowledge', 'primary'),
                new IssueAction('reply', 'Send reply', 'secondary', [
                    ['name' => 'message', 'label' => 'Reply', 'type' => 'textarea', 'required' => true, 'max' => 20],
                    ['name' => 'assignee', 'label' => 'Assign to', 'type' => 'select', 'options' => ['5' => 'Fleet desk'], 'required' => false],
                    ['name' => 'due_on', 'label' => 'Due', 'type' => 'date'],
                ]),
            ],
            readOnlyReason: $readOnly,
        );
    }

    private function opened(): Testable
    {
        return Livewire::test(IssueActionPanel::class)->call('openIssue', 'fake_vehicle:7');
    }

    public function test_opening_loads_detail_fresh_every_time_and_renders_it(): void
    {
        $panel = $this->opened();
        $this->assertSame(1, $this->source->detailCalls);
        $panel->assertSet('open', true)->assertSet('ref', 'fake_vehicle:7')->assertSet('version', 'v1')
            ->assertSee('Rescue 2 — Oxygen cylinder low')
            ->assertSee('Fake vehicle')
            ->assertSee('Fixture Reporter')
            ->assertSee('Cylinder 2 at 900 psi.')
            ->assertSee('Reported at checkout')
            ->assertSee('Open full record')
            ->assertSeeHtml('role="dialog"')
            ->assertSeeHtml('aria-modal="true"')
            ->assertSeeHtml('aria-labelledby="mbfd-dash-panel-title"')
            ->assertSeeHtml('/images/apparatus/roster/rescue-96.webp');

        $this->version = 'v2';
        $panel->call('openIssue', 'fake_vehicle:7')->assertSet('version', 'v2');
        $this->assertSame(2, $this->source->detailCalls);
    }

    public function test_malformed_and_unknown_refs_are_rejected_without_calling_any_source(): void
    {
        foreach (['fake_vehicle', 'fake_vehicle:', 'FAKE:1', 'fake_vehicle:1;drop', '../x:1', 'App\\Source:1', 'unknown_type:1', ['x'], null] as $ref) {
            Livewire::test(IssueActionPanel::class)->call('openIssue', $ref)
                ->assertSet('ref', null)
                ->assertSee('That item could not be opened.');
        }
        $this->assertSame(0, $this->source->detailCalls);
    }

    public function test_only_offered_actions_render_with_labels_required_markers_and_limits(): void
    {
        $this->opened()
            ->assertSeeHtml('data-panel-action="acknowledge"')
            ->assertSeeHtml('data-panel-action="reply"')
            ->assertDontSeeHtml('data-panel-action="resolve"')
            ->assertSeeHtml('for="mbfd-dash-reply-message"')
            ->assertSeeHtml('maxlength="20"')
            ->assertSeeHtml('aria-required="true"')
            ->assertSee('(required)')
            ->assertSeeHtml('<option value="5">Fleet desk</option>')
            ->assertSeeHtml('type="date"');

        $this->source->details['7'] = fn (): IssueDetail => $this->detail([new IssueAction('acknowledge', 'Acknowledge', 'primary')]);
        $this->opened()->assertSeeHtml('data-panel-action="acknowledge"')->assertDontSeeHtml('data-panel-action="reply"');
    }

    public function test_read_only_detail_shows_the_reason_and_no_buttons(): void
    {
        $this->source->details['7'] = fn (): IssueDetail => $this->detail([], 'Only Fleet reviewers can act on this.');

        $this->opened()
            ->assertSee('Only Fleet reviewers can act on this.')
            ->assertDontSeeHtml('data-panel-action=')
            ->assertDontSeeHtml('mbfd-dash-action__submit')
            ->assertSet('token', '');
    }

    public function test_done_shows_success_refreshes_lists_and_reloads_detail(): void
    {
        $this->source->performUsing = fn (): ActionResult => ActionResult::done('Reply sent.');
        $panel = $this->opened()->set('input.reply.message', 'On my way');

        $panel->call('submit', 'reply', $panel->get('token'))
            ->assertSee('Reply sent.')
            ->assertDispatched(DashboardBoard::CHANGED_EVENT)
            ->assertSet('input', []);

        $this->assertSame([['id' => '7', 'action' => 'reply', 'input' => ['message' => 'On my way', 'assignee' => '', 'due_on' => ''], 'version' => 'v1']], $this->source->performed);
        $this->assertSame(2, $this->source->detailCalls);
    }

    public function test_repeat_and_queued_submissions_are_ignored(): void
    {
        $panel = $this->opened();
        $token = $panel->get('token');
        $panel->call('submit', 'acknowledge', $token)->call('submit', 'acknowledge', $token);
        $this->assertCount(1, $this->source->performed);

        $panel->call('submit', 'acknowledge', 'not-the-token');
        $panel->call('submit', 'acknowledge', null);
        $this->assertCount(1, $this->source->performed);
    }

    public function test_an_action_the_source_did_not_offer_is_never_performed(): void
    {
        $panel = $this->opened();
        $panel->call('submit', 'delete_everything', $panel->get('token'))
            ->assertSee('That action is no longer available for this item.');
        $this->assertSame([], $this->source->performed);
    }

    public function test_conflict_reloads_detail_and_keeps_typed_input(): void
    {
        $this->source->performUsing = function (): ActionResult {
            $this->version = 'v9';

            return ActionResult::conflict();
        };
        $panel = $this->opened()->set('input.reply.message', 'Keep me');

        $panel->call('submit', 'reply', $panel->get('token'))
            ->assertSee('Someone else updated this item.')
            ->assertSet('input.reply.message', 'Keep me')
            ->assertSet('version', 'v9')
            ->assertNotDispatched(DashboardBoard::CHANGED_EVENT);
        $this->assertSame(2, $this->source->detailCalls);

        // The retry carries the version that was just reloaded.
        $this->source->performUsing = fn (): ActionResult => ActionResult::done('Reply sent.');
        $panel->call('submit', 'reply', $panel->get('token'));
        $this->assertSame('v9', $this->source->performed[1]['version']);
    }

    public function test_forbidden_invalid_and_gone_show_plain_messages_and_keep_input(): void
    {
        $this->source->performUsing = fn (): ActionResult => ActionResult::forbidden();
        $panel = $this->opened()->set('input.reply.message', 'Typed text');
        $panel->call('submit', 'reply', $panel->get('token'))
            ->assertSee('You no longer have permission to do that.')
            ->assertSet('input.reply.message', 'Typed text');

        $this->source->performUsing = fn (): ActionResult => ActionResult::invalid(['message' => 'Say more than that.']);
        $panel->call('submit', 'reply', $panel->get('token'))
            ->assertSee('Say more than that.')
            ->assertSeeHtml('aria-invalid="true"')
            ->assertSet('input.reply.message', 'Typed text');

        $this->source->performUsing = fn (): ActionResult => ActionResult::gone();
        $panel->call('submit', 'reply', $panel->get('token'))
            ->assertSee('This item no longer exists.')
            ->assertSet('input.reply.message', 'Typed text')
            ->assertSee('Not sent. Your text is kept here so you can copy it:')
            ->assertDontSeeHtml('data-panel-action=');
    }

    public function test_field_rules_are_checked_before_the_source_is_called(): void
    {
        $panel = $this->opened();
        $panel->call('submit', 'reply', $panel->get('token'))->assertSee('Reply is required.');

        $panel->set('input.reply.message', str_repeat('a', 21))->call('submit', 'reply', $panel->get('token'))
            ->assertSee('Reply must be 20 characters or fewer.');

        $panel->set('input.reply.message', 'ok')->set('input.reply.assignee', '999')->call('submit', 'reply', $panel->get('token'))
            ->assertSee('Choose one of the listed options for Assign to.');

        $panel->set('input.reply.assignee', '5')->set('input.reply.due_on', '2026-02-31')->call('submit', 'reply', $panel->get('token'))
            ->assertSee('Due must be a valid date.');

        $this->assertSame([], $this->source->performed);
    }

    public function test_detail_that_disappeared_is_reported_plainly(): void
    {
        $this->source->details['7'] = fn (): ?IssueDetail => null;
        $this->opened()->assertSee('This item no longer exists or is no longer available to you.')->assertDontSeeHtml('data-panel-action=');
    }

    public function test_panel_text_is_escaped(): void
    {
        $this->source->details['7'] = fn (): IssueDetail => $this->detail(null, null, [
            'title' => '<script>alert(1)</script>',
            'body' => '<img src=x onerror=alert(2)>',
            'facts' => [['label' => '<b>x</b>', 'value' => '<script>alert(3)</script>']],
            'timeline' => [['at' => CarbonImmutable::now(), 'actor' => '<i>a</i>', 'text' => '<script>alert(4)</script>']],
            'sourceUrl' => 'javascript:alert(5)',
        ]);

        $html = $this->opened()->html();
        $this->assertStringNotContainsString('<script>alert(', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('Open full record', $html);
    }

    public function test_close_resets_the_panel(): void
    {
        $this->opened()->set('input.reply.message', 'x')->call('close')
            ->assertSet('open', false)->assertSet('ref', null)->assertSet('detail', [])->assertSet('input', []);
    }

    public function test_non_admin_cannot_use_the_panel(): void
    {
        $this->actingAs(User::factory()->create(['account_status' => 'active']));

        Livewire::test(IssueActionPanel::class)->call('openIssue', 'fake_vehicle:7')->assertForbidden();
        $this->assertSame(0, $this->source->detailCalls);
    }

    public function test_board_rows_are_buttons_that_open_the_panel_by_ref(): void
    {
        $this->source->rows = [FakeIssueSource::issue('fake_vehicle', '7', ['title' => 'Oxygen cylinder low', 'subject' => 'Rescue 2'])];

        Livewire::test(DashboardBoard::class, ['dashboard' => 'vehicle-checkout'])
            ->assertSeeHtml('<button type="button" class="mbfd-dash-row__button')
            ->assertSeeHtml('data-issue-ref="fake_vehicle:7"')
            ->assertSeeHtml('aria-haspopup="dialog"')
            ->assertSeeHtml("\$dispatch('dashboard-issue-open', { ref: \$el.dataset.issueRef })");
    }
}
