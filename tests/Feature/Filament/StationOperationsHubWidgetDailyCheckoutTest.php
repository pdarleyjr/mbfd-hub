<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Widgets\StationOperationsHubWidget;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusServiceTicket;
use App\Models\Station;
use App\Models\StationInspection;
use App\Models\StationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StationOperationsHubWidgetDailyCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'America/New_York'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_preserves_each_actual_checkout_submission_without_compliance_aggregation(): void
    {
        $station = $this->station(1);
        $apparatus = $this->apparatus($station, 'E17');
        $first = $this->inspection($apparatus, 'approved', '2026-09-23 08:15:00');
        $second = $this->inspection($apparatus, 'pending_review', '2026-09-23 09:45:00');

        $data = app(StationOperationsHubWidget::class)->getViewData();
        $stationData = $data['stationData'][$station->id];

        $this->assertSame([$second->id, $first->id], collect($data['department']['checkouts'])->pluck('id')->all());
        $this->assertSame([$second->id, $first->id], collect($stationData['activity'])->where('type', 'daily_checkout')->pluck('id')->all());
        $this->assertArrayNotHasKey('dailyCheckout', $stationData);
        $this->assertArrayNotHasKey('readiness', $stationData);
    }

    public function test_it_represents_an_empty_activity_window_and_persistent_oos_state_without_a_percentage(): void
    {
        $station = $this->station(1);
        $this->apparatus($station, 'E18', 'Out of Service');

        $stationData = app(StationOperationsHubWidget::class)->getViewData()['stationData'][$station->id];

        $this->assertSame([], $stationData['activity']);
        $this->assertSame(1, $stationData['apparatus']['out_of_service']);
        $this->assertSame('apparatus_status', collect($stationData['exceptions'])->first()['type']);
        $this->assertArrayNotHasKey('percent', $stationData);
    }

    public function test_it_uses_authoritative_display_buckets_and_station_scoped_missing_defects(): void
    {
        $station = $this->station(1);
        $secondStation = $this->station(2);
        $available = $this->apparatus($station, 'E1', 'available');
        $outOfService = $this->apparatus($station, 'R1', 'OOS');
        $this->apparatus($station, 'T1', 'in-maintenance');
        $this->apparatus($secondStation, 'E2', 'In Service');

        ApparatusDefect::recordDefect($available->id, 'Cab', 'Missing radio', 'Missing', 'Fixture radio absent.');
        ApparatusDefect::recordDefect($outOfService->id, 'Cab', 'Damaged light', 'Damaged', 'Fixture lens cracked.');

        $data = app(StationOperationsHubWidget::class)->getViewData();

        $this->assertSame(2, $data['department']['apparatus']['in_service']);
        $this->assertSame(1, $data['department']['apparatus']['out_of_service']);
        $this->assertSame(1, $data['department']['apparatus']['maintenance']);
        $this->assertSame(1, $data['stationData'][$station->id]['counts']['missingDefects']);
        $this->assertSame(1, $data['stationData'][$station->id]['apparatus']['in_service']);
        $this->assertSame(1, $data['stationData'][$station->id]['apparatus']['out_of_service']);
        $this->assertSame(1, $data['stationData'][$station->id]['apparatus']['maintenance']);
    }

    public function test_it_uses_only_the_configured_operational_station_scope_everywhere(): void
    {
        foreach ([1, 2, 3, 4, 6, 99] as $number) {
            $this->station($number);
        }

        $data = app(StationOperationsHubWidget::class)->getViewData();

        $this->assertSame([1, 2, 3, 4, 6], collect($data['stations'])->pluck('station_number')->all());
        $this->assertCount(5, $data['stationData']);
        $this->assertNotContains(99, collect($data['stations'])->pluck('station_number')->all());
    }

    public function test_it_keeps_operational_records_actionable_and_station_scoped(): void
    {
        $station = $this->station(1);
        $otherStation = $this->station(2);
        $apparatus = $this->apparatus($station, 'E1');
        $this->apparatus($otherStation, 'E2');

        $inspection = StationInspection::query()->create(['station_id' => $station->id, 'inspection_date' => now(), 'inspection_type' => 'weekly', 'overall_status' => 'pass', 'form_data' => []]);
        $request = StationRequest::query()->create(['station_id' => $station->id, 'requester_name_snapshot' => 'Fixture Member', 'request_type' => 'equipment', 'title' => 'Radio replacement', 'description' => 'Fixture request', 'priority' => 'high', 'status' => 'submitted']);
        $ticket = ApparatusServiceTicket::query()->create(['station_id' => $station->id, 'apparatus_id' => $apparatus->id, 'unit_designation_snapshot' => 'E1', 'origin' => 'station', 'category' => 'repair', 'title' => 'Pump check', 'description' => 'Fixture service ticket', 'priority' => 'attention', 'status' => 'submitted']);
        StationRequest::query()->create(['station_id' => $otherStation->id, 'requester_name_snapshot' => 'Other Member', 'request_type' => 'equipment', 'title' => 'Other station', 'description' => 'Other fixture request', 'priority' => 'normal', 'status' => 'submitted']);

        $data = app(StationOperationsHubWidget::class)->getViewData()['stationData'][$station->id];
        $activity = collect($data['activity']);

        $this->assertStringContainsString('/admin/station-requests/'.$request->id, $activity->firstWhere('type', 'station_request')['url']);
        $this->assertStringContainsString('/admin/apparatus-service-tickets/'.$ticket->id, $activity->firstWhere('type', 'service_ticket')['url']);
        $this->assertStringContainsString('/admin/station-inspections/'.$inspection->id, $activity->firstWhere('type', 'station_inspection')['url']);
        $this->assertSame(1, $data['stationNumber']);
        $this->assertStringContainsString('tableFilters%5Bstation_id%5D%5Bvalue%5D='.$station->id, $data['links']['requests']);
    }

    public function test_it_keeps_all_current_window_activity_and_excludes_older_history(): void
    {
        $station = $this->station(1);
        foreach (range(1, 7) as $minute) {
            Carbon::setTestNow(Carbon::parse("2026-09-23 09:0{$minute}:00", 'America/New_York'));
            StationInspection::query()->create(['station_id' => $station->id, 'inspection_date' => now(), 'inspection_type' => 'daily', 'overall_status' => 'pass', 'form_data' => []]);
        }
        Carbon::setTestNow(Carbon::parse('2026-09-22 07:00:00', 'America/New_York'));
        StationInspection::query()->create(['station_id' => $station->id, 'inspection_date' => now(), 'inspection_type' => 'weekly', 'overall_status' => 'needs_attention', 'form_data' => []]);
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'America/New_York'));

        $activity = collect(app(StationOperationsHubWidget::class)->getViewData()['stationData'][$station->id]['activity']);

        $this->assertCount(7, $activity);
        $this->assertSame(7, $activity->where('type', 'station_inspection')->count());
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

    private function apparatus(Station $station, string $unit, string $status = 'In Service'): Apparatus
    {
        return Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => $unit,
            'designation' => $unit,
            'vehicle_number' => $unit,
            'make' => 'Fixture',
            'model' => 'Engine',
            'status' => $status,
        ]);
    }

    private function inspection(Apparatus $apparatus, string $reviewStatus, string $completedAt): ApparatusInspection
    {
        return ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id,
            'client_submission_id' => (string) Str::uuid(),
            'operator_name' => 'Fixture Operator',
            'rank' => 'Firefighter',
            'shift' => 'A-Day',
            'unit_number' => $apparatus->unit_id,
            'review_status' => $reviewStatus,
            'completed_at' => Carbon::parse($completedAt, 'America/New_York')->utc(),
        ]);
    }
}
