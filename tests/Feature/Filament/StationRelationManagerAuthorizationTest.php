<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\StationResource\RelationManagers\ApparatusesRelationManager;
use App\Filament\Resources\StationResource\RelationManagers\CapitalProjectsRelationManager;
use App\Filament\Resources\StationResource\RelationManagers\StationInventoryItemsRelationManager;
use App\Filament\Resources\StationResource\RelationManagers\Under25kProjectsRelationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Station profile relation-manager actions follow the admin capability model:
 * view-only admins never see or run them, including on the read-only view page.
 */
final class StationRelationManagerAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpStationAdmin();
    }

    public function test_view_only_admin_cannot_see_or_run_station_relation_manager_mutations(): void
    {
        $station = $this->station(1);
        $other = $this->station(2);
        $apparatus = $this->apparatus($other, 'E2');
        $assigned = $this->apparatus($station, 'E1');
        $low = $this->inventoryItem($station, 'low');
        $capital = $this->capitalProject();
        $under25k = $this->under25kProject();

        $this->actingAsAdminWith(['admin.stations.view', 'admin.fleet.view', 'admin.projects.view']);

        Livewire::test(ApparatusesRelationManager::class, $this->relationParams($station))
            ->assertTableActionHidden('assignApparatus')
            ->assertTableActionHidden('reassign', $assigned)
            ->mountTableAction('assignApparatus')
            ->assertTableActionNotMounted('assignApparatus')
            ->mountTableAction('reassign', $assigned)
            ->assertTableActionNotMounted('reassign');

        Livewire::test(StationInventoryItemsRelationManager::class, $this->relationParams($station))
            ->assertTableActionHidden('mark_ordered', $low)
            ->assertTableActionHidden('replenish', $low)
            ->assertTableActionHidden('edit', $low)
            ->mountTableAction('mark_ordered', $low)
            ->assertTableActionNotMounted('mark_ordered');

        Livewire::test(CapitalProjectsRelationManager::class, $this->relationParams($station))
            ->assertTableActionHidden('assignProject')
            ->mountTableAction('assignProject')
            ->assertTableActionNotMounted('assignProject');

        Livewire::test(Under25kProjectsRelationManager::class, $this->relationParams($station))
            ->assertTableActionHidden('assignProject')
            ->mountTableAction('assignProject')
            ->assertTableActionNotMounted('assignProject');

        self::assertSame($other->id, $apparatus->fresh()->station_id);
        self::assertSame($station->id, $assigned->fresh()->station_id);
        self::assertSame('low', $low->fresh()->status);
        self::assertNull($capital->fresh()->station_id);
        self::assertNull($under25k->fresh()->station_id);
    }

    public function test_station_manager_without_fleet_management_cannot_reassign_apparatus(): void
    {
        $station = $this->station(1);
        $assigned = $this->apparatus($station, 'E1');
        $low = $this->inventoryItem($station, 'low');

        $this->actingAsAdminWith(['admin.stations.view', 'admin.stations.manage', 'admin.fleet.view']);

        Livewire::test(ApparatusesRelationManager::class, $this->relationParams($station))
            ->assertTableActionHidden('assignApparatus')
            ->assertTableActionHidden('reassign', $assigned);

        Livewire::test(StationInventoryItemsRelationManager::class, $this->relationParams($station))
            ->assertTableActionVisible('mark_ordered', $low)
            ->callTableAction('mark_ordered', $low);

        self::assertSame('ordered', $low->fresh()->status);
    }

    public function test_authorized_manager_can_assign_apparatus_and_projects_from_the_view_page(): void
    {
        $station = $this->station(1);
        $other = $this->station(2);
        $apparatus = $this->apparatus($other, 'E2');
        $capital = $this->capitalProject();
        $under25k = $this->under25kProject();

        $this->actingAsAdminWith([
            'admin.stations.view', 'admin.stations.manage',
            'admin.fleet.view', 'admin.fleet.manage',
            'admin.projects.view', 'admin.projects.manage',
        ]);

        Livewire::test(ApparatusesRelationManager::class, $this->relationParams($station))
            ->assertTableActionVisible('assignApparatus')
            ->callTableAction('assignApparatus', data: ['apparatus_id' => $apparatus->id])
            ->assertHasNoTableActionErrors();

        Livewire::test(CapitalProjectsRelationManager::class, $this->relationParams($station))
            ->callTableAction('assignProject', data: ['project_id' => $capital->id])
            ->assertHasNoTableActionErrors();

        Livewire::test(Under25kProjectsRelationManager::class, $this->relationParams($station))
            ->callTableAction('assignProject', data: ['project_id' => $under25k->id])
            ->assertHasNoTableActionErrors();

        self::assertSame($station->id, $apparatus->fresh()->station_id);
        self::assertSame('Station 1', $apparatus->fresh()->current_location);
        self::assertSame($station->id, $capital->fresh()->station_id);
        self::assertSame($station->id, $under25k->fresh()->station_id);
    }
}
