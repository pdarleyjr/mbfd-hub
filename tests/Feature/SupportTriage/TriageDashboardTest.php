<?php

declare(strict_types=1);

namespace Tests\Feature\SupportTriage;

use App\Filament\Resources\DefectResource\Pages\ListDefects;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\User;
use App\Services\Dashboards\Sources\Station\StationRequestSource;
use App\Services\Dashboards\Sources\Vehicle\ApparatusDefectSource;
use App\Services\StationRequestSideEffectService;
use App\Services\SupportTriage\EquipmentClassification as C;
use App\Services\SupportTriage\EquipmentRuleService;
use App\Services\SupportTriage\TriageProjector;
use App\Support\Dashboards\DashboardQuery;
use App\Support\Dashboards\IssueSourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Dashboards\StationDashboardFixtures;
use Tests\Feature\Dashboards\VehicleDashboardFixtures;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

final class TriageDashboardTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;
    use StationDashboardFixtures;
    use VehicleDashboardFixtures;

    private ApparatusDefectSource $source;

    private Apparatus $rig;

    private User $reporter;

    private ApparatusInspection $inspection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVehicleDashboard();
        app(EquipmentRuleService::class)->seedDefaults();
        $this->source = app(IssueSourceRegistry::class)->get('apparatus_defect');
        $this->rig = $this->rig('Rescue 2', 'R2');
        $this->rig->update(['type' => 'Rescue', 'daily_checkout_template' => 'rescue']);
        $this->reporter = $this->member('Riley Reporter');
        $this->inspection = $this->inspectionBy($this->rig, $this->reporter, '2026-10-30 12:00:00');
    }

    private function defect(string $item, array $attributes = []): ApparatusDefect
    {
        $defect = $this->openDefect($this->rig, $this->inspection, $this->reporter, $item);
        // Checkouts project through ApparatusInspectionProcessingService; these fixtures are created directly.
        app(TriageProjector::class)->refreshDefect($defect);
        if ($attributes !== []) {
            $defect->forceFill($attributes)->save();
        }

        return $defect->refresh();
    }

    /** @return array<int, string> defect id => severity shown */
    private function severities(?string $filter = null): array
    {
        $rows = $this->source->rows($this->personas()['admin'], $this->boardQuery(severity: $filter), 100);

        return collect($rows)->mapWithKeys(fn ($row): array => [(int) $row->id => $row->severity])->all();
    }

    public function test_the_dashboard_shows_and_filters_by_the_equipment_priority(): void
    {
        $litter = $this->defect('Kitty Litter');
        $meter = $this->defect('Gas Meter');
        $scba = $this->defect('SCBA #1');

        $this->assertEquals([$litter->id => C::LOW, $meter->id => C::MEDIUM, $scba->id => C::HIGH], $this->severities());
        $this->assertSame([$scba->id], array_keys($this->severities(C::HIGH)));
        $this->assertSame([$meter->id], array_keys($this->severities(C::MEDIUM)));
        $this->assertSame([$litter->id], array_keys($this->severities(C::LOW)));

        $counts = $this->source->counts($this->personas()['admin'], $this->boardQuery());
        $this->assertSame(3, $counts['open']);
        $this->assertSame(1, $counts['high']);
        $this->assertSame(1, $this->source->counts($this->personas()['admin'], $this->boardQuery(severity: C::LOW))['open']);
    }

    public function test_rows_are_ordered_high_first(): void
    {
        $litter = $this->defect('Kitty Litter', ['first_reported_at' => '2026-10-30 13:00:00']);
        $scba = $this->defect('SCBA #1', ['first_reported_at' => '2026-10-30 11:00:00']);
        $meter = $this->defect('Gas Meter', ['first_reported_at' => '2026-10-30 12:00:00']);

        $this->assertSame([$scba->id, $meter->id, $litter->id], array_keys($this->severities()));
    }

    public function test_an_issue_not_projected_yet_uses_the_older_rule_and_a_flag_still_wins(): void
    {
        $missing = $this->defect('Kitty Litter');
        $routine = $this->defect('Flashlight', ['issue_type' => 'expired']);
        \App\Models\SupportTriage\SupportTriageLevel::query()->delete();

        $this->assertEquals([$missing->id => C::MEDIUM, $routine->id => C::LOW], $this->severities());

        $missing->forceFill(['severity' => 'significant'])->saveQuietly();
        $this->assertSame(C::HIGH, $this->severities()[$missing->id], 'a flag reads high even before projection');
    }

    public function test_the_detail_names_where_the_priority_came_from_and_staff_can_change_it(): void
    {
        $admin = $this->personas()['admin'];
        $defect = $this->defect('Kitty Litter');
        $detail = $this->source->detail($admin, (string) $defect->id);
        $facts = collect($detail->facts)->pluck('value', 'label');
        $this->assertSame('Low (set by equipment priorities)', $facts['Priority']);
        $this->assertContains('set_priority', array_map(static fn ($action): string => $action->key, $detail->actions));

        $result = $this->source->perform($admin, (string) $defect->id, 'set_priority', ['level' => 'high'], $detail->issue->version);

        $this->assertSame('done', $result->outcome);
        $changed = $this->source->detail($admin, (string) $defect->id);
        $this->assertSame('High (changed by '.($admin->display_name ?: $admin->name).')', collect($changed->facts)->pluck('value', 'label')['Priority']);
        $this->assertSame('high', $changed->issue->severity);

        $this->source->perform($admin, (string) $defect->id, 'set_priority', ['level' => 'auto'], $changed->issue->version);
        $this->assertSame('Low (set by equipment priorities)', collect($this->source->detail($admin, (string) $defect->id)->facts)->pluck('value', 'label')['Priority']);

        $this->assertSame('invalid', $this->source->perform($admin, (string) $defect->id, 'set_priority', ['level' => 'urgent'], $detail->issue->version)->outcome);
    }

    public function test_changing_priority_follows_the_existing_authorization(): void
    {
        $defect = $this->defect('Kitty Litter');
        $viewer = $this->fleetViewer();
        $version = $this->source->detail($this->personas()['admin'], (string) $defect->id)->issue->version;

        $this->assertSame('forbidden', $this->source->perform($viewer, (string) $defect->id, 'set_priority', ['level' => 'high'], $version)->outcome);
        $this->assertSame('forbidden', $this->source->perform($this->plainMember(), (string) $defect->id, 'set_priority', ['level' => 'high'], $version)->outcome);
        $this->assertSame(C::LOW, app(TriageProjector::class)->levelFor('apparatus_defect', (int) $defect->id));
        $this->assertNotContains('set_priority', array_map(static fn ($action): string => $action->key, $this->source->detail($viewer, (string) $defect->id)->actions));
    }

    public function test_the_defects_list_has_a_priority_column_and_filter(): void
    {
        $litter = $this->defect('Kitty Litter');
        $scba = $this->defect('SCBA #1');
        $this->actingAs($this->personas()['super admin']);

        Livewire::test(ListDefects::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$litter, $scba])
            ->filterTable('priority_level', C::HIGH)
            ->assertCanSeeTableRecords([$scba])
            ->assertCanNotSeeTableRecords([$litter])
            ->filterTable('priority_level', C::LOW)
            ->assertCanSeeTableRecords([$litter])
            ->assertCanNotSeeTableRecords([$scba]);
    }

    public function test_station_requests_show_the_equipment_priority_and_staff_can_change_it(): void
    {
        $source = app(StationRequestSource::class);
        $admin = $this->adminWith(['admin.stations.view', 'admin.stations.manage']);
        $ac = $this->stationRequest(['title' => 'Dayroom A/C is not working', 'description' => 'Hot', 'priority' => 'low']);
        $paper = $this->stationRequest(['title' => 'Need paper towels', 'description' => 'Out', 'priority' => 'critical']);
        $sparks = $this->stationRequest(['title' => 'Outlet sparking', 'description' => 'Kitchen', 'priority' => 'normal']);
        $unprojected = $this->stationRequest(['title' => 'Something', 'description' => 'Else', 'priority' => 'normal']);
        foreach ([$ac, $paper, $sparks] as $request) {
            app(StationRequestSideEffectService::class)->requestCreated($request);
        }

        $rows = collect($source->rows($admin, DashboardQuery::now(), 50))->mapWithKeys(fn ($row): array => [(int) $row->id => $row->severity]);
        $this->assertSame(C::MEDIUM, $rows[$ac->id], 'the A/C is medium even though the member picked low');
        $this->assertSame(C::HIGH, $rows[$paper->id], 'a critical member priority still reads high');
        $this->assertSame(C::HIGH, $rows[$sparks->id]);
        $this->assertSame(C::MEDIUM, $rows[$unprojected->id], 'not projected: the member priority maps as before');
        $this->assertSame([$paper->id, $sparks->id], array_slice($rows->keys()->all(), 0, 2), 'high first, the more urgent member priority first');
        $this->assertSame(2, $source->counts($admin, DashboardQuery::now())['high']);

        $high = $source->rows($admin, new DashboardQuery(DashboardQuery::now()->window, false, [], null, C::HIGH), 50);
        $this->assertCount(2, $high);

        $detail = $source->detail($admin, (string) $ac->id);
        $facts = collect($detail->facts)->pluck('value', 'label');
        $this->assertSame('Medium (set by equipment priorities)', $facts['Priority']);
        $this->assertSame('Low', $facts['Requested urgency']);

        $this->assertSame('done', $source->perform($admin, (string) $ac->id, 'set_priority', ['level' => 'high'], $detail->issue->version)->outcome);
        $this->assertSame('high', $source->detail($admin, (string) $ac->id)->issue->severity);
        $this->assertSame('low', $ac->fresh()->priority, 'the member priority is never overwritten');

        $viewer = $this->adminWith(['admin.stations.view']);
        $this->assertSame('forbidden', $source->perform($viewer, (string) $ac->id, 'set_priority', ['level' => 'low'], $detail->issue->version)->outcome);
    }
}
