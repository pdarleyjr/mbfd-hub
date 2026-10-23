<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ApparatusInspectionExceptionResource\Pages\ListInspectionExceptions;
use App\Filament\Resources\DefectResource\Pages\ListDefects;
use App\Filament\Resources\InspectionResource\Pages\ListInspections;
use App\Filament\Resources\StationResource\Pages\ListStations;
use App\Filament\Resources\StationResource\Pages\ViewStation;
use App\Filament\Resources\StationResource\RelationManagers\ApparatusServiceTicketsRelationManager;
use App\Filament\Resources\StationResource\RelationManagers\DailyCheckoutsRelationManager;
use App\Filament\Resources\StationResource\RelationManagers\EquipmentIssuesRelationManager;
use App\Filament\Resources\StationResource\StationOperationsSection;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusServiceTicket;
use App\Models\Station;
use App\Models\StationRequest;
use App\Services\StationOperationsSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Apparatus service, Daily Checkouts, equipment issues, and station requests
 * are reachable per station from the Admin station profile, and each follows
 * the fleet/station capability of the record it shows.
 */
final class StationOperationsIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpStationAdmin();
    }

    public function test_fleet_relation_managers_require_fleet_view_capability(): void
    {
        $station = $this->station(1);

        $this->actingAsAdminWith(['admin.stations.view']);
        foreach ($this->fleetRelationManagers() as $manager) {
            self::assertFalse($manager::canViewForRecord($station, ViewStation::class), $manager.' must be hidden without admin.fleet.view');
        }

        $this->actingAsAdminWith(['admin.stations.view', 'admin.fleet.view']);
        foreach ($this->fleetRelationManagers() as $manager) {
            self::assertTrue($manager::canViewForRecord($station, ViewStation::class), $manager.' must be visible with admin.fleet.view');
        }
    }

    public function test_service_ticket_manager_lists_only_this_stations_open_tickets(): void
    {
        $station = $this->station(1);
        $other = $this->station(2);
        $engine = $this->apparatus($station, 'E1');
        $open = $this->ticket($station, $engine, 'submitted');
        $closed = $this->ticket($station, $engine, 'completed');
        $archived = $this->ticket($station, $engine, 'submitted');
        $archived->forceFill(['archived_at' => now()])->save();
        $foreign = $this->ticket($other, $this->apparatus($other, 'E2'), 'submitted');
        $this->actingAsAdminWith(['admin.stations.view', 'admin.fleet.view']);

        Livewire::test(ApparatusServiceTicketsRelationManager::class, $this->relationParams($station))
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$closed, $foreign, $archived])
            ->filterTable('open', false)
            ->assertCanSeeTableRecords([$closed])
            ->assertCanNotSeeTableRecords([$open]);
    }

    public function test_daily_checkout_manager_lists_station_checkouts_and_filters_follow_up(): void
    {
        $station = $this->station(1);
        $other = $this->station(2);
        $engine = $this->apparatus($station, 'E1');
        $accepted = $this->inspection($engine, 'accepted');
        $followUp = $this->inspection($engine, 'accepted_with_exception');
        $foreign = $this->inspection($this->apparatus($other, 'E2'), 'accepted');
        $this->actingAsAdminWith(['admin.stations.view', 'admin.fleet.view']);

        Livewire::test(DailyCheckoutsRelationManager::class, $this->relationParams($station))
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$accepted, $followUp])
            ->assertCanNotSeeTableRecords([$foreign])
            ->filterTable('needs_follow_up', true)
            ->assertCanSeeTableRecords([$followUp])
            ->assertCanNotSeeTableRecords([$accepted])
            ->resetTableFilters()
            ->filterTable('apparatus_id', (string) $engine->id)
            ->assertCanSeeTableRecords([$accepted, $followUp])
            ->sortTable('completed_at', 'asc')
            ->assertSuccessful();
    }

    public function test_equipment_issue_manager_defaults_to_unresolved_issues_for_the_station(): void
    {
        $station = $this->station(1);
        $other = $this->station(2);
        $engine = $this->apparatus($station, 'E1');
        $open = $this->defect($engine, false);
        $resolved = $this->defect($engine, true);
        $foreign = $this->defect($this->apparatus($other, 'E2'), false);
        $this->actingAsAdminWith(['admin.stations.view', 'admin.fleet.view']);

        Livewire::test(EquipmentIssuesRelationManager::class, $this->relationParams($station))
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$resolved, $foreign])
            ->filterTable('issue_type', 'missing')
            ->assertCanSeeTableRecords([$open])
            ->sortTable('reported_date', 'desc')
            ->assertSuccessful();
    }

    public function test_operations_summary_counts_only_open_work_for_the_station(): void
    {
        $station = $this->station(1);
        $other = $this->station(2);
        $engine = $this->apparatus($station, 'E1');
        $foreignEngine = $this->apparatus($other, 'E2');

        $this->stationRequest($station, 'pending');
        $this->stationRequest($station, 'completed');
        $this->stationRequest($other, 'pending');
        $this->ticket($station, $engine, 'submitted');
        $this->ticket($other, $foreignEngine, 'submitted');
        $this->defect($engine, false);
        $this->defect($engine, true);
        $this->defect($foreignEngine, false);
        $recent = $this->inspection($engine, 'accepted_with_exception');
        $this->exception($recent, 'open');
        $this->exception($this->inspection($engine, 'accepted'), 'resolved', 'miles');
        $this->inspection($engine, 'accepted', now()->subDays(3));

        $summary = app(StationOperationsSummaryService::class)->summaryFor($station->fresh());

        self::assertSame(1, $summary['open_station_requests']);
        self::assertSame(1, $summary['open_service_tickets']);
        self::assertSame(1, $summary['open_equipment_issues']);
        self::assertSame(1, $summary['open_inspection_exceptions']);
        self::assertSame(2, $summary['checkouts_last_24h']);
        self::assertNotNull($summary['last_checkout_at']);
    }

    public function test_archiving_open_requests_removes_active_counts_without_changing_workflow_or_history(): void
    {
        $station = $this->station(1);
        $engine = $this->apparatus($station, 'E1');
        $request = $this->stationRequest($station, 'pending');
        $ticket = $this->ticket($station, $engine, 'submitted');
        $request->forceFill(['archived_at' => now()])->save();
        $ticket->forceFill(['archived_at' => now()])->save();

        $summary = app(StationOperationsSummaryService::class)->summaryFor($station->fresh());
        self::assertSame(0, $summary['open_station_requests']);
        self::assertSame(0, $summary['open_service_tickets']);
        self::assertSame('pending', $request->fresh()->status);
        self::assertSame('submitted', $ticket->fresh()->status);

        $request->forceFill(['archived_at' => null])->save();
        $ticket->forceFill(['archived_at' => null])->save();
        $restored = app(StationOperationsSummaryService::class)->summaryFor($station->fresh());
        self::assertSame(1, $restored['open_station_requests']);
        self::assertSame(1, $restored['open_service_tickets']);
    }

    public function test_view_page_shows_operations_counts_with_station_filtered_links(): void
    {
        $station = $this->station(1);
        $this->ticket($station, $this->apparatus($station, 'E1'), 'submitted');
        $this->actingAsSuperAdmin();

        Livewire::test(ViewStation::class, ['record' => $station->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Station Operations')
            ->assertSee('Open apparatus service')
            ->assertSee('Checkout exceptions to review')
            ->assertSee('tableFilters%5Bstation_id%5D%5Bvalue%5D='.$station->id, false);
    }

    public function test_station_only_admin_sees_station_requests_but_not_fleet_counts(): void
    {
        $station = $this->station(1);
        $this->actingAsAdminWith(['admin.stations.view']);

        Livewire::test(ViewStation::class, ['record' => $station->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Open station requests')
            ->assertDontSee('Open apparatus service')
            ->assertDontSee('Unresolved equipment issues');
    }

    public function test_unresolved_equipment_issue_link_lists_exactly_the_counted_issues(): void
    {
        $station = $this->station(1);
        $other = $this->station(2);
        $engine = $this->apparatus($station, 'E1');
        $open = $this->defect($engine, false);
        $inProgress = $this->defect($engine, false, 'in_progress');
        $resolved = $this->defect($engine, true);
        $foreign = $this->defect($this->apparatus($other, 'E2'), false);
        $this->actingAsSuperAdmin();

        $url = StationOperationsSection::equipmentIssuesUrl($station);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame((string) $station->id, $query['tableFilters']['station_id']['value'] ?? null);

        $count = app(StationOperationsSummaryService::class)->summaryFor($station->fresh())['open_equipment_issues'];
        self::assertSame(2, $count);

        Livewire::withQueryParams($query)
            ->test(ListDefects::class)
            ->call('loadTable')
            ->assertCanSeeTableRecords([$open, $inProgress])
            ->assertCanNotSeeTableRecords([$resolved, $foreign])
            ->assertCountTableRecords($count);
    }

    public function test_unresolved_filter_on_the_equipment_issue_list_excludes_only_resolved_issues(): void
    {
        $engine = $this->apparatus($this->station(1), 'E1');
        $open = $this->defect($engine, false);
        $inProgress = $this->defect($engine, false, 'in_progress');
        $resolved = $this->defect($engine, true);
        $this->actingAsSuperAdmin();

        Livewire::test(ListDefects::class)
            ->call('loadTable')
            ->resetTableFilters()
            ->filterTable('status', null)
            ->filterTable('unresolved', true)
            ->assertCanSeeTableRecords([$open, $inProgress])
            ->assertCanNotSeeTableRecords([$resolved])
            ->filterTable('unresolved', false)
            ->assertCanSeeTableRecords([$resolved])
            ->assertCanNotSeeTableRecords([$open, $inProgress]);
    }

    public function test_station_table_and_profile_show_a_photo_or_placeholder(): void
    {
        $withPhoto = $this->station(1, ['image_path' => 'stations/photos/front.jpg']);
        $bundled = $this->station(2);
        $noArtwork = $this->station(9);
        $this->actingAsSuperAdmin();

        self::assertSame('/storage/stations/photos/front.jpg', $withPhoto->photoOrPlaceholderUrl());
        self::assertSame('/images/stations/station-2.webp', $bundled->photoOrPlaceholderUrl());
        self::assertSame(Station::PLACEHOLDER_IMAGE, $noArtwork->photoOrPlaceholderUrl());

        Livewire::test(ListStations::class)
            ->call('loadTable')
            ->assertTableColumnExists('photo')
            ->assertSee('/storage/stations/photos/front.jpg', false)
            ->assertSee('/images/stations/station-2.webp', false)
            ->assertSee(Station::PLACEHOLDER_IMAGE, false)
            ->assertSee('No photo uploaded for Station 9', false);

        Livewire::test(ViewStation::class, ['record' => $noArtwork->getRouteKey()])
            ->assertSuccessful()
            ->assertSee(Station::PLACEHOLDER_IMAGE, false)
            ->assertSee('No photo uploaded. Members see this standard station artwork.');
    }

    public function test_admin_lists_filter_daily_checkouts_and_exceptions_by_station(): void
    {
        $station = $this->station(1);
        $other = $this->station(2);
        $engine = $this->apparatus($station, 'E1');
        $mine = $this->inspection($engine, 'accepted_with_exception');
        $theirs = $this->inspection($this->apparatus($other, 'E2'), 'accepted');
        $openException = $this->exception($mine, 'open');
        $closedException = $this->exception($mine, 'dismissed', 'miles');
        $this->actingAsSuperAdmin();

        Livewire::test(ListInspections::class)
            ->call('loadTable')
            ->filterTable('station_id', (string) $station->id)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->resetTableFilters()
            ->filterTable('needs_follow_up', true)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);

        Livewire::test(ListInspectionExceptions::class)
            ->call('loadTable')
            ->filterTable('station_id', (string) $station->id)
            ->filterTable('open', true)
            ->assertCanSeeTableRecords([$openException])
            ->assertCanNotSeeTableRecords([$closedException]);
    }

    /** @return list<class-string<\Filament\Resources\RelationManagers\RelationManager>> */
    private function fleetRelationManagers(): array
    {
        return [
            ApparatusServiceTicketsRelationManager::class,
            DailyCheckoutsRelationManager::class,
            EquipmentIssuesRelationManager::class,
        ];
    }

    private function ticket(Station $station, Apparatus $apparatus, string $status): ApparatusServiceTicket
    {
        return ApparatusServiceTicket::query()->create([
            'station_id' => $station->id,
            'apparatus_id' => $apparatus->id,
            'unit_designation_snapshot' => $apparatus->designation,
            'origin' => 'station',
            'category' => 'repair',
            'title' => 'Pump check '.uniqid(),
            'description' => 'Fixture service ticket',
            'priority' => 'attention',
            'status' => $status,
        ]);
    }

    private function inspection(Apparatus $apparatus, string $processingStatus, ?\DateTimeInterface $completedAt = null): ApparatusInspection
    {
        return ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id,
            'operator_name' => 'Fixture Operator',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'results' => [],
            'review_status' => 'approved',
            'processing_status' => $processingStatus,
            'completed_at' => $completedAt ?? now(),
        ]);
    }

    private function exception(ApparatusInspection $inspection, string $status, string $field = 'engine_hours'): ApparatusInspectionException
    {
        return ApparatusInspectionException::query()->create([
            'apparatus_inspection_id' => $inspection->id,
            'apparatus_id' => $inspection->apparatus_id,
            'field' => $field,
            'reason' => 'rollback',
            'submitted_value' => 300,
            'baseline_value' => 400,
            'status' => $status,
        ]);
    }

    private function defect(Apparatus $apparatus, bool $resolved, ?string $status = null): ApparatusDefect
    {
        return ApparatusDefect::query()->create([
            'apparatus_id' => $apparatus->id,
            'compartment' => 'Cab',
            'item' => 'Fixture radio '.uniqid(),
            'issue_type' => 'missing',
            'status' => $status ?? ($resolved ? 'resolved' : 'open'),
            'resolved' => $resolved,
            'reported_date' => now()->toDateString(),
        ]);
    }

    private function stationRequest(Station $station, string $status): StationRequest
    {
        return StationRequest::query()->create([
            'station_id' => $station->id,
            'requester_name_snapshot' => 'Fixture Member',
            'request_type' => 'equipment',
            'title' => 'Radio replacement '.uniqid(),
            'description' => 'Fixture request',
            'priority' => 'normal',
            'status' => $status,
        ]);
    }
}
