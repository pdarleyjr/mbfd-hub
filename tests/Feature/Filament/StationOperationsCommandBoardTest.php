<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\StationOperationsHubWidget;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\HubSupportTicket;
use App\Models\Station;
use App\Models\StationInspection;
use App\Models\StationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class StationOperationsCommandBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'America/New_York'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $user = User::factory()->create(['account_status' => 'active']);
        $user->assignRole('super_admin');
        $this->actingAsCanonicalUser($user);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_operational_activity_uses_a_half_open_eight_am_window_and_preserves_duplicate_unit_submissions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'America/New_York'));
        $station = $this->station(1);
        $apparatus = $this->apparatus($station, 'E1');

        $before = $this->inspection($apparatus, '2026-09-23 07:59:59 America/New_York');
        $atStart = $this->inspection($apparatus, '2026-09-23 08:00:00 America/New_York', 'accepted');
        $duplicate = $this->inspection($apparatus, '2026-09-23 08:17:00 America/New_York', 'accepted_with_exception');
        $beforeEnd = $this->inspection($apparatus, '2026-09-24 07:59:59 America/New_York', 'accepted');
        $atEnd = $this->inspection($apparatus, '2026-09-24 08:00:00 America/New_York', 'accepted');

        $data = app(StationOperationsHubWidget::class)->getViewData();
        $checkouts = collect($data['department']['checkouts']);

        self::assertSame([$beforeEnd->id, $duplicate->id, $atStart->id], $checkouts->pluck('id')->all());
        self::assertNotContains($before->id, $checkouts->pluck('id')->all());
        self::assertNotContains($atEnd->id, $checkouts->pluck('id')->all());
        self::assertSame(['Accepted', 'Follow-up needed', 'Accepted'], $checkouts->pluck('status')->all());
        self::assertTrue($checkouts->every(fn (array $row): bool => str_contains($row['url'], '/admin/inspections/')));
        self::assertSame('2026-09-23T08:00:00-04:00', $data['operationalWindow']['start']);
        self::assertSame('2026-09-24T08:00:00-04:00', $data['operationalWindow']['end']);
        self::assertSame(3, collect($data['stationData'][$station->id]['activity'])->where('type', 'daily_checkout')->count());
    }

    public function test_unresolved_attention_survives_the_operational_boundary_and_resolved_items_do_not_appear(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'America/New_York'));
        $station = $this->station(1);
        $apparatus = $this->apparatus($station, 'L1', 'Out of Service', 320, 300);

        $oldRequest = StationRequest::query()->create([
            'station_id' => $station->id,
            'requester_name_snapshot' => 'Fixture Member',
            'request_type' => 'repair',
            'title' => 'Bay door',
            'description' => 'Door remains open.',
            'priority' => 'high',
            'status' => 'pending',
            'created_at' => Carbon::parse('2026-09-20 09:00:00', 'America/New_York'),
        ]);
        $reviewPending = StationInspection::query()->create([
            'station_id' => $station->id,
            'inspection_date' => '2026-09-20',
            'inspection_type' => 'weekly',
            'overall_status' => 'needs_attention',
            'form_data' => [],
            'created_at' => Carbon::parse('2026-09-20 10:00:00', 'America/New_York'),
        ]);
        $openSupport = HubSupportTicket::factory()->create([
            'generated_title' => 'Checkout page will not load',
            'impact' => 'task_blocking',
            'status' => 'new',
            'created_at' => Carbon::parse('2026-09-19 09:00:00', 'America/New_York'),
        ]);
        $acknowledgedSupport = HubSupportTicket::factory()->create([
            'generated_title' => 'Intermittent display issue',
            'impact' => 'minor',
            'status' => 'acknowledged',
            'acknowledged_at' => Carbon::parse('2026-09-20 10:00:00', 'America/New_York'),
            'created_at' => Carbon::parse('2026-09-20 09:00:00', 'America/New_York'),
        ]);
        $closedSupport = HubSupportTicket::factory()->create(['status' => 'closed']);

        $data = app(StationOperationsHubWidget::class)->getViewData();
        $attention = collect($data['department']['attention']);

        self::assertNotNull($attention->firstWhere(fn (array $row): bool => $row['type'] === 'station_request' && $row['id'] === $oldRequest->id));
        self::assertNotNull($attention->firstWhere(fn (array $row): bool => $row['type'] === 'station_inspection' && $row['id'] === $reviewPending->id));
        self::assertNotNull($attention->firstWhere(fn (array $row): bool => $row['type'] === 'support_ticket' && $row['id'] === $openSupport->id));
        self::assertNotNull($attention->firstWhere(fn (array $row): bool => $row['type'] === 'support_ticket' && $row['id'] === $acknowledgedSupport->id));
        self::assertNull($attention->firstWhere(fn (array $row): bool => $row['type'] === 'support_ticket' && $row['id'] === $closedSupport->id));
        self::assertNotNull($attention->firstWhere(fn (array $row): bool => $row['type'] === 'apparatus_status' && $row['id'] === $apparatus->id));
        self::assertNotNull($attention->firstWhere(fn (array $row): bool => $row['type'] === 'pm' && $row['id'] === $apparatus->id));
        self::assertSame(1, $data['department']['apparatus']['out_of_service']);
        self::assertSame(1, $data['department']['pm']['due']);
    }

    public function test_station_activity_is_batched_and_does_not_leak_between_stations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'America/New_York'));
        $stationOne = $this->station(1);
        $stationTwo = $this->station(2);
        $this->station(5);
        $engineOne = $this->apparatus($stationOne, 'E1');
        $engineTwo = $this->apparatus($stationTwo, 'E2');
        $inspectionOne = $this->inspection($engineOne, '2026-09-23 09:00:00 America/New_York', 'accepted');
        $inspectionTwo = $this->inspection($engineTwo, '2026-09-23 09:05:00 America/New_York', 'accepted');

        $data = app(StationOperationsHubWidget::class)->getViewData();

        self::assertSame([1, 2], collect($data['stations'])->pluck('station_number')->all());
        self::assertSame([$inspectionOne->id], collect($data['stationData'][$stationOne->id]['activity'])->where('type', 'daily_checkout')->pluck('id')->all());
        self::assertSame([$inspectionTwo->id], collect($data['stationData'][$stationTwo->id]['activity'])->where('type', 'daily_checkout')->pluck('id')->all());
        self::assertArrayNotHasKey('readiness', $data['stationData'][$stationOne->id]);
        self::assertArrayNotHasKey('readinessPercent', $data['department']);
    }

    public function test_authorized_dashboard_and_display_mode_render_the_command_board(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'America/New_York'));
        $this->station(1);

        $this->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee('Station Operations Command Board')
            ->assertSee('Today’s Truck Checkouts')
            ->assertSee('New / Needs Attention');

        $this->get(Dashboard::getUrl(['display' => 1]))
            ->assertOk()
            ->assertSee('mbfd-command-display', false)
            ->assertSee('Exit Display Mode');
    }

    public function test_widget_renders_explicit_polling_accessible_expansion_and_no_readiness_score(): void
    {
        $this->station(1);

        Livewire::test(StationOperationsHubWidget::class)
            ->assertSuccessful()
            ->assertSeeHtml('wire:poll.20s="refreshBoard"')
            ->assertSee('Expand Station 1 operations')
            ->assertSee('Operational-Day Activity')
            ->assertDontSee('Readiness')
            ->assertDontSee('% readiness');
    }

    public function test_display_mode_exit_remains_available_after_a_live_refresh(): void
    {
        $this->station(1);

        Livewire::withQueryParams(['display' => 1])
            ->test(StationOperationsHubWidget::class)
            ->assertSet('displayMode', true)
            ->assertSee('Exit Display Mode')
            ->call('refreshBoard')
            ->assertSet('displayMode', true)
            ->assertSee('Exit Display Mode');
    }

    public function test_dashboard_requires_existing_admin_authentication(): void
    {
        auth()->logout();

        $this->get(Dashboard::getUrl())->assertRedirect('/login');
    }

    public function test_query_count_does_not_scale_with_the_number_of_configured_stations(): void
    {
        $first = $this->station(1);
        $this->apparatus($first, 'E1');
        app(StationOperationsHubWidget::class)->getViewData();

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(StationOperationsHubWidget::class)->getViewData();
        $oneStationCount = count(DB::getQueryLog());

        foreach ([2, 3, 4, 6] as $number) {
            $station = $this->station($number);
            $this->apparatus($station, 'E'.$number);
        }

        DB::flushQueryLog();
        app(StationOperationsHubWidget::class)->getViewData();
        $fiveStationCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertSame($oneStationCount, $fiveStationCount);
        self::assertLessThanOrEqual(20, $fiveStationCount);
    }

    private function station(int $number): Station
    {
        return Station::query()->create([
            'station_number' => $number,
            'name' => "Station {$number}",
            'address' => "{$number} Test Street",
            'is_active' => true,
        ]);
    }

    private function apparatus(
        Station $station,
        string $unit,
        string $status = 'In Service',
        int $currentHours = 100,
        int $pmInterval = 300,
    ): Apparatus {
        return Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => $unit,
            'designation' => $unit,
            'vehicle_number' => $unit,
            'make' => 'Fixture',
            'model' => 'Engine',
            'status' => $status,
            'daily_checkout_requirement' => 'required',
            'current_engine_hours' => $currentHours,
            'last_pm_engine_hours' => 0,
            'pm_interval_hours' => $pmInterval,
        ]);
    }

    private function inspection(Apparatus $apparatus, string $completedAt, ?string $processingStatus = null): ApparatusInspection
    {
        return ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id,
            'client_submission_id' => (string) Str::uuid(),
            'operator_name' => 'Fixture Operator',
            'rank' => 'Firefighter',
            'shift' => 'A-Day',
            'unit_number' => $apparatus->unit_id,
            'review_status' => 'approved',
            'processing_status' => $processingStatus,
            'completed_at' => Carbon::parse($completedAt)->utc(),
        ]);
    }
}
