<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Widgets\StationOperationsHubWidget;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusServiceTicket;
use App\Models\DailyCheckoutLedgerCutover;
use App\Models\Station;
use App\Models\StationInspection;
use App\Models\StationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StationOperationsHubWidgetDailyCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_the_canonical_daily_checkout_matrix_not_raw_inspection_rows(): void
    {
        $station = Station::query()->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '1 Test Street',
            'is_active' => true,
        ]);

        $checked = $this->apparatus($station, 'E17A');
        $attention = $this->apparatus($station, 'E17B');
        $reviewPending = $this->apparatus($station, 'E17C');
        $notChecked = $this->apparatus($station, 'E17D');
        $outOfService = $this->apparatus($station, 'E17E', 'Out of Service');
        $this->activateCutover([$checked, $attention, $reviewPending, $notChecked, $outOfService]);

        $this->inspection($checked, 'approved');
        $this->inspection($attention, 'approved');
        $this->inspection($reviewPending, 'pending_review');
        $this->inspection($outOfService, 'approved');

        ApparatusDefect::query()->create([
            'apparatus_id' => $attention->id,
            'compartment' => 'Cab',
            'item' => 'Fixture radio',
            'status' => 'Missing',
            'resolved' => false,
        ]);

        $data = app(StationOperationsHubWidget::class)->getViewData();
        $stationData = $data['stationData'][$station->id];
        $dailyCheckout = $stationData['dailyCheckout'];
        $matrix = collect($dailyCheckout['matrix'])->keyBy('apparatus_id');

        $this->assertSame(4, $dailyCheckout['required_total']);
        $this->assertSame(1, $dailyCheckout['checked']);
        $this->assertSame(1, $dailyCheckout['attention']);
        $this->assertSame(1, $dailyCheckout['review_pending']);
        $this->assertSame(1, $dailyCheckout['not_checked']);
        $this->assertSame(2, $dailyCheckout['completed']);
        $this->assertSame(1, $dailyCheckout['out_of_service']);
        $this->assertSame(50.0, $dailyCheckout['completion_percent']);
        $this->assertNotSame((int) $dailyCheckout['completion_percent'], $stationData['readiness']['percent']);
        $this->assertNotSame('UNKNOWN', $stationData['readiness']['status']);
        $this->assertTrue($dailyCheckout['completion_available']);
        $this->assertSame('out_of_service', $matrix[$outOfService->id]['state']);
        $this->assertFalse($matrix[$outOfService->id]['included_in_required_total']);
        $this->assertSame('review_pending', $matrix[$reviewPending->id]['state']);
        $this->assertSame('not_checked', $matrix[$notChecked->id]['state']);
        $this->assertSame(2, $stationData['counts']['dailyCheckoutCompleted']);
        $this->assertArrayNotHasKey('vehicleInspections', $stationData);
    }

    public function test_it_explicitly_marks_a_zero_required_denominator_as_unavailable(): void
    {
        $station = Station::query()->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '1 Test Street',
            'is_active' => true,
        ]);

        $this->apparatus($station, 'E18A', 'Out of Service');
        $this->apparatus($station, 'E18B', 'In Service', 'exempt');

        $data = app(StationOperationsHubWidget::class)->getViewData();
        $stationData = $data['stationData'][$station->id];
        $dailyCheckout = $stationData['dailyCheckout'];

        $this->assertSame(0, $dailyCheckout['required_total']);
        $this->assertSame(0, $dailyCheckout['completed']);
        $this->assertNull($dailyCheckout['completion_percent']);
        $this->assertFalse($dailyCheckout['completion_available']);
        $this->assertSame('No required apparatus — completion unavailable', $stationData['dailyCheckoutSubtitle']);
    }

    public function test_it_uses_the_display_status_buckets_and_missing_defects_for_station_operations(): void
    {
        $station = Station::query()->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '1 Test Street',
            'is_active' => true,
        ]);
        $secondStation = Station::query()->create([
            'station_number' => 2,
            'name' => 'Station 2',
            'address' => '2 Test Street',
            'is_active' => true,
        ]);

        $available = $this->apparatus($station, 'E1', 'available');
        $outOfService = $this->apparatus($station, 'R1', 'OOS');
        $maintenance = $this->apparatus($station, 'T1', 'in-maintenance');
        $this->apparatus($secondStation, 'E2', 'In Service');

        ApparatusDefect::query()->create([
            'apparatus_id' => $available->id,
            'compartment' => 'Cab',
            'item' => 'Missing radio',
            'status' => 'Missing',
            'resolved' => false,
        ]);
        ApparatusDefect::query()->create([
            'apparatus_id' => $outOfService->id,
            'compartment' => 'Cab',
            'item' => 'Damaged light',
            'status' => 'Damaged',
            'resolved' => false,
        ]);

        $widget = app(StationOperationsHubWidget::class);
        $widget->selectedStationId = (string) $station->id;
        $data = $widget->getViewData();

        $this->assertSame([(int) $station->id], collect($data['stations'])->pluck('id')->all());
        $this->assertSame(2, $data['department']['apparatus']['in_service']);
        $this->assertSame(1, $data['department']['apparatus']['out_of_service']);
        $this->assertSame(1, $data['department']['apparatus']['maintenance']);
        $this->assertSame(1, $data['department']['work']['missing']);
        $this->assertSame(1, $data['stationData'][$station->id]['counts']['missingDefects']);
        $this->assertSame(1, $data['stationData'][$station->id]['apparatus']['in_service']);
        $this->assertSame(1, $data['stationData'][$station->id]['apparatus']['out_of_service']);
        $this->assertSame(1, $data['stationData'][$station->id]['apparatus']['maintenance']);
    }

    public function test_it_uses_only_the_configured_operational_station_scope_everywhere(): void
    {
        foreach ([1, 2, 3, 4, 6, 99] as $number) {
            Station::query()->create([
                'station_number' => $number,
                'name' => "Station {$number}",
                'address' => "{$number} Test Street",
                'is_active' => true,
            ]);
        }

        $data = app(StationOperationsHubWidget::class)->getViewData();

        $this->assertSame([1, 2, 3, 4, 6], collect($data['stationOptions'])->pluck('station_number')->all());
        $this->assertSame([1, 2, 3, 4, 6], collect($data['stations'])->pluck('station_number')->all());
        $this->assertCount(5, $data['stationData']);
    }

    public function test_it_keeps_recent_records_actionable_and_station_scoped(): void
    {
        $station = Station::query()->create(['station_number' => 1, 'name' => 'Station 1', 'address' => '1 Test Street', 'is_active' => true]);
        $otherStation = Station::query()->create(['station_number' => 2, 'name' => 'Station 2', 'address' => '2 Test Street', 'is_active' => true]);
        $apparatus = $this->apparatus($station, 'E1');
        $this->apparatus($otherStation, 'E2');

        $inspection = StationInspection::query()->create(['station_id' => $station->id, 'inspection_date' => now(), 'inspection_type' => 'weekly', 'overall_status' => 'pass', 'form_data' => []]);
        $request = StationRequest::query()->create(['station_id' => $station->id, 'requester_name_snapshot' => 'Fixture Member', 'request_type' => 'equipment', 'title' => 'Radio replacement', 'description' => 'Fixture request', 'priority' => 'high', 'status' => 'submitted']);
        $ticket = ApparatusServiceTicket::query()->create(['station_id' => $station->id, 'apparatus_id' => $apparatus->id, 'unit_designation_snapshot' => 'E1', 'origin' => 'station', 'category' => 'repair', 'title' => 'Pump check', 'description' => 'Fixture service ticket', 'priority' => 'attention', 'status' => 'submitted']);
        StationRequest::query()->create(['station_id' => $otherStation->id, 'requester_name_snapshot' => 'Other Member', 'request_type' => 'equipment', 'title' => 'Other station', 'description' => 'Other fixture request', 'priority' => 'normal', 'status' => 'submitted']);

        $data = app(StationOperationsHubWidget::class)->getViewData()['stationData'][$station->id];
        $activity = collect($data['recentActivity']);

        $this->assertStringContainsString('/admin/station-requests/'.$request->id, $activity->firstWhere('type', 'station_request')['url']);
        $this->assertStringContainsString('/admin/apparatus-service-tickets/'.$ticket->id, $activity->firstWhere('type', 'service_ticket')['url']);
        $this->assertStringContainsString('/admin/station-inspections/'.$inspection->id, $activity->firstWhere('type', 'station_inspection')['url']);
        $this->assertSame(1, $data['stationNumber']);
        $this->assertStringContainsString('tableFilters%5Bstation_id%5D%5Bvalue%5D='.$station->id, $data['links']['requests']);
    }

    public function test_it_ranks_recent_inspections_per_station_without_starving_older_station_history(): void
    {
        $first = Station::query()->create(['station_number' => 1, 'name' => 'Station 1', 'address' => '1 Test Street', 'is_active' => true]);
        $second = Station::query()->create(['station_number' => 2, 'name' => 'Station 2', 'address' => '2 Test Street', 'is_active' => true]);
        foreach (range(1, 7) as $day) {
            StationInspection::query()->create(['station_id' => $first->id, 'inspection_date' => now()->subDays($day), 'inspection_type' => 'daily', 'overall_status' => 'pass', 'form_data' => []]);
        }
        $older = StationInspection::query()->create(['station_id' => $second->id, 'inspection_date' => now()->subDays(30), 'inspection_type' => 'weekly', 'overall_status' => 'needs_attention', 'form_data' => []]);

        $data = app(StationOperationsHubWidget::class)->getViewData()['stationData'];

        $this->assertCount(4, $data[$first->id]['recentInspections']);
        $this->assertSame($older->id, $data[$second->id]['recentInspections'][0]['id']);
    }

    private function apparatus(
        Station $station,
        string $unit,
        string $status = 'In Service',
        string $requirement = 'required',
    ): Apparatus {
        return Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => $unit,
            'designation' => $unit,
            'vehicle_number' => $unit,
            'make' => 'Fixture',
            'model' => 'Engine',
            'status' => $status,
            'daily_checkout_requirement' => $requirement,
        ]);
    }

    private function inspection(Apparatus $apparatus, string $reviewStatus): void
    {
        ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id,
            'client_submission_id' => (string) Str::uuid(),
            'operator_name' => 'Fixture Operator',
            'rank' => 'Firefighter',
            'shift' => 'A-Day',
            'unit_number' => $apparatus->unit_id,
            'review_status' => $reviewStatus,
            'completed_at' => now(),
        ]);
    }

    /** @param list<Apparatus> $apparatuses */
    private function activateCutover(array $apparatuses): void
    {
        $snapshot = collect($apparatuses)
            ->sortBy('id')
            ->map(static fn (Apparatus $apparatus): array => [
                'id' => (int) $apparatus->id,
                'status' => $apparatus->status,
            ])
            ->values()
            ->all();
        $encodedSnapshot = json_encode($snapshot, JSON_THROW_ON_ERROR);

        DailyCheckoutLedgerCutover::query()->create([
            'ledger' => DailyCheckoutLedgerCutover::LEDGER,
            'release_sha' => str_repeat('f', 40),
            'source' => DailyCheckoutLedgerCutover::SOURCE,
            'activated_at' => now()->subDay(),
            'apparatus_status_snapshot' => $snapshot,
            'snapshot_sha256' => hash('sha256', $encodedSnapshot),
            'apparatus_count' => count($snapshot),
        ]);
    }
}
