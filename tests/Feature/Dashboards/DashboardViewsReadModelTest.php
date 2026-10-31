<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Livewire\Dashboards\DashboardBoard;
use App\Models\User;
use App\Services\Dashboards\DashboardReadModel;
use App\Services\Dashboards\IssuePresenter;
use App\Support\Dashboards\DashboardImages;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueSourceRegistry;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\Support\Dashboards\FakeIssueSource;
use Tests\TestCase;

final class DashboardViewsReadModelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-30 10:00:00', 'America/New_York'));
        $this->admin = User::factory()->create(['account_status' => 'active']);
        $this->admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function bind(FakeIssueSource ...$sources): void
    {
        $this->app->instance(IssueSourceRegistry::class, new IssueSourceRegistry($sources));
    }

    /** @return list<\App\Support\Dashboards\DashboardIssue> */
    private function many(string $type, int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = FakeIssueSource::issue($type, (string) $i, ['occurredAt' => CarbonImmutable::now()->subMinutes($i)]);
        }

        return $rows;
    }

    public function test_rows_are_merged_by_severity_then_recency_and_counts_summed(): void
    {
        $a = new FakeIssueSource('src_a', [DashboardView::VehicleCheckout], [
            FakeIssueSource::issue('src_a', '1', ['severity' => 'low', 'occurredAt' => CarbonImmutable::now()->subMinute()]),
            FakeIssueSource::issue('src_a', '2', ['severity' => 'high', 'occurredAt' => CarbonImmutable::now()->subHours(3)]),
        ]);
        $b = new FakeIssueSource('src_b', [DashboardView::VehicleCheckout], [
            FakeIssueSource::issue('src_b', '1', ['severity' => 'high', 'occurredAt' => CarbonImmutable::now()->subMinutes(10)]),
            FakeIssueSource::issue('src_b', '2', ['severity' => 'medium', 'occurredAt' => CarbonImmutable::now()]),
        ], ['open' => 2, 'high' => 1, 'rescue' => 4]);
        $this->bind($a, $b);

        $snapshot = app(DashboardReadModel::class)->load($this->admin, DashboardView::VehicleCheckout);

        $this->assertSame(['src_b:1', 'src_a:2', 'src_b:2', 'src_a:1'], array_map(static fn ($r) => $r->ref(), $snapshot->rows));
        $this->assertSame(4, $snapshot->count('open'));
        $this->assertSame(2, $snapshot->count('high'));
        $this->assertSame(4, $snapshot->count('rescue'));
    }

    public function test_sources_not_visible_to_the_user_or_not_on_the_view_are_not_read(): void
    {
        $hidden = new FakeIssueSource('hidden', [DashboardView::VehicleCheckout], $this->many('hidden', 3), visible: false);
        $elsewhere = new FakeIssueSource('elsewhere', [DashboardView::StationOperations], $this->many('elsewhere', 3));
        $this->bind($hidden, $elsewhere);

        $snapshot = app(DashboardReadModel::class)->load($this->admin, DashboardView::VehicleCheckout);

        $this->assertSame([], $snapshot->rows);
        $this->assertSame(0, $hidden->rowsCalls + $hidden->countsCalls + $elsewhere->rowsCalls + $elsewhere->countsCalls);
    }

    public function test_each_source_is_capped_and_asked_once_per_load(): void
    {
        $big = new FakeIssueSource('big', [DashboardView::VehicleCheckout], $this->many('big', 210));
        $this->bind($big);

        $snapshot = app(DashboardReadModel::class)->load($this->admin, DashboardView::VehicleCheckout);

        $this->assertCount(DashboardReadModel::ROWS_PER_SOURCE, $snapshot->rows);
        $this->assertTrue($snapshot->moreAvailable);
        $this->assertSame(1, $big->rowsCalls);
        $this->assertSame(1, $big->countsCalls);
        $this->assertSame(210, $snapshot->count('open'));
    }

    public function test_loads_are_cached_briefly_per_user_and_refresh_bypasses_the_cache(): void
    {
        $source = new FakeIssueSource('src', [DashboardView::VehicleCheckout], $this->many('src', 2));
        $this->bind($source);
        $model = app(DashboardReadModel::class);

        $model->load($this->admin, DashboardView::VehicleCheckout);
        $model->load($this->admin, DashboardView::VehicleCheckout);
        $this->assertSame(1, $source->rowsCalls);

        $other = User::factory()->create(['account_status' => 'active']);
        $model->load($other, DashboardView::VehicleCheckout);
        $this->assertSame(2, $source->rowsCalls, 'never shared across users');

        $model->load($this->admin, DashboardView::VehicleCheckout, fresh: true);
        $this->assertSame(3, $source->rowsCalls);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(DashboardReadModel::CACHE_SECONDS + 1));
        $model->load($this->admin, DashboardView::VehicleCheckout);
        $this->assertSame(4, $source->rowsCalls);
    }

    public function test_filters_map_to_the_source_query_and_counts_stay_unfiltered(): void
    {
        $source = new FakeIssueSource('src', [DashboardView::VehicleCheckout], [
            FakeIssueSource::issue('src', '1', ['tags' => ['rescue'], 'severity' => 'high']),
            FakeIssueSource::issue('src', '2', ['tags' => ['marine'], 'stage' => 'in_progress']),
        ]);
        $this->bind($source);
        $model = app(DashboardReadModel::class);

        $rescue = $model->load($this->admin, DashboardView::VehicleCheckout, 'rescue');
        $this->assertSame(['src:1'], array_map(static fn ($r) => $r->ref(), $rescue->rows));
        $this->assertSame(['rescue'], $source->rowQueries[0]->tags);
        $this->assertSame(2, $rescue->count('open'));

        $model->load($this->admin, DashboardView::VehicleCheckout, 'high');
        $this->assertSame('high', $source->rowQueries[1]->severity);
        $model->load($this->admin, DashboardView::VehicleCheckout, 'awaiting_response');
        $this->assertSame('awaiting_response', $source->rowQueries[2]->stage);

        $bogus = $model->load($this->admin, DashboardView::VehicleCheckout, 'facilities');
        $this->assertSame('all', $bogus->filter, 'a station chip is not valid on the vehicle view');
    }

    public function test_a_failing_source_does_not_break_the_board_and_is_not_cached(): void
    {
        $ok = new FakeIssueSource('ok', [DashboardView::VehicleCheckout], $this->many('ok', 1));
        $bad = new FakeIssueSource('bad', [DashboardView::VehicleCheckout], [], onRows: static fn () => throw new RuntimeException('down'));
        $this->bind($ok, $bad);

        $snapshot = app(DashboardReadModel::class)->load($this->admin, DashboardView::VehicleCheckout);
        $this->assertSame(['bad'], $snapshot->failedSources);
        $this->assertCount(1, $snapshot->rows);

        app(DashboardReadModel::class)->load($this->admin, DashboardView::VehicleCheckout);
        $this->assertSame(2, $ok->rowsCalls);

        Livewire::test(DashboardBoard::class, ['dashboard' => 'vehicle-checkout'])
            ->assertSee('Some items could not be loaded just now');
    }

    public function test_board_render_query_count_does_not_grow_with_rows(): void
    {
        $source = new FakeIssueSource('src', [DashboardView::Overview, DashboardView::VehicleCheckout], $this->many('src', 3));
        $this->bind($source);
        Livewire::test(DashboardBoard::class, ['dashboard' => 'vehicle-checkout']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(DashboardBoard::class, ['dashboard' => 'vehicle-checkout'])->call('refresh');
        $few = count(DB::getQueryLog());

        $source->rows = $this->many('src', 25);
        DB::flushQueryLog();
        Livewire::test(DashboardBoard::class, ['dashboard' => 'vehicle-checkout'])->call('refresh');
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($few, $many);
        $this->assertLessThanOrEqual(20, $many);
    }

    public function test_polling_and_refresh_never_perform_actions(): void
    {
        $source = new FakeIssueSource('src', [DashboardView::VehicleCheckout], $this->many('src', 2));
        $this->bind($source);

        Livewire::test(DashboardBoard::class, ['dashboard' => 'vehicle-checkout'])
            ->assertSeeHtml('wire:poll.30s.visible')
            ->call('$refresh')->call('refresh')->dispatch(DashboardBoard::CHANGED_EVENT);

        $this->assertSame([], $source->performed);
        $this->assertSame(0, $source->detailCalls);
    }

    public function test_images_map_bundled_keys_and_fall_back_to_null(): void
    {
        $this->assertSame('/images/apparatus/roster/rescue-96.webp', DashboardImages::url('apparatus:rescue', 88));
        $this->assertSame('/images/apparatus/roster/rescue-192.webp', DashboardImages::url('apparatus:rescue', 176));
        $this->assertSame('/images/apparatus/roster/ladder-3-96.webp', DashboardImages::url('apparatus:ladder-3', 40));
        $this->assertSame('/images/apparatus/roster/fireboat-192.webp', DashboardImages::url('apparatus:fireboat', 999));
        $this->assertSame('/images/apparatus/roster/command-300-120.webp', DashboardImages::url('apparatus:command-300', 88));
        $this->assertSame('/images/apparatus/roster/captain-5-240.webp', DashboardImages::url('apparatus:captain-5', 176));
        $this->assertSame('/images/stations/station-1.webp', DashboardImages::url('station:1', 88));
        $this->assertSame('cutout', DashboardImages::kind('apparatus:engine'));
        $this->assertSame('photo', DashboardImages::kind('station:6'));

        foreach ([null, '', 'station:5', 'station:99', 'station:../1', 'apparatus:tanker', 'apparatus:../../x', 'https://evil.test/a.png', 'apparatus:rescue/x'] as $key) {
            $this->assertNull(DashboardImages::url($key, 88), (string) $key);
            $this->assertNull(DashboardImages::kind($key));
        }

        foreach (['engine', 'ladder-1', 'ladder-3', 'rescue', 'fireboat'] as $class) {
            $this->assertFileExists(public_path("images/apparatus/roster/{$class}-96.webp"));
            $this->assertFileExists(public_path("images/apparatus/roster/{$class}-192.webp"));
        }
    }

    public function test_rows_render_images_with_dimensions_and_alt_or_a_fallback_icon(): void
    {
        $this->bind(new FakeIssueSource('src', [DashboardView::VehicleCheckout], [
            FakeIssueSource::issue('src', '1', ['subject' => 'Rescue 2', 'imageKey' => 'apparatus:rescue']),
            FakeIssueSource::issue('src', '2', ['subject' => 'Utility 9', 'imageKey' => null]),
        ]));

        $html = Livewire::test(DashboardBoard::class, ['dashboard' => 'vehicle-checkout'])->html();
        $this->assertMatchesRegularExpression('#<img src="/images/apparatus/roster/rescue-96.webp"\s+srcset="/images/apparatus/roster/rescue-96.webp 1x, /images/apparatus/roster/rescue-192.webp 2x"\s+width="88" height="52" loading="lazy" decoding="async" alt="Rescue 2">#', $html);
        $this->assertStringContainsString('mbfd-dash-thumb--cutout', $html);
        $this->assertStringContainsString('mbfd-dash-thumb--icon', $html);
    }

    public function test_time_labels(): void
    {
        $now = CarbonImmutable::parse('2026-10-30 10:00:00', 'America/New_York');
        $this->assertSame('6:12 AM', IssuePresenter::time($now->setTime(6, 12), $now));
        $this->assertSame('Yesterday, 4:18 PM', IssuePresenter::time($now->subDay()->setTime(16, 18), $now));
        $this->assertSame('Oct 28, 6:11 PM', IssuePresenter::time($now->subDays(2)->setTime(18, 11), $now));
        $this->assertSame('Dec 31, 2025, 6:11 PM', IssuePresenter::time(CarbonImmutable::parse('2025-12-31 18:11', 'America/New_York'), $now));
        // 01:30 UTC on Oct 30 is 9:30 PM Oct 29 in New York.
        $this->assertSame('Yesterday, 9:30 PM', IssuePresenter::time(CarbonImmutable::parse('2026-10-30 01:30', 'UTC'), $now));
    }
}
