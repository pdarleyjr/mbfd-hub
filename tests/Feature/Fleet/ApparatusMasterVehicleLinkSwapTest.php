<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Models\Apparatus;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Services\Fleet\VehicleSwapService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fleet\Concerns\BuildsVehicleSwapFixtures;
use Tests\TestCase;

/**
 * A vehicle swap moves the unit designation between truck rows and never moves a vehicle
 * number, so each master link stays on the physical truck it belongs to.
 */
final class ApparatusMasterVehicleLinkSwapTest extends TestCase
{
    use BuildsVehicleSwapFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSwapFixtures();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return list<array{int, string, ?int, ?string}> */
    private function links(): array
    {
        return Apparatus::query()->orderBy('id')->get()
            ->map(fn (Apparatus $row): array => [$row->id, $row->vehicle_number, $row->unit_master_vehicle_id, $row->master_link_status])
            ->all();
    }

    public function test_fixture_trucks_are_linked_to_masters_created_from_them(): void
    {
        $this->assertSame(3, UnitMasterVehicle::query()->count());
        foreach ([$this->unit, $this->frontline, $this->reserve] as $truck) {
            $fresh = $truck->fresh();
            $this->assertSame('linked', $fresh->master_link_status);
            $this->assertSame($fresh->vehicle_number, $fresh->masterVehicle->veh_number);
        }
    }

    public function test_a_swap_leaves_every_link_on_its_physical_truck(): void
    {
        $links = $this->links();
        $masters = UnitMasterVehicle::query()->count();
        $history = UnitMasterVehicleChange::query()->count();

        $this->app->make(VehicleSwapService::class)->moveUnitForChangeout($this->unit->fresh(), $this->frontline->fresh());

        $this->assertSame('E3', $this->unit->fresh()->designation, 'the unit really did move');
        $this->assertSame($links, $this->links());
        $this->assertSame($masters, UnitMasterVehicle::query()->count());
        $this->assertSame($history, UnitMasterVehicleChange::query()->count(), 'a swap writes no master history');
    }

    public function test_a_queued_checkout_that_names_another_truck_keeps_links_on_their_trucks(): void
    {
        $links = $this->links();

        $this->postJson($this->submissionUrl(), $this->payload(['vehicle_number' => 'V-303', 'swap_answer' => 'swap_completed']))->assertCreated();

        $this->assertSame('E2', $this->frontline->fresh()->designation);
        $this->assertSame($links, $this->links());
    }
}
