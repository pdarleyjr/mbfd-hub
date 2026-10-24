<?php

declare(strict_types=1);

namespace Tests\Feature\Defects;

use App\Filament\Widgets\StationOperationsHubWidget;
use App\Http\Controllers\Api\CommandOverviewController;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\User;
use App\Services\DailyCheckoutComplianceService;
use App\Services\Defects\DefectContinuityService;
use App\Services\Defects\DefectCorrectiveActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

/**
 * An issue closed with no action required is no longer OPEN everywhere (readiness, the chief display,
 * the command overview, the Operations Hub, Daily Checkout's "previously reported" list), exactly like a
 * repaired one, but every count that says HOW issues ended keeps the two apart.
 */
final class ClosedNoActionSurfacesTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;

    private Apparatus $rig;

    private User $admin;

    private ApparatusDefect $open;

    private ApparatusDefect $closedNoAction;

    private ApparatusDefect $fixed;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $token = Str::random(48);
        config(['services.display_api.token' => $token]);
        $this->withHeader('X-Display-Token', $token);

        $this->rig = $this->rig();
        $reporter = $this->member('Riley Reporter');
        $this->admin = $this->reviewer();
        $inspection = $this->inspectionBy($this->rig, $reporter);
        $this->open = $this->openDefect($this->rig, $inspection, $reporter, 'Open Battery Charger');
        $this->closedNoAction = $this->openDefect($this->rig, $inspection, $reporter, 'Dismissed Wedge Kit');
        $this->fixed = $this->openDefect($this->rig, $inspection, $reporter, 'Resolved Hose Nozzle');

        $service = app(DefectCorrectiveActionService::class);
        $service->dismiss($this->closedNoAction->id, $this->admin, ['reason' => 'Duplicate of the report from Oct 20.']);
        $service->resolve($this->fixed->id, $this->admin, ['resolution_type' => 'replacement_installed', 'resolution_notes' => 'New nozzle issued.']);
    }

    public function test_it_is_no_longer_open_in_readiness_and_daily_checkout_exactly_like_a_repair(): void
    {
        $this->assertSame([$this->open->id], $this->rig->openDefects()->pluck('id')->all());
        $this->assertSame([$this->open->id], ApparatusDefect::query()->criticalForReadiness()->pluck('id')->all());

        $findings = app(DefectContinuityService::class)->openFindings($this->rig, []);
        $this->assertSame([$this->open->id], array_map(static fn (array $f): int => (int) $f['id'], $findings));
    }

    public function test_the_chief_display_counts_and_lists_only_open_issues(): void
    {
        $data = $this->getJson('/api/display/snapshot')->assertOk()->json();

        $this->assertSame(1, $data['defects']['total_open']);
        $json = json_encode($data);
        $this->assertStringContainsString('Open Battery Charger', $json);
        $this->assertStringNotContainsString('Dismissed Wedge Kit', $json);
        $this->assertStringNotContainsString('Resolved Hose Nozzle', $json);
    }

    public function test_the_command_overview_counts_open_issues_only(): void
    {
        $payload = app(CommandOverviewController::class)(app(DailyCheckoutComplianceService::class))->getData(true);

        $openCounts = [];
        array_walk_recursive($payload, static function ($value, $key) use (&$openCounts): void {
            if ($key === 'open_defects') {
                $openCounts[] = $value;
            }
        });
        $this->assertNotSame([], $openCounts);
        $this->assertSame(1, max($openCounts));
        $this->assertStringNotContainsString('Dismissed Wedge Kit', (string) json_encode($payload));
    }

    public function test_the_operations_hub_lists_open_issues_only(): void
    {
        // The hub only lists the configured stations.
        \Illuminate\Support\Facades\DB::table('stations')->where('id', $this->rig->station_id)
            ->update(['station_number' => (int) array_key_first((array) config('station_operations.stations'))]);

        $json = (string) json_encode(app(StationOperationsHubWidget::class)->getViewData());

        $this->assertStringContainsString('Open Battery Charger', $json);
        $this->assertStringNotContainsString('Dismissed Wedge Kit', $json);
        $this->assertStringNotContainsString('Resolved Hose Nozzle', $json);
    }

    public function test_the_admin_metrics_separate_repairs_from_no_action_closures(): void
    {
        $this->actingAs($this->superAdminUser());

        $defects = $this->getJson('/api/admin/metrics')->assertOk()->json('defects');

        $this->assertSame(1, $defects['open']);
        $this->assertSame(1, $defects['resolved']);
        $this->assertSame(1, $defects['closed_no_action']);
        $this->assertSame(3, $defects['total']);
    }

    public function test_the_defects_list_filter_options_keep_resolved_and_closed_apart(): void
    {
        $labels = \App\Support\Fleet\DefectActionStatus::labels();
        $this->assertSame('Resolved', $labels['resolved']);
        $this->assertSame('Closed — no action required', $labels['dismissed']);

        $byStatus = fn (string $status): array => ApparatusDefect::query()->where('action_status', $status)->pluck('id')->all();
        $this->assertSame([$this->fixed->id], $byStatus('resolved'));
        $this->assertSame([$this->closedNoAction->id], $byStatus('dismissed'));
    }

    public function test_an_item_reported_again_after_a_no_action_closure_is_flagged_not_called_routine_recurrence(): void
    {
        $reporter = $this->member('Riley Again');
        $inspection = $this->inspectionBy($this->rig, $reporter);
        $again = $this->openDefect($this->rig, $inspection, $reporter, 'Dismissed Wedge Kit');
        $again->forceFill(['recurrence_of_defect_id' => $this->closedNoAction->id])->save();
        $afterRepair = $this->openDefect($this->rig, $inspection, $reporter, 'Resolved Hose Nozzle');
        $afterRepair->forceFill(['recurrence_of_defect_id' => $this->fixed->id])->save();

        $cards = collect(app(\App\Filament\Support\InspectionWorkspace::class)->build($inspection, $this->admin)['defects'])->keyBy('id');

        $this->assertTrue($cards[$again->id]['recurrence_after_no_action']);
        $this->assertTrue($cards[$afterRepair->id]['recurrence']);
        $this->assertFalse($cards[$afterRepair->id]['recurrence_after_no_action']);
    }

    public function test_a_defect_cannot_be_hard_deleted_from_its_edit_page_even_by_a_super_admin(): void
    {
        $this->actingAs($this->superAdminUser());

        \Livewire\Livewire::test(\App\Filament\Resources\DefectResource\Pages\EditDefect::class, ['record' => $this->open->getKey()])
            ->assertActionDoesNotExist('delete');
    }

    private function superAdminUser(): User
    {
        $user = $this->reviewer('super_admin', 'Super Admin');

        return $user;
    }
}
