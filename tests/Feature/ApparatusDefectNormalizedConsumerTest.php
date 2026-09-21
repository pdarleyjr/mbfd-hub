<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\FleetStatsWidget;
use App\Filament\Widgets\StationOperationsHubWidget;
use App\Http\Controllers\Api\AdminMetricsController;
use App\Models\AdminAlertEvent;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectRecommendation;
use App\Models\Station;
use App\Services\Display\DisplaySnapshotService;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class ApparatusDefectNormalizedConsumerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_recorded_missing_defect_is_counted_and_resolving_it_removes_it_from_unresolved_views(): void
    {
        [$station, $apparatus] = $this->stationAndApparatus();

        $defect = ApparatusDefect::recordDefect($apparatus->id, 'Cab', 'Portable radio', 'Missing', 'Radio absent.');

        $this->assertSame('open', $defect->status);
        $this->assertSame('missing', $defect->issue_type);
        $this->assertFalse($defect->resolved);
        $this->assertSame(1, ApparatusDefect::query()->unresolved()->missing()->count());
        $this->assertSame(1, ApparatusDefect::query()->criticalForReadiness()->count());
        $this->assertSame(1, app(StationOperationsHubWidget::class)->getViewData()['stationData'][$station->id]['counts']['missingDefects']);
        $this->assertSame(1, app(DisplaySnapshotService::class)->overview()['defects']['critical_missing']);
        $this->assertSame(1, count(app(DisplaySnapshotService::class)->criticalItems()['critical_defects']));
        $this->assertContains('1 critical defect open', app(DisplaySnapshotService::class)->stationDetail($station->id)['readiness']['reasons']);
        $this->assertSame(1, app(AdminMetricsController::class)->index()->getData(true)['defects']['critical']);
        $this->assertSame(1, app(AdminMetricsController::class)->index()->getData(true)['top_missing_items']['Portable radio']);
        $this->assertSame(1, $this->fleetCriticalDefectValue());
        $this->assertDatabaseHas('admin_alert_events', ['related_type' => 'apparatus_defect', 'related_id' => $defect->id, 'severity' => 'warning']);
        $this->assertStringContainsString('(missing)', AdminAlertEvent::query()->where('related_id', $defect->id)->sole()->message);
        $this->assertSame(1, ApparatusDefectRecommendation::query()->where('apparatus_defect_id', $defect->id)->count());

        $defect->update(['status' => 'in_progress']);
        $this->assertSame(1, ApparatusDefect::query()->criticalForReadiness()->count());

        $defect->update(['status' => 'resolved']);
        Cache::forget(DisplaySnapshotService::SNAPSHOT_CACHE_KEY);

        $this->assertTrue($defect->fresh()->resolved);
        $this->assertSame(0, ApparatusDefect::query()->unresolved()->count());
        $this->assertSame(0, app(StationOperationsHubWidget::class)->getViewData()['stationData'][$station->id]['counts']['missingDefects']);
        $this->assertSame(0, app(DisplaySnapshotService::class)->overview()['defects']['critical_missing']);
        $this->assertSame(0, app(AdminMetricsController::class)->index()->getData(true)['defects']['critical']);
        $this->assertSame(0, $this->fleetCriticalDefectValue());
    }

    public function test_a_recorded_damaged_defect_is_attention_without_being_counted_as_missing(): void
    {
        [$station, $apparatus] = $this->stationAndApparatus();

        $defect = ApparatusDefect::recordDefect($apparatus->id, 'Cab', 'Scene light', 'Damaged', 'Lens cracked.');

        $this->assertSame('open', $defect->status);
        $this->assertSame('damaged', $defect->issue_type);
        $this->assertFalse($defect->resolved);
        $this->assertSame(1, ApparatusDefect::query()->unresolved()->damaged()->count());
        $this->assertSame(1, ApparatusDefect::query()->criticalForReadiness()->count());
        $this->assertSame(0, app(StationOperationsHubWidget::class)->getViewData()['stationData'][$station->id]['counts']['missingDefects']);
        $this->assertSame(0, app(DisplaySnapshotService::class)->overview()['defects']['critical_missing']);
        $this->assertSame(0, app(AdminMetricsController::class)->index()->getData(true)['defects']['critical']);
        $this->assertSame(0, $this->fleetCriticalDefectValue());
        $this->assertDatabaseHas('admin_alert_events', ['related_type' => 'apparatus_defect', 'related_id' => $defect->id, 'severity' => 'info']);
        $this->assertStringContainsString('(damaged)', AdminAlertEvent::query()->where('related_id', $defect->id)->sole()->message);
        $this->assertSame(1, ApparatusDefectRecommendation::query()->where('apparatus_defect_id', $defect->id)->count());
    }

    /** @return array{Station, Apparatus} */
    private function stationAndApparatus(): array
    {
        $station = Station::query()->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '1 Test Street',
            'is_active' => true,
        ]);
        $apparatus = Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => 'E1',
            'designation' => 'E1',
            'vehicle_number' => 'E1',
            'make' => 'Fixture',
            'model' => 'Engine',
            'status' => 'In Service',
            'daily_checkout_requirement' => 'required',
        ]);

        return [$station, $apparatus];
    }

    private function fleetCriticalDefectValue(): int
    {
        $widget = new class extends FleetStatsWidget
        {
            /** @return array<Stat> */
            public function stats(): array
            {
                return $this->getStats();
            }
        };

        return (int) collect($widget->stats())
            ->first(fn (Stat $stat): bool => $stat->getLabel() === 'Critical Defects')
            ->getValue();
    }
}
