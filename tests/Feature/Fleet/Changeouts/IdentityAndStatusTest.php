<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Services\Fleet\VehicleSwapService;
use App\Support\Fleet\FleetVehicleCatalog;
use Filament\Facades\Filament;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A status describes a truck and a label names one. Neither is handed to the wrong truck by a changeout.
 */
final class IdentityAndStatusTest extends ChangeoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useSmallChecklists();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function statuses(): array
    {
        return [
            'a healthy unit onto a reserve' => ['In Service', 'Reserve'],
            'onto an available truck' => ['In Service', 'Available'],
            'leaving a failed truck' => ['Maintenance', 'Reserve'],
            'onto a truck in the shop' => ['In Service', 'Maintenance'],
            'onto an out of service truck' => ['In Service', 'Out of Service'],
            'from out of service to out of service' => ['Out of Service', 'Out of Service'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_the_new_truck_is_in_service_and_the_old_truck_is_out_of_service_whatever_they_were(string $unitStatus, string $truckStatus): void
    {
        $this->unit->forceFill(['status' => $unitStatus])->save();
        $this->reserve->forceFill(['status' => $truckStatus])->save();

        app(VehicleSwapService::class)->moveUnitForChangeout($this->unit->fresh(), $this->reserve->fresh());

        $this->assertSame('In Service', $this->reserve->fresh()->getAttribute('status'));
        $this->assertSame('Out of Service', $this->unit->fresh()->getAttribute('status'));
    }

    public function test_both_trucks_are_at_the_station_the_unit_is_at(): void
    {
        $this->moveToReserve()->assertCreated();

        $this->assertSame(['Station 2', 'Station 2'], [$this->reserve->fresh()->getAttribute('current_location'), $this->unit->fresh()->getAttribute('current_location')]);
        $this->assertSame($this->unit->fresh()->station_id, $this->reserve->fresh()->station_id);
    }

    public function test_a_traded_frontline_unit_keeps_its_own_name_and_station(): void
    {
        $from = $this->reserveEngine('44', ['unit_id' => 'E7', 'designation' => 'E 7', 'name' => 'ENGINE E7', 'slug' => 'e7', 'daily_checkout_requirement' => 'required', 'status' => 'In Service']);
        $to = $this->reserveEngine('55', ['unit_id' => 'E55', 'designation' => 'E 55', 'name' => 'ENGINE E55', 'slug' => 'e55', 'daily_checkout_requirement' => 'required', 'status' => 'In Service', 'station_id' => $this->unit->station_id]);
        $from->forceFill(['station_id' => null])->save();

        app(VehicleSwapService::class)->moveUnitForChangeout($from->fresh(), $to->fresh());

        $displaced = $from->fresh();
        $this->assertSame(['E55', 'E 55', 'ENGINE E55', 'e55'], [$displaced->unit_id, $displaced->designation, $displaced->name, $displaced->slug], 'A number in a frontline label is its name, never rewritten.');
        $this->assertSame($this->unit->station_id, $displaced->station_id, 'It stays on its own station page.');
        $this->assertSame('Out of Service', $displaced->getAttribute('status'));
        $this->assertSame('e7', $to->fresh()->slug);
        $this->assertSame('In Service', $to->fresh()->getAttribute('status'));
    }

    public function test_a_row_that_never_moved_a_truck_raises_no_alert(): void
    {
        $this->moveToReserve()->assertCreated();
        ApparatusChangeout::query()->latest('id')->firstOrFail()->update(['status' => 'rejected']);

        $this->assertSame(0, ApparatusChangeout::query()->unacknowledged()->count());
    }

    public function test_a_rewritten_label_another_row_still_carries_gets_its_own(): void
    {
        $to = $this->reserveEngine('1033', ['unit_id' => 'R-1033', 'designation' => 'R-1033', 'name' => 'RESCUE 1033', 'slug' => 'r1033']);
        $from = $this->reserveEngine('17503', ['unit_id' => 'E44', 'designation' => 'E44', 'name' => 'Engine 44', 'slug' => 'e44']);
        $this->reserveEngine('9999', ['unit_id' => 'R-17503', 'designation' => 'R-17503', 'name' => 'Old label', 'slug' => 'r17503']);

        app(VehicleSwapService::class)->moveUnitForChangeout($from->fresh(), $to->fresh());

        $this->assertSame('R-17503-'.$from->id, $from->fresh()->unit_id);
        $this->assertSame('r17503-'.$from->id, $from->fresh()->slug);
    }

    public function test_maintenance_reads_as_out_of_service_for_a_swap_role(): void
    {
        $this->assertSame(FleetVehicleCatalog::ROLE_OUT_OF_SERVICE, FleetVehicleCatalog::roleFor('Maintenance'));
        $this->assertSame(FleetVehicleCatalog::ROLE_OUT_OF_SERVICE, FleetVehicleCatalog::roleFor('Out of Service'));
        $this->assertSame(FleetVehicleCatalog::ROLE_RESERVE, FleetVehicleCatalog::roleFor('Available'));
    }

    public function test_a_number_is_only_rewritten_where_it_stands_alone(): void
    {
        $to = $this->reserveEngine('1033', ['unit_id' => 'R-11033', 'designation' => 'R-1033', 'name' => 'RESCUE 1033 (was 21033)', 'slug' => 'r1033']);
        $from = $this->reserveEngine('17503', ['unit_id' => 'E44', 'designation' => 'E44', 'name' => 'Engine 44', 'slug' => 'e44']);

        app(VehicleSwapService::class)->moveUnitForChangeout($from->fresh(), $to->fresh());

        $old = $from->fresh();
        $this->assertSame(['R-11033', 'R-17503', 'RESCUE 17503 (was 21033)', 'r17503'], [$old->unit_id, $old->designation, $old->name, $old->slug]);
    }

    public function test_a_number_with_special_characters_is_matched_literally(): void
    {
        $to = $this->reserveEngine('V.909', ['unit_id' => 'R-V.909', 'designation' => 'R-VX909', 'name' => 'Reserve V.909', 'slug' => 'r-v909']);
        $from = $this->reserveEngine('$1-2', ['unit_id' => 'E44', 'designation' => 'E44', 'name' => 'Engine 44', 'slug' => 'e44']);

        app(VehicleSwapService::class)->moveUnitForChangeout($from->fresh(), $to->fresh());

        $this->assertSame(['R-$1-2', 'R-VX909', 'Reserve $1-2'], [$from->fresh()->unit_id, $from->fresh()->designation, $from->fresh()->name]);
    }

    public function test_an_older_row_named_reserve_id_follows_its_row(): void
    {
        // Older swaps left trucks named by row id, and some rows may still carry it.
        $this->reserve->forceFill(['unit_id' => 'reserve-'.$this->reserve->id, 'slug' => 'reserve-'.$this->reserve->id, 'designation' => 'Reserve V-909'])->save();

        // E3 moves onto that truck, so the truck it left carries the reserve identity, renamed for the row.
        $this->actingAsCanonicalUser($this->fleetOperationsManager());
        $this->postChangeout($this->frontline, ['to_apparatus_id' => $this->reserve->id])->assertCreated()->assertJsonPath('changeout.status', 'completed');

        $left = $this->frontline->fresh();
        $this->assertSame(['reserve-'.$left->id, 'reserve-'.$left->id], [$left->slug, $left->unit_id]);
        $this->assertSame('Reserve V-303', $left->designation);
        $this->assertSame('E3', $this->reserve->fresh()->designation);
        $this->assertSame(0, Apparatus::query()->where('slug', 'reserve-'.$this->reserve->id)->count());
    }
}
