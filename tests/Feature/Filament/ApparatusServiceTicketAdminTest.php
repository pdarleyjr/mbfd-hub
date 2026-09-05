<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ApparatusResource\Pages\ListApparatuses;
use App\Filament\Resources\ApparatusServiceTicketResource;
use App\Filament\Resources\ApparatusServiceTicketResource\Pages\ListApparatusServiceTickets;
use App\Filament\Resources\ApparatusServiceTicketResource\Pages\ViewApparatusServiceTicket;
use App\Models\Apparatus;
use App\Models\ApparatusServiceTicket;
use App\Models\Station;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
class ApparatusServiceTicketAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Apparatus $apparatus;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate('logistics_admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole($role);
        Gate::before(fn (User $user): ?bool => $user->hasRole('logistics_admin') ? true : null);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        $station = Station::query()->create([
            'station_number' => 4,
            'name' => 'Station 4',
            'address' => '6880 Indian Creek Drive',
            'is_active' => true,
        ]);
        $this->apparatus = Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => 'R4',
            'name' => 'Rescue 4',
            'type' => 'Rescue',
            'vehicle_number' => '4004',
            'designation' => 'R4',
            'slug' => 'rescue-4',
            'make' => 'Freightliner',
            'model' => 'M2',
            'year' => 2020,
            'status' => 'In Service',
            'current_engine_hours' => 200,
            'current_miles' => 14000,
        ]);
    }

    public function test_schedule_service_row_action_creates_canonical_ticket(): void
    {
        Livewire::test(ListApparatuses::class)
            ->assertSuccessful()
            ->call('loadTable')
            ->assertTableActionExists('scheduleService', record: $this->apparatus)
            ->callTableAction('scheduleService', $this->apparatus, data: [
                'client_submission_id' => '8f7336bf-70e2-4e62-ad8f-af05d4d17d56',
                'category' => 'repair_mechanical',
                'priority' => 'routine',
                'service_type' => 'Compartment door adjustment',
                'title' => 'Quarterly compartment door adjustment',
                'description' => 'Inspect and adjust compartment doors during the scheduled Fleet window.',
                'scheduled_for' => now()->addDays(2)->toDateTimeString(),
                'scheduled_location' => 'Fire Fleet',
                'expected_return_at' => now()->addDays(2)->addHours(3)->toDateTimeString(),
                'public_note' => 'Service is scheduled with Fleet.',
            ])
            ->assertHasNoTableActionErrors();

        $ticket = ApparatusServiceTicket::query()->sole();
        $this->assertSame('fleet', $ticket->origin);
        $this->assertSame('scheduled', $ticket->status);
        $this->assertSame($this->apparatus->id, $ticket->apparatus_id);
        $this->assertSame('Compartment door adjustment', $ticket->service_type);
        $this->assertSame('Fire Fleet', $ticket->scheduled_location);
        $this->assertNotNull($ticket->expected_return_at);
        $this->assertCount(1, $ticket->updates);
    }

    public function test_fleet_table_filters_search_and_view_transition_are_operational(): void
    {
        $ticket = $this->ticket('submitted', 'Pump primer inspection', 'urgent');
        $completed = $this->ticket('completed', 'Prior tire service', 'routine');

        Livewire::test(ListApparatusServiceTickets::class)
            ->assertSuccessful()
            ->call('loadTable')
            ->assertTableFilterExists('station_id')
            ->assertTableFilterExists('apparatus_id')
            ->assertTableFilterExists('category')
            ->assertTableFilterExists('priority')
            ->assertTableFilterExists('status')
            ->assertCanSeeTableRecords([$ticket])
            ->searchTable('primer')
            ->assertCanSeeTableRecords([$ticket])
            ->set('activeTab', 'all')
            ->searchTable('tire')
            ->assertCanSeeTableRecords([$completed]);

        Livewire::test(ViewApparatusServiceTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertSuccessful()
            ->assertActionExists('transition_acknowledged')
            ->assertActionExists('change_unit_operational_status')
            ->callAction('transition_acknowledged', data: [
                'public_note' => 'Fleet acknowledged the ticket.',
                'internal_note' => 'Assign pump technician.',
            ])
            ->assertHasNoActionErrors()
            ->callAction('change_unit_operational_status', data: [
                'status' => 'Out of Service',
                'public_note' => 'Unit placed out of service pending pump inspection.',
                'internal_note' => 'Officer contacted.',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('acknowledged', $ticket->refresh()->status);
        $this->assertSame('Out of Service', $this->apparatus->refresh()->status);
        $this->assertCount(3, $ticket->updates);
    }

    public function test_training_only_user_cannot_access_service_ticket_resource(): void
    {
        $trainingRole = Role::findOrCreate('training_admin', 'web');
        $training = User::factory()->create();
        $training->assignRole($trainingRole);
        $this->actingAs($training);

        $this->assertFalse(Gate::forUser($training)->allows('viewAny', ApparatusServiceTicket::class));
        $this->assertFalse(ApparatusServiceTicketResource::canViewAny());
        $this->get(ApparatusServiceTicketResource::getUrl('index'))
            ->assertForbidden();
    }

    private function ticket(string $status, string $title, string $priority): ApparatusServiceTicket
    {
        $ticket = ApparatusServiceTicket::query()->create([
            'client_submission_id' => fake()->uuid(),
            'apparatus_id' => $this->apparatus->id,
            'station_id' => $this->apparatus->station_id,
            'unit_designation_snapshot' => 'R4',
            'created_by_user_id' => $this->admin->id,
            'requester_name_snapshot' => $this->admin->name,
            'origin' => 'fleet',
            'category' => 'repair_mechanical',
            'title' => $title,
            'description' => 'Admin workflow test fixture for apparatus service.',
            'priority' => $priority,
            'status' => $status,
            'completed_at' => $status === 'completed' ? now() : null,
        ]);
        $ticket->updates()->create([
            'status' => $status,
            'public_note' => 'Initial status.',
            'changed_by_user_id' => $this->admin->id,
        ]);

        return $ticket->refresh();
    }
}
