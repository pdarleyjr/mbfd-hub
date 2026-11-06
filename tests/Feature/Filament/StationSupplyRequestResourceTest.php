<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\StationSupplyRequestResource;
use App\Filament\Resources\StationSupplyRequestResource\Pages\ListStationSupplyRequests;
use App\Models\Station;
use App\Models\StationSupplyRequest;
use App\Models\SupportTriage\SupportTriageLevel;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class StationSupplyRequestResourceTest extends TestCase
{
    use RefreshDatabase;

    private Station $stationOne;

    private Station $stationTwo;

    private Station $stationThree;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();
        $this->stationOne = $this->station(1);
        $this->stationTwo = $this->station(2);
        $this->stationThree = $this->station(3);
    }

    public function test_staff_see_requests_from_every_station_with_priority_and_the_automated_reply(): void
    {
        $this->actingAsStaff(['admin.stations.view']);
        $soap = $this->supply($this->stationOne, 'Need dish soap for the kitchen', ['ai_receipt_text' => 'Thanks, Support Services has your request.', 'ai_receipt_at' => now()]);
        $gloves = $this->supply($this->stationTwo, 'Gloves are out of size large', ['public_response' => 'Ordered, arriving Friday.', 'status' => 'ordered']);
        $unrated = $this->supply($this->stationThree, 'New hose nozzle');
        $this->level($soap, 'low');
        $this->level($gloves, 'high');

        $component = Livewire::test(ListStationSupplyRequests::class)
            ->call('loadTable')
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$soap, $gloves, $unrated])
            ->assertSee('Station 1')
            ->assertSee('Station 2')
            ->assertSee('Station 3')
            ->assertSee('Support Services (automated)')
            ->assertSee('Thanks, Support Services has your request.')
            ->assertSee('Ordered, arriving Friday.')
            ->assertSee('Not set');

        $levels = $component->instance()->getTableRecords()->pluck('priority_level', 'id')->all();
        ksort($levels);
        $this->assertSame([$soap->id => 'low', $gloves->id => 'high', $unrated->id => null], $levels);
    }

    public function test_the_list_reads_priority_without_one_query_per_row(): void
    {
        $this->actingAsStaff(['admin.stations.view']);
        foreach (range(1, 2) as $n) {
            $this->level($this->supply($this->stationOne, "Supply need {$n}"), 'medium');
        }
        $load = fn () => Livewire::test(ListStationSupplyRequests::class)->call('loadTable')->assertSuccessful();
        $load(); // warm caches (permissions, config) so only per-row work differs
        $few = $this->countQueries($load);
        foreach (range(3, 14) as $n) {
            $this->level($this->supply($this->stationTwo, "Supply need {$n}"), 'high');
        }
        $many = $this->countQueries($load);
        $this->assertSame($few, $many, 'Twelve more rows must not add queries (priority is read with the rows).');
    }

    public function test_open_requests_come_first_then_newest_first(): void
    {
        $this->actingAsStaff(['admin.stations.view']);
        $oldOpen = $this->supply($this->stationOne, 'Old open request', ['created_at' => now()->subDays(5)]);
        $newOpen = $this->supply($this->stationOne, 'New open request', ['created_at' => now()->subDay()]);
        $newReplenished = $this->supply($this->stationOne, 'New finished request', ['status' => 'replenished', 'created_at' => now()]);

        Livewire::test(ListStationSupplyRequests::class)
            ->call('loadTable')
            ->assertCanSeeTableRecords([$newOpen, $oldOpen, $newReplenished], inOrder: true);
    }

    public function test_a_user_without_station_access_is_denied(): void
    {
        $this->actingAsStaff([]);
        $this->supply($this->stationOne, 'Hidden request');

        $this->assertFalse(StationSupplyRequestResource::canViewAny());
        $this->assertFalse(StationSupplyRequestResource::canExportTable());
        $this->get(StationSupplyRequestResource::getUrl('index'))->assertForbidden();
        Livewire::test(ListStationSupplyRequests::class)->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_sign_in(): void
    {
        $this->get('/admin/station-supply-requests')->assertRedirect();
    }

    public function test_status_station_priority_and_text_filters_work(): void
    {
        $this->actingAsStaff(['admin.stations.view']);
        $soap = $this->supply($this->stationOne, 'Need dish soap', ['created_by_name' => 'Pat Lee']);
        $gloves = $this->supply($this->stationTwo, 'Need gloves', ['status' => 'ordered']);
        $nozzle = $this->supply($this->stationThree, 'Need a nozzle');
        $this->level($soap, 'low');
        $this->level($gloves, 'high');

        Livewire::test(ListStationSupplyRequests::class)->call('loadTable')
            ->filterTable('status', 'ordered')
            ->assertCanSeeTableRecords([$gloves])->assertCanNotSeeTableRecords([$soap, $nozzle])
            ->resetTableFilters()
            ->filterTable('station_id', $this->stationOne->id)
            ->assertCanSeeTableRecords([$soap])->assertCanNotSeeTableRecords([$gloves, $nozzle])
            ->resetTableFilters()
            ->filterTable('priority_level', 'high')
            ->assertCanSeeTableRecords([$gloves])->assertCanNotSeeTableRecords([$soap, $nozzle])
            ->resetTableFilters()
            ->filterTable('priority_level', 'not_set')
            ->assertCanSeeTableRecords([$nozzle])->assertCanNotSeeTableRecords([$soap, $gloves])
            ->resetTableFilters()
            ->searchTable('gloves')
            ->assertCanSeeTableRecords([$gloves])->assertCanNotSeeTableRecords([$soap, $nozzle])
            ->searchTable('Pat Lee')
            ->assertCanSeeTableRecords([$soap])->assertCanNotSeeTableRecords([$gloves, $nozzle]);
    }

    public function test_archived_requests_are_hidden_until_asked_for(): void
    {
        $this->actingAsStaff(['admin.stations.view']);
        $active = $this->supply($this->stationOne, 'Active need');
        $archived = $this->supply($this->stationOne, 'Archived need', ['archived_at' => now()]);

        Livewire::test(ListStationSupplyRequests::class)->call('loadTable')
            ->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$archived])
            ->filterTable('archive_state', 'all')
            ->assertCanSeeTableRecords([$active, $archived]);
    }

    public function test_export_actions_are_offered_to_staff_who_can_view(): void
    {
        $this->actingAsStaff(['admin.stations.view']);
        $this->supply($this->stationOne, 'Export me');

        Livewire::test(ListStationSupplyRequests::class)->call('loadTable')
            ->assertTableActionVisible('export')
            ->assertTableBulkActionExists('export_selected');
    }

    public function test_viewers_cannot_change_requests_but_managers_use_the_same_workflow_as_the_station_tab(): void
    {
        $request = null;
        $this->actingAsStaff(['admin.stations.view']);
        $request = $this->supply($this->stationOne, 'Needs a decision');

        Livewire::test(ListStationSupplyRequests::class)->call('loadTable')
            ->assertTableActionHidden('edit', $request)
            ->assertTableActionHidden('archive', $request)
            ->assertTableActionVisible('view', $request);

        $this->actingAsStaff(['admin.stations.view', 'admin.stations.manage']);
        Livewire::test(ListStationSupplyRequests::class)->call('loadTable')
            ->assertTableActionVisible('edit', $request)
            ->callTableAction('edit', $request, ['status' => 'ordered', 'admin_notes' => 'On the Friday order.', 'public_response' => 'Ordered.'])
            ->assertHasNoTableActionErrors();

        $request->refresh();
        $this->assertSame('ordered', $request->status);
        $this->assertSame('Ordered.', $request->public_response);
        $this->assertDatabaseHas('station_inventory_audits', ['station_id' => $this->stationOne->id, 'action' => 'request_updated']);

        Livewire::test(ListStationSupplyRequests::class)->call('loadTable')
            ->callTableAction('archive', $request, ['reason' => 'Done'])
            ->assertHasNoTableActionErrors();
        $this->assertTrue($request->refresh()->isArchived());
    }

    public function test_the_view_dialog_shows_the_automated_reply_labelled_as_not_a_staff_reply(): void
    {
        $this->actingAsStaff(['admin.stations.view']);
        $request = $this->supply($this->stationOne, 'Need paper towels', ['ai_receipt_text' => 'Got it. Support Services will follow up.', 'ai_receipt_at' => now()]);
        $this->level($request, 'medium');

        Livewire::test(ListStationSupplyRequests::class)->call('loadTable')
            ->mountTableAction('view', $request)
            ->assertSee('Support Services (automated)')
            ->assertSee('Automatic receipt only. Not a staff reply or approval.')
            ->assertSee('Got it. Support Services will follow up.')
            ->assertSee('Medium');
    }

    public function test_it_sits_in_the_stations_group_without_its_own_icon_or_badge(): void
    {
        $this->actingAsStaff(['admin.stations.view']);

        $this->assertSame('Stations', StationSupplyRequestResource::getNavigationGroup());
        $this->assertSame('Supply Requests', StationSupplyRequestResource::getNavigationLabel());
        $this->assertNull(StationSupplyRequestResource::getNavigationIcon());
        $this->assertNull(StationSupplyRequestResource::getNavigationBadge());
        $this->assertFalse(StationSupplyRequestResource::canCreate());
    }

    /** @param list<string> $permissions */
    private function actingAsStaff(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create(['account_status' => 'active']);
        $user->givePermissionTo(collect(['admin.access', ...$permissions])
            ->map(fn (string $permission) => Permission::findOrCreate($permission, 'web')));
        $this->actingAs($user);

        return $user;
    }

    private function station(int $number): Station
    {
        return Station::query()->create([
            'station_number' => $number,
            'address' => "{$number} Test Street",
            'city' => 'Miami Beach',
            'state' => 'FL',
            'zip_code' => '33139',
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function supply(Station $station, string $text, array $attributes = []): StationSupplyRequest
    {
        $request = new StationSupplyRequest;
        $request->forceFill(array_merge([
            'station_id' => $station->id,
            'request_text' => $text,
            'status' => 'open',
            'created_by_name' => 'Pat Smith',
            'created_by_shift' => 'A',
        ], $attributes))->save();

        return $request->refresh();
    }

    private function level(StationSupplyRequest $request, string $level): void
    {
        SupportTriageLevel::query()->create([
            'subject_type' => 'station_supply_request',
            'subject_id' => $request->id,
            'auto_level' => $level,
            'level' => $level,
            'policy_version' => 1,
            'computed_at' => now(),
        ]);
    }

    /** @var list<string> */
    private array $lastQueries = [];

    private function countQueries(callable $callback): int
    {
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $callback();
        $this->lastQueries = array_map(fn (array $query): string => (string) $query['query'], \Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        return count($this->lastQueries);
    }
}
