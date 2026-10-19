<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\AccountStatus;
use App\Enums\ApparatusPmServiceType;
use App\Enums\ApparatusServiceTicketStatus;
use App\Filament\Resources\ApparatusResource\Pages\ListApparatuses;
use App\Filament\Resources\ApparatusServiceTicketResource\Pages\ViewApparatusServiceTicket;
use App\Models\Apparatus;
use App\Models\ApparatusServiceTicket;
use App\Models\Station;
use App\Models\User;
use App\Services\ApparatusServiceTicketWorkflowService;
use App\Services\Security\FleetOperationsManagerAssignment;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Status changes, scheduled service, PM logs and ticket transitions write
 * fleet records, so they need admin.fleet.manage, not just admin.fleet.view.
 */
final class FleetServiceActionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Apparatus $apparatus;

    private ApparatusServiceTicket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        $station = Station::query()->create([
            'station_number' => 2,
            'name' => 'Station 2',
            'address' => '2300 Pine Tree Drive',
            'is_active' => true,
        ]);
        $this->apparatus = Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => 'E2',
            'name' => 'Engine 2',
            'type' => 'Engine',
            'vehicle_number' => '2002',
            'designation' => 'E2',
            'slug' => 'engine-2',
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2021,
            'status' => 'In Service',
            'current_engine_hours' => 300,
            'current_miles' => 21000,
        ]);
        $this->ticket = ApparatusServiceTicket::query()->create([
            'client_submission_id' => fake()->uuid(),
            'apparatus_id' => $this->apparatus->id,
            'station_id' => $station->id,
            'unit_designation_snapshot' => 'E2',
            'requester_name_snapshot' => 'Fixture',
            'origin' => 'fleet',
            'category' => 'repair_mechanical',
            'title' => 'Pump panel gauge sticking',
            'description' => 'Authorization fixture for apparatus service tickets.',
            'priority' => 'routine',
            'status' => ApparatusServiceTicketStatus::Submitted->value,
        ]);
    }

    public function test_view_only_fleet_member_is_refused_by_every_service_write(): void
    {
        $viewer = $this->fleetViewer();
        $workflow = app(ApparatusServiceTicketWorkflowService::class);

        $attempts = [
            'status' => fn () => $workflow->changeOperationalStatus($this->apparatus, $viewer, 'Out of Service'),
            'schedule' => fn () => $workflow->createFleetTicket($this->apparatus, $viewer, $this->scheduleData()),
            'pm' => fn () => $workflow->logPmService($this->apparatus, $viewer, $this->pmData()),
            'transition' => fn () => $workflow->transition($this->ticket, $viewer, ApparatusServiceTicketStatus::Acknowledged, []),
        ];
        foreach ($attempts as $name => $attempt) {
            try {
                $attempt();
                self::fail("A view-only member should not be able to run the {$name} action.");
            } catch (AuthorizationException) {
            }
        }

        self::assertSame('In Service', $this->apparatus->fresh()->status);
        self::assertNull($this->apparatus->fresh()->last_pm_date);
        self::assertSame(1, ApparatusServiceTicket::query()->count());
        self::assertSame('submitted', $this->ticket->fresh()->status);
    }

    public function test_view_only_fleet_member_does_not_get_the_admin_actions(): void
    {
        $viewer = $this->fleetViewer();
        self::assertTrue($viewer->can('viewAny', Apparatus::class), 'The viewer still sees the fleet.');
        $this->actingAs($viewer);

        Livewire::test(ListApparatuses::class)
            ->assertSuccessful()
            ->call('loadTable')
            ->assertTableActionHidden('updateStatus', $this->apparatus)
            ->assertTableActionHidden('scheduleService', $this->apparatus)
            ->assertTableActionHidden('logPmService', $this->apparatus);

        Livewire::test(ViewApparatusServiceTicket::class, ['record' => $this->ticket->getRouteKey()])
            ->assertSuccessful()
            ->assertActionHidden('transition_acknowledged')
            ->assertActionHidden('change_unit_operational_status');
    }

    public function test_fleet_operations_manager_can_run_every_service_action(): void
    {
        $manager = $this->fleetManager();
        $this->actingAs($manager);

        Livewire::test(ListApparatuses::class)
            ->call('loadTable')
            ->assertTableActionVisible('updateStatus', $this->apparatus)
            ->callTableAction('updateStatus', $this->apparatus, data: ['status' => 'Maintenance'])
            ->assertHasNoTableActionErrors()
            ->callTableAction('scheduleService', $this->apparatus, data: $this->scheduleData())
            ->assertHasNoTableActionErrors()
            ->callTableAction('logPmService', $this->apparatus, data: $this->pmData())
            ->assertHasNoTableActionErrors();

        Livewire::test(ViewApparatusServiceTicket::class, ['record' => $this->ticket->getRouteKey()])
            ->assertActionVisible('change_unit_operational_status')
            ->callAction('transition_acknowledged', data: ['public_note' => 'Fleet has it.'])
            ->assertHasNoActionErrors();

        self::assertSame('Maintenance', $this->apparatus->fresh()->status);
        self::assertNotNull($this->apparatus->fresh()->last_pm_date);
        self::assertSame(3, ApparatusServiceTicket::query()->count());
        self::assertSame('acknowledged', $this->ticket->fresh()->status);
    }

    public function test_super_administrator_keeps_service_access(): void
    {
        $super = User::factory()->create(['account_status' => AccountStatus::Active]);
        $super->assignRole(Role::findOrCreate('super_admin', 'web'));
        $workflow = app(ApparatusServiceTicketWorkflowService::class);

        $workflow->changeOperationalStatus($this->apparatus, $super, 'Reserve');
        $workflow->transition($this->ticket, $super, ApparatusServiceTicketStatus::Acknowledged, []);

        self::assertSame('Reserve', $this->apparatus->fresh()->status);
        self::assertSame('acknowledged', $this->ticket->fresh()->status);
    }

    private function fleetViewer(): User
    {
        $viewer = User::factory()->create(['account_status' => AccountStatus::Active]);
        foreach (['admin.access', 'admin.fleet.view'] as $permission) {
            $viewer->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $viewer->fresh();
    }

    private function fleetManager(): User
    {
        $super = User::factory()->create(['account_status' => AccountStatus::Active]);
        $super->assignRole(Role::findOrCreate('super_admin', 'web'));
        $manager = User::factory()->create(['account_status' => AccountStatus::Active]);
        app(FleetOperationsManagerAssignment::class)->apply($super, $manager, true, 'fleet duties');

        return $manager->fresh();
    }

    /** @return array<string, mixed> */
    private function scheduleData(): array
    {
        return [
            'client_submission_id' => fake()->uuid(),
            'category' => 'repair_mechanical',
            'priority' => 'routine',
            'service_type' => 'Pump test',
            'title' => 'Annual pump service test',
            'description' => 'Annual pump performance test at the Fleet shop.',
            'scheduled_for' => now()->addDays(3)->toDateTimeString(),
            'scheduled_location' => 'Fire Fleet',
            'public_note' => 'Pump test scheduled.',
        ];
    }

    /** @return array<string, mixed> */
    private function pmData(): array
    {
        return [
            'client_submission_id' => fake()->uuid(),
            'service_date' => now()->toDateString(),
            'service_type' => ApparatusPmServiceType::values()[0],
        ];
    }
}
