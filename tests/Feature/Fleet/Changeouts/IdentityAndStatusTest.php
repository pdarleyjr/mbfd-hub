<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Models\Apparatus;
use App\Services\Fleet\VehicleSwapService;
use App\Support\Fleet\FleetVehicleCatalog;
use Filament\Facades\Filament;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A status describes a truck and a label names one. Neither is handed to the wrong truck, by a changeout or a decision.
 */
final class IdentityAndStatusTest extends ChangeoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useSmallChecklists();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array<string, array{0: string, 1: string, 2: string|null, 3: string, 4: string}> */
    public static function statuses(): array
    {
        return [
            'a healthy unit onto a reserve' => ['In Service', 'Reserve', null, 'In Service', 'Reserve'],
            'an available reserve stays in the pool' => ['In Service', 'Available', null, 'In Service', 'Available'],
            'a unit leaving a failed truck runs in service' => ['Maintenance', 'Reserve', null, 'In Service', 'Reserve'],
            'an out of service unit too' => ['Out of Service', 'Available', null, 'In Service', 'Available'],
            'a failed destination keeps its failure and does not pass it on' => ['In Service', 'Maintenance', null, 'Maintenance', 'Available'],
            'an out of service destination too' => ['In Service', 'Out of Service', null, 'Out of Service', 'Available'],
            'Fleet choosing the old truck status wins' => ['In Service', 'Maintenance', 'Reserve', 'Maintenance', 'Reserve'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_a_failure_status_never_travels(string $unitStatus, string $truckStatus, ?string $chosen, string $newAfter, string $oldAfter): void
    {
        $this->unit->forceFill(['status' => $unitStatus])->save();
        $this->reserve->forceFill(['status' => $truckStatus])->save();

        app(VehicleSwapService::class)->moveUnitForChangeout($this->unit->fresh(), $this->reserve->fresh(), $chosen);

        $this->assertSame($newAfter, $this->reserve->fresh()->getAttribute('status'));
        $this->assertSame($oldAfter, $this->unit->fresh()->getAttribute('status'));
    }

    public function test_an_open_ticket_sends_the_old_truck_to_the_shop_unless_fleet_said_otherwise(): void
    {
        $this->openTicketOn($this->unit);
        $this->moveToReserve(['alert_id' => $this->swapAlert($this->unit)->id])->assertCreated();
        $this->assertSame('Maintenance', $this->unit->fresh()->getAttribute('status'));

        $other = $this->reserveEngine('V-910');
        $this->openTicketOn($this->reserve);
        $this->postChangeout($this->reserve, ['alert_id' => $this->swapAlert($this->reserve, ['old_truck_status_after' => 'Available'])->id, 'to_apparatus_id' => $other->id])->assertCreated();
        $this->assertSame('Available', $this->reserve->fresh()->getAttribute('status'));
    }

    public function test_no_ticket_means_the_old_truck_takes_the_status_the_new_truck_vacated(): void
    {
        $this->moveToReserve(['alert_id' => $this->swapAlert($this->unit)->id])->assertCreated();

        $this->assertSame('Reserve', $this->unit->fresh()->getAttribute('status'));
    }

    public function test_a_truck_with_an_open_ticket_is_not_eligible_and_it_is_one_query_for_the_list(): void
    {
        $ticketed = $this->reserveEngine('V-920', ['status' => 'Available']);
        $this->openTicketOn($ticketed);
        foreach (['V-921', 'V-922', 'V-923'] as $number) {
            $this->reserveEngine($number);
        }
        $alert = $this->swapAlert($this->unit);

        $context = $this->getJson("/api/public/apparatuses/{$this->unit->id}/changeout-context?alert_id={$alert->id}")->assertOk();

        $this->assertNull(collect($context->json('candidates'))->firstWhere('apparatus_id', $ticketed->id));
        $this->assertFalse(collect($context->json('other_vehicles'))->firstWhere('apparatus_id', $ticketed->id)['direct']);
        $this->moveTo($ticketed, $alert->id)->assertCreated()->assertJsonPath('changeout.status', 'pending_fleet');
    }

    public function test_a_closed_ticket_does_not_hold_a_truck(): void
    {
        $this->openTicketOn($this->reserve)->forceFill(['status' => 'completed'])->save();

        $this->moveToReserve(['alert_id' => $this->swapAlert($this->unit)->id])->assertCreated()->assertJsonPath('changeout.status', 'completed');
    }

    public function test_a_return_to_a_truck_with_an_open_ticket_is_fleets_decision(): void
    {
        $this->moveToReserve(['alert_id' => $this->swapAlert($this->unit)->id])->assertCreated();
        $this->openTicketOn($this->unit);

        $context = $this->getJson("/api/public/apparatuses/{$this->reserve->id}/changeout-context")->assertOk();
        $this->assertNull(collect($context->json('candidates'))->firstWhere('apparatus_id', $this->unit->id));
        $this->postChangeout($this->reserve, ['to_apparatus_id' => $this->unit->id])->assertCreated()->assertJsonPath('changeout.status', 'pending_fleet');

        $this->assertSame('E2', $this->reserve->fresh()->designation);
    }

    public function test_maintenance_reads_as_out_of_service_for_a_swap_role(): void
    {
        $this->assertSame(FleetVehicleCatalog::ROLE_OUT_OF_SERVICE, FleetVehicleCatalog::roleFor('Maintenance'));
        $this->assertSame(FleetVehicleCatalog::ROLE_OUT_OF_SERVICE, FleetVehicleCatalog::roleFor('Out of Service'));
        $this->assertSame(FleetVehicleCatalog::ROLE_RESERVE, FleetVehicleCatalog::roleFor('Available'));
    }

    public function test_a_decision_never_trades_a_shop_truck_status_onto_a_good_one(): void
    {
        $this->unit->forceFill(['status' => 'Maintenance'])->save();
        $this->reserve->forceFill(['status' => 'Available'])->save();
        $swap = $this->heldRequest('V-909', withFinding: false);

        app(VehicleSwapService::class)->decide($swap->id, $this->fleetOperationsManager(), VehicleSwapService::DECISION_APPROVE_SWAP);

        $this->assertSame('E2', $this->reserve->fresh()->designation);
        $this->assertSame('Maintenance', $this->unit->fresh()->getAttribute('status'));
        $this->assertSame('Available', $this->reserve->fresh()->getAttribute('status'));
    }

    public function test_a_decision_still_trades_in_service_with_available(): void
    {
        $this->reserve->forceFill(['status' => 'Available'])->save();
        $swap = $this->heldRequest('V-909', withFinding: false);

        app(VehicleSwapService::class)->decide($swap->id, $this->fleetOperationsManager(), VehicleSwapService::DECISION_APPROVE_SWAP);

        $this->assertSame('In Service', $this->reserve->fresh()->getAttribute('status'));
        $this->assertSame('Available', $this->unit->fresh()->getAttribute('status'));
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

    public function test_a_reserve_identity_from_a_decision_follows_its_row_so_a_later_decision_does_not_collide(): void
    {
        // decide() "replace" leaves the old truck as reserve-<its id>.
        $swap = $this->heldRequest('V-909', withFinding: false);
        $manager = $this->fleetOperationsManager();
        app(VehicleSwapService::class)->decide($swap->id, $manager, VehicleSwapService::DECISION_APPROVE_REPLACE, null, VehicleSwapService::DEMOTE_RESERVE);
        $replaced = $this->unit->fresh();
        $this->assertSame('reserve-'.$replaced->id, $replaced->slug);

        // E3 moves onto that truck, so the truck it left carries the reserve identity, renamed for the row.
        $this->actingAsCanonicalUser($manager);
        $this->postChangeout($this->frontline, ['to_apparatus_id' => $replaced->id])->assertCreated()->assertJsonPath('changeout.status', 'completed');
        $left = $this->frontline->fresh();
        $this->assertSame(['reserve-'.$left->id, 'reserve-'.$left->id], [$left->slug, $left->unit_id]);
        $this->assertSame('Reserve V-303', $left->designation);
        $this->assertSame('E3', $this->unit->fresh()->designation);

        // That truck can be demoted by a decision again: reserve-<its id> is free, because nobody kept it.
        $this->actingAsCanonicalUser($this->member);
        $unitNow = $this->unit->fresh();
        $this->unit = $unitNow;
        $this->assertNotNull($unitNow->slug);
        $target = $this->reserveEngine('V-940', ['status' => 'Reserve']);
        $next = $this->heldRequest('V-940', withFinding: false);

        app(VehicleSwapService::class)->decide($next->id, $manager, VehicleSwapService::DECISION_APPROVE_REPLACE, null, VehicleSwapService::DEMOTE_RESERVE);

        $this->assertSame('E3', $target->fresh()->designation);
        $this->assertSame('reserve-'.$unitNow->id, $unitNow->fresh()->slug);
        $this->assertSame(1, Apparatus::query()->where('slug', 'reserve-'.$unitNow->id)->count());
        $this->assertSame('reserve-'.$left->id, $left->fresh()->slug);
    }

    private function moveTo(Apparatus $truck, int $alertId): \Illuminate\Testing\TestResponse
    {
        return $this->postChangeout($this->unit, ['alert_id' => $alertId, 'to_apparatus_id' => $truck->id]);
    }
}
