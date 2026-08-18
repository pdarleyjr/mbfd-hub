<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ApparatusServiceTicketStatus;
use App\Filament\Employee\Pages\ApparatusServiceRequestPage;
use App\Filament\Employee\Pages\EmployeeDashboard;
use App\Models\Apparatus;
use App\Models\Employee;
use App\Models\Station;
use App\Models\User;
use App\Services\ApparatusServiceTicketWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ApparatusServiceTicketApiTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    private Apparatus $apparatus;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->station = Station::query()->create([
            'station_number' => 3,
            'name' => 'Station 3',
            'address' => '5303 Collins Avenue',
            'is_active' => true,
        ]);
        $this->apparatus = Apparatus::query()->create([
            'station_id' => $this->station->id,
            'unit_id' => 'L3',
            'name' => 'Ladder 3',
            'type' => 'Ladder',
            'vehicle_number' => '3003',
            'designation' => 'L3',
            'slug' => 'ladder-3',
            'make' => 'Pierce',
            'model' => 'Ascendant',
            'year' => 2021,
            'status' => 'In Service',
            'vin' => 'TOP-SECRET-VIN',
            'notes' => 'TOP SECRET APPARATUS NOTE',
        ]);
        $this->employee = Employee::query()->create([
            'employee_id' => '9033',
            'name' => 'Firefighter Public Test',
            'rank' => 'Firefighter',
            'password' => 'test-password',
        ]);
    }

    public function test_there_is_no_public_ticket_submission_endpoint(): void
    {
        $this->postJson('/api/public/apparatus-service-tickets', [
            'apparatus_id' => $this->apparatus->id,
            'title' => 'Unauthorized public request',
        ])->assertNotFound();

        $this->assertDatabaseCount('apparatus_service_tickets', 0);
    }

    public function test_employee_page_requires_employee_authentication(): void
    {
        $this->get('/employee/apparatus-service-request')->assertRedirect('/employee/login');

        $this->actingAs($this->employee, 'employee');
        $this->get('/employee/apparatus-service-request')->assertOk();
        $this->get('/admin/apparatus-service-tickets')->assertRedirect('/admin/login');
    }

    public function test_authenticated_employee_form_submits_ticket_without_impersonation_fields(): void
    {
        $this->actingAs($this->employee, 'employee');
        Filament::setCurrentPanel(Filament::getPanel('employee'));

        Livewire::withQueryParams(['station_id' => $this->station->id, 'apparatus_id' => $this->apparatus->id])
            ->test(ApparatusServiceRequestPage::class)
            ->fillForm([
                'station_id' => $this->station->id,
                'apparatus_id' => $this->apparatus->id,
                'category' => 'repair_mechanical',
                'priority' => 'attention',
                'title' => 'Air horn pressure slow to recover',
                'description' => 'Pressure recovery was slower than normal during morning checkout.',
                'client_submission_id' => '91c03051-281f-4a19-8896-53b1bbcc8b27',
            ])
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertRedirect(EmployeeDashboard::getUrl(panel: 'employee'));

        $this->assertDatabaseHas('apparatus_service_tickets', [
            'apparatus_id' => $this->apparatus->id,
            'requested_by_employee_id' => $this->employee->id,
            'requester_name_snapshot' => $this->employee->name,
            'origin' => 'station',
        ]);
    }

    public function test_employee_form_rejects_cross_station_apparatus_context(): void
    {
        $otherStation = Station::query()->create([
            'station_number' => 2,
            'name' => 'Station 2',
            'address' => '2300 Pine Tree Drive',
            'is_active' => true,
        ]);
        $this->actingAs($this->employee, 'employee');
        Filament::setCurrentPanel(Filament::getPanel('employee'));

        try {
            Livewire::test(ApparatusServiceRequestPage::class)
                ->fillForm([
                    'station_id' => $otherStation->id,
                    'apparatus_id' => $this->apparatus->id,
                    'category' => 'repair_mechanical',
                    'priority' => 'routine',
                    'title' => 'Invalid cross-station context',
                    'description' => 'This apparatus does not belong to the selected station.',
                    'client_submission_id' => '9f49e020-c9d7-4e6d-863a-8a6f0bb10460',
                ])
                ->call('submit');
            $this->fail('A cross-station apparatus context was accepted.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('apparatus_service_tickets', 0);
        }
    }

    public function test_public_station_and_apparatus_feeds_are_allowlisted_and_paginated(): void
    {
        $service = app(ApparatusServiceTicketWorkflowService::class);
        $ticket = $service->submitFromEmployee($this->employee, $this->apparatus, [
            'client_submission_id' => '826c35fd-c6be-4b3c-953e-2a9a8cbce28f',
            'category' => 'specialty_system',
            'title' => 'Aerial intercom audio low',
            'description' => 'Requester private description must not be public.',
            'priority' => 'attention',
        ])->ticket;
        $admin = $this->makeAdmin('logistics_admin');
        $service->transition($ticket, $admin, ApparatusServiceTicketStatus::Acknowledged, [
            'public_note' => 'Fleet is checking the aerial communications system.',
            'internal_note' => 'Mechanic private note and vendor phone 305-555-1212.',
        ]);

        $stationResponse = $this->getJson("/api/public/stations/{$this->station->id}/service-tickets?scope=open&per_page=1")
            ->assertOk()
            ->assertJsonPath('data.0.ticket_number', $ticket->ticket_number)
            ->assertJsonPath('data.0.unit_designation', 'L3')
            ->assertJsonPath('data.0.current_public_response', 'Fleet is checking the aerial communications system.')
            ->assertJsonPath('meta.per_page', 1);
        $this->assertStringContainsString('no-store', (string) $stationResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $stationResponse->headers->get('Cache-Control'));

        $noticeResponse = $this->getJson("/api/public/apparatuses/{$this->apparatus->id}/service-notices")
            ->assertOk()
            ->assertJsonPath('data.0.ticket_number', $ticket->ticket_number);

        $this->assertSame([
            'id', 'ticket_number', 'apparatus_id', 'station_id', 'unit_designation', 'origin',
            'category', 'service_type', 'title', 'priority', 'status', 'is_open', 'scheduled_for',
            'scheduled_location', 'expected_return_at', 'current_public_response', 'created_at',
            'updated_at', 'updates',
        ], array_keys($stationResponse->json('data.0')));

        foreach ([$stationResponse, $noticeResponse] as $response) {
            $body = $response->getContent();
            $this->assertStringNotContainsString('Firefighter Public Test', $body);
            $this->assertStringNotContainsString('Requester private description', $body);
            $this->assertStringNotContainsString('Mechanic private note', $body);
            $this->assertStringNotContainsString('305-555-1212', $body);
            $this->assertStringNotContainsString('TOP-SECRET-VIN', $body);
            $this->assertStringNotContainsString('TOP SECRET APPARATUS NOTE', $body);
            $this->assertStringNotContainsString('requested_by_employee_id', $body);
            $this->assertStringNotContainsString('internal_note', $body);
            $this->assertStringNotContainsString('metadata', $body);
        }
    }

    public function test_completed_tickets_leave_active_notices_but_remain_in_history(): void
    {
        $service = app(ApparatusServiceTicketWorkflowService::class);
        $ticket = $service->createFleetTicket($this->apparatus, $this->makeAdmin('admin'), [
            'client_submission_id' => 'c8afdf15-b33b-44c8-a8a4-9f3a82818038',
            'category' => 'repair_mechanical',
            'title' => 'Door latch adjustment',
            'description' => 'Fleet-created ticket.',
            'priority' => 'routine',
            'status' => 'in_progress',
        ])->ticket;
        $admin = User::query()->findOrFail($ticket->created_by_user_id);
        $service->transition($ticket, $admin, ApparatusServiceTicketStatus::Completed, [
            'public_note' => 'Latch adjusted and checked.',
        ]);

        $this->getJson("/api/public/apparatuses/{$this->apparatus->id}/service-notices")
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson("/api/public/stations/{$this->station->id}/service-tickets?scope=all")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'completed');
    }

    public function test_station_activity_projects_each_ticket_workflow_event_without_private_notes(): void
    {
        $service = app(ApparatusServiceTicketWorkflowService::class);
        $ticket = $service->submitFromEmployee($this->employee, $this->apparatus, [
            'client_submission_id' => '9d28d7ef-7733-4279-97f5-3170793518ea',
            'category' => 'electrical',
            'title' => 'Cab scene light intermittent',
            'description' => 'The cab scene light flickers when the unit is idling.',
            'priority' => 'attention',
        ])->ticket;
        $admin = $this->makeAdmin('logistics_admin');
        $service->transition($ticket, $admin, ApparatusServiceTicketStatus::Acknowledged, [
            'public_note' => 'Fleet acknowledged the report.',
            'internal_note' => 'PRIVATE CONNECTOR NOTE',
        ]);
        $service->transition($ticket, $admin, ApparatusServiceTicketStatus::InProgress, [
            'public_note' => 'Fleet has started work.',
        ]);

        $response = $this->getJson("/api/public/stations/{$this->station->id}/activity")
            ->assertOk();
        $events = collect($response->json('activity'))->where('request_number', $ticket->ticket_number)->values();

        $this->assertCount(3, $events);
        $this->assertSame(['in_progress', 'acknowledged', 'submitted'], $events->pluck('status')->all());
        $this->assertStringNotContainsString('PRIVATE CONNECTOR NOTE', $response->getContent());
    }

    private function makeAdmin(string $roleName): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate($roleName, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
